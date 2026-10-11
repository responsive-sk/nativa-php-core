<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Bootstrap;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\Support\DatabaseMigrator;

/**
 * Bootstrapper — first-request application setup.
 *
 * Ensures runtime storage directories exist and auto-runs the (idempotent)
 * module migrations on the FIRST request only: after a successful run a
 * marker file is written to storage/cache/, so subsequent requests skip the
 * glob + reflection + DDL work. When no migrators exist yet (e.g. before any
 * module ships a migrator), no marker is written so the auto-run stays armed
 * for the first real migrator.
 *
 * The marker records "the migrators of THIS module set have run", so the
 * module set changing must drop it: ModuleInstaller::add()/remove() call
 * invalidateMigrations(). Without that, a slice installed after the first
 * request keeps its tables forever uncreated — the first admin write then
 * dies on a missing table (measured on Boot 16: `audit_log`). There is no
 * other lever in this tree: the kernel's bin/cms ships module:* only, not a
 * console with a `migrate` command.
 */
final readonly class Bootstrapper
{
    private const string MARKER = '.migrations-applied';

    private AppPaths $paths;

    private DatabaseMigrator $migrator;

    public function __construct()
    {
        $this->paths = AppPaths::instance();
        $this->migrator = new DatabaseMigrator();
    }

    /**
     * Ensure storage directories exist.
     */
    public function ensureStorage(): void
    {
        foreach (['data', 'logs', 'cache', 'sessions', 'uploads'] as $dir) {
            $this->paths->ensureDir($this->paths->storage($dir));
        }
    }

    /**
     * Whether the first-request migration marker is present.
     */
    public function isMigrated(): bool
    {
        return is_file($this->markerPath());
    }

    /**
     * Auto-run module migrations on the first request.
     *
     * @return list<string> modules migrated ([] when skipped by marker)
     */
    public function migrate(): array
    {
        if ($this->isMigrated()) {
            return [];
        }

        $output = new class () implements CliOutput {
            public function writeln(string $message): void
            {
                // HTTP bootstrap has no console — mirror to the error log.
                error_log('[bootstrapper] ' . strip_tags($message));
            }
        };

        $migrated = $this->migrator->run($output);

        // Write the marker only when at least one migrator actually ran, so
        // the auto-run stays armed for migrators added in later phases.
        if ([] !== $migrated) {
            $this->paths->ensureDir($this->paths->cache());
            file_put_contents($this->markerPath(), implode(PHP_EOL, $migrated) . PHP_EOL);
        }

        return $migrated;
    }

    /**
     * Full boot: storage dirs + first-request auto-migration.
     *
     * @return list<string> modules migrated
     */
    public function boot(): array
    {
        $this->ensureStorage();

        return $this->migrate();
    }

    /**
     * Drop the first-request marker so the next request re-runs every module
     * migrator against the current module set (the DDL is idempotent).
     *
     * @throws \RuntimeException when the marker exists and cannot be removed
     */
    public static function invalidateMigrations(): void
    {
        $path = AppPaths::instance()->cache(self::MARKER);

        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException(sprintf('Cannot remove the migration marker: %s', $path));
        }
    }

    private function markerPath(): string
    {
        return $this->paths->cache(self::MARKER);
    }
}
