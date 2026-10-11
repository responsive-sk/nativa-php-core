<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Application\Services\PageCacheService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Outbox\Domain\SecurityEvents;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Modules\Security\Application\Services\RateLimiter;

/**
 * RateLimitMiddleware — general per-IP request throttling.
 *
 * Applies a 60 requests/minute limit per client IP, returning 429 when
 * exceeded. Loopback addresses (127.0.0.1, ::1, localhost) are exempt so
 * local development and testing are not throttled. The RateLimiter (and its
 * database connection) is constructed lazily only for non-loopback traffic.
 * Login-specific limits (5/min per IP + per-email) are enforced by the login
 * action via RateLimiter.
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
     * @param \Closure(): RateLimiter $rateLimiterFactory
     * @param PageCacheService|null   $cache              Optional page cache for rate-limit fallback
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
        $key = 'rate_limit:' . $request->getClientIp();

        if (!$rateLimiter->isAllowed($key, self::GENERAL_LIMIT, self::GENERAL_WINDOW)) {
            return $this->blockedResponse($rateLimiter, $key, $request);
        }

        return $next($request);
    }

    /**
     * Record the trip as a durable security event.
     *
     * A 429 is a caller being refused, which is the same category of fact as an
     * authorization denial: something was refused, and reconstructing that
     * afterwards is the whole point. The IP rides along because the audit row
     * has a dedicated column for it and an investigation starts there.
     */
    private function recordTrip(string $key, Request $request): void
    {
        OutboxServices::securityEvent(
            SecurityEvents::RATELIMIT_TRIPPED,
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
            $cacheKey = $this->cache->cacheKey($request, LocalesServices::locale()->current()->code());
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
