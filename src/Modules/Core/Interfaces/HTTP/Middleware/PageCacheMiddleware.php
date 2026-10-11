<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Application\Services\PageCacheService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

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
            $key = $this->cache->cacheKey($request, LocalesServices::locale()->current()->code());
            $this->cache->set($key, $response);
        }

        return $response;
    }
}
