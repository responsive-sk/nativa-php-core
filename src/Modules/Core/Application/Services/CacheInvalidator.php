<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

/**
 * CacheInvalidator — flushes the file-based page cache.
 *
 * Contract: after ANY admin write action completes, the page cache must not
 * serve content that predates the save. Every action that appends to the
 * immutable audit log calls CacheInvalidator::flush() beside it; bulk actions
 * flush once after their loop. The rate-limit 429 fallback therefore never
 * serves pre-edit content for longer than the request that wrote it.
 *
 * Flush-all (glob + unlink) instead of selective invalidation: cache keys
 * are SHA256 hashes with no path metadata, a reverse index would add state
 * for a problem the 5-minute TTL already bounds. The cache repopulates
 * passively on the next requests.
 *
 * Lives in Core (shared kernel) so every module can call it without
 * cross-module imports; static + stateless so #[Route] action classes
 * (instantiated by the router with no arguments) need no wiring changes.
 */
final class CacheInvalidator
{
    /**
     * Delete every cached page entry. Returns the number of files deleted.
     */
    public static function flush(): int
    {
        return (new PageCacheService(
            APP_BASE_PATH . '/' . PageCacheService::DEFAULT_DIR,
        ))->flush();
    }
}
