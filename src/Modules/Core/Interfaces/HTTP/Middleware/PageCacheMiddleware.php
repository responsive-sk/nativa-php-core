<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Application\Services\PageCacheService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ContentServices;

/**
 * PageCacheMiddleware — passive cache population for rate-limit fallback.
 *
 * Captures every cacheable GET response and writes it to the file-based
 * page cache. This middleware NEVER reads from cache — that job belongs
 * to RateLimitMiddleware, which checks the cache when blocking a request.
 *
 * Runs after RateLimitMiddleware in the pipeline so only requests that
 * passed the rate limit are cached. The cached content retains the
 * __CSP_NONCE_PLACEHOLDER__ string — SecurityHeadersMiddleware replaces
 * it with a fresh nonce on every request.
 *
 * The cache key is per locale; without Locales it falls back to the mapper's
 * default (itself the shell provider's answer with a kernel fallback) rather
 * than loading a missing translation store to ask which locale is current.
 */
final readonly class PageCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PageCacheService $cache,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        if ($this->cache->isCacheable($request, $response)) {
            $key = $this->cache->cacheKey($request, ContentServices::mapper()->defaultLocale());
            $this->cache->set($key, $response);
        }

        return $response;
    }
}
