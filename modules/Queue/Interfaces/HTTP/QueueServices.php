<?php

declare(strict_types = 1);

namespace Modules\Queue\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Queue\Application\Services\DatabaseQueue;
use Modules\Queue\Application\Services\HandlerRegistry;

/**
 * QueueServices — lazy cached service factories for the Queue module.
 *
 * Actions never `new` the queue: the handler registry is shared process-
 * wide (handlers register once, the worker and the admin see the same
 * map), and `reset()` keeps tests isolated.
 */
final class QueueServices
{
    private static ?DatabaseQueue $queue = null;

    private static ?HandlerRegistry $handlers = null;

    public static function queue(): DatabaseQueue
    {
        return self::$queue ??= new DatabaseQueue(
            DatabaseConnectionManager::instance()->connection('queue'),
            self::handlers(),
        );
    }

    public static function handlers(): HandlerRegistry
    {
        return self::$handlers ??= new HandlerRegistry();
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$queue = null;
        self::$handlers = null;
    }
}
