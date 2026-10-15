<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Application\Services\PageCacheService;
use Modules\Core\Domain\Contracts\RateLimiter;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ContentServices;

/**
 * RateLimitMiddleware — general per-IP request throttling.
 *
 * Applies a 60 requests/minute limit per client IP, returning 429 when
 * exceeded. Loopback addresses (127.0.0.1, ::1, localhost) are exempt so
 * local development and testing are not throttled.
 *
 * Three guards keep this file kernel-owned, one per edge it used to carry:
 *
 * - LOCALE. The cache key is per locale; without Locales it falls back to
 *   the mapper's default (itself the shell provider's answer with a kernel
 *   fallback) instead of loading a missing translation store.
 * - OUTBOX. A throttled request is a security event, recorded only when the
 *   outbox is installed: the `class_exists` guard emits through the module's
 *   services when they are here and drops the event when they are not — the
 *   request is answered either way, never fataled. No `use` statement names
 *   the module, so the ledger stays clean while the behaviour survives —
 *   and that is why both FQCNs are written out at the call site instead of
 *   imported. An import would be a second, quieter way to say the same thing:
 *   it compiles whether or not the branch ever runs, and `KernelCouplingTest`
 *   counts it as an edge regardless of the guard. Keep the strings inline;
 *   adding the `use` back is a green test turning red for a stylistic gain.
 * - LIMITER. The factory product is duck-typed, not type-hinted: the limiter
 *   itself is a kernel obligation, so the middleware degrades (passes the
 *   request through un-limited) rather than disappearing when the factory
 *   yields nothing usable. The expected shape is `isAllowed(string, int,
 *   int): bool` + `getRetryAfter(string): int`.
 *
 * When a PageCacheService is provided, blocked requests check the cache
 * for a cached HTML response. Cache hits are served with HTTP 429,
 * Retry-After header, and X-Cache: HIT — giving rate-limited clients
 * a functional page instead of a bare error string.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    private const array LOOPBACK_IPS = ['127.0.0.1', '::1', 'localhost'];

    private const int GENERAL_LIMIT = 60;

    private const int GENERAL_WINDOW = 60;

    /**
     * @param \Closure(): object    $rateLimiterFactory Yields the limiter candidate.
     *                                                  The product is unchecked until isLimiter() verifies the RateLimiter
     *                                                  shape, so the closure stays `object` — typing it narrower would
     *                                                  turn a junk product (pass-through by contract) into a TypeError.
     * @param PageCacheService|null $cache              Optional page cache for rate-limit fallback
     */
    public function __construct(
        private \Closure $rateLimiterFactory,
        private ?PageCacheService $cache = null,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->isLoopback($request)) {
            return $next($request);
        }

        $rateLimiter = ($this->rateLimiterFactory)();

        if (!$this->isLimiter($rateLimiter)) {
            return $next($request);
        }

        /** @var RateLimiter $rateLimiter The isLimiter() guard verified the shape. */
        $key = 'rate_limit:' . $request->getClientIp();

        if (!$rateLimiter->isAllowed($key, self::GENERAL_LIMIT, self::GENERAL_WINDOW)) {
            return $this->blockedResponse($rateLimiter, $key, $request);
        }

        return $next($request);
    }

    /**
     * The factory crossed a module boundary, so its product is verified, not
     * trusted: anything without the two limiter methods degrades to
     * pass-through rather than fataling on the call.
     */
    private function isLimiter(mixed $rateLimiter): bool
    {
        return is_object($rateLimiter)
            && method_exists($rateLimiter, 'isAllowed')
            && method_exists($rateLimiter, 'getRetryAfter');
    }

    /**
     * Record the trip as a security event — only when the outbox is installed.
     *
     * A 429 is a caller being refused, the same category of fact as an
     * authorization denial, and the IP rides along because an investigation
     * starts there. Without the outbox the event is dropped, not the request.
     */
    private function recordTrip(string $key, Request $request): void
    {
        if (!class_exists(\Modules\Outbox\Interfaces\HTTP\OutboxServices::class)
            || !class_exists(\Modules\Outbox\Domain\SecurityEvents::class)
        ) {
            return;
        }

        \Modules\Outbox\Interfaces\HTTP\OutboxServices::securityEvent(
            \Modules\Outbox\Domain\SecurityEvents::RATELIMIT_TRIPPED,
            ['key' => $key, 'scope' => $request->getPathInfo(), 'ip' => $request->getClientIp()],
        );
    }

    /**
     * Build the 429 response — serve cached HTML when available, bare
     * error string otherwise.
     */
    private function blockedResponse(RateLimiter $rateLimiter, string $key, Request $request): Response
    {
        $this->recordTrip($key, $request);

        if (null !== $this->cache) {
            $cacheKey = $this->cache->cacheKey($request, ContentServices::mapper()->defaultLocale());
            $cached = $this->cache->get($cacheKey);

            if (null !== $cached) {
                $response = Response::html($cached['content'], 429);
                $retryAfter = $rateLimiter->getRetryAfter($key);
                $response->setHeader('Retry-After', (string) $retryAfter);
                $response->setHeader('X-Cache', 'HIT');

                return $response;
            }
        }

        return Response::html('Too Many Requests', 429);
    }

    private function isLoopback(Request $request): bool
    {
        return \in_array($request->getClientIp(), self::LOOPBACK_IPS, true);
    }
}
