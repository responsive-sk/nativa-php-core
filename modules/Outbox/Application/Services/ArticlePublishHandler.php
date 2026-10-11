<?php

declare(strict_types = 1);

namespace Modules\Outbox\Application\Services;

use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;

/**
 * ArticlePublishHandler — the shipped outbox handler for article state changes.
 *
 * Publishing an article changes three things at once: the markdown file's
 * front matter, the page cache, and the feeds and sitemap that read from
 * them. The handler owns the cache part of that, which is what makes the
 * outbox load-bearing rather than decorative — a deployment that drains the
 * queue from cron flushes caches for publishes that happened while it was
 * down.
 *
 * The flush is idempotent (`CacheInvalidator::flush()` clears a directory),
 * so at-most-once effect is acceptable. That is the property the module's
 * non-transactional guarantee depends on: at-least-once delivery plus an
 * idempotent handler equals "the cache is eventually right", which is all a
 * 5-minute-TTL cache can ask for.
 */
final class ArticlePublishHandler
{
    /**
     * Bind both article event types on the processor.
     */
    public static function register(OutboxProcessor $processor): void
    {
        foreach ([OutboxServices::ARTICLE_PUBLISHED, OutboxServices::ARTICLE_UNPUBLISHED] as $type) {
            $processor->registerHandler($type, static function (): void {
                CacheInvalidator::flush();
            });
        }
    }
}
