<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

use Modules\Core\Application\Events\EventDispatcher;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * ServiceContainer — lightweight service wiring registry.
 *
 * Not a dependency-injection container: modules construct their own
 * dependencies and register the shared singletons here. Core services
 * (paths, database connections, event dispatcher) are wired by
 * registerCoreServices().
 */
final class ServiceContainer
{
    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct()
    {
    }

    /**
     * Get the shared instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset the singleton (test isolation).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Register a shared service under an id (class name or alias).
     */
    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    /**
     * Resolve a registered service.
     *
     * @throws \RuntimeException when the service is not registered
     */
    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new \RuntimeException(sprintf('Service not registered: %s', $id));
        }

        return $this->services[$id];
    }

    /**
     * Whether a service is registered under the id.
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /**
     * Wire the shared core services (paths, connections, events).
     */
    public function registerCoreServices(): void
    {
        $paths = AppPaths::instance();
        $this->set(AppPaths::class, $paths);
        $this->set(DatabaseConnectionManager::class, DatabaseConnectionManager::instance());
        $this->set(EventDispatcher::class, new EventDispatcher());
    }
}
