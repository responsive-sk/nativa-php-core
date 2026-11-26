<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Bootstrap;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\Support\DatabaseMigrator;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\Driver;

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
 * The marker is a claim about DATABASES, not about a file, so it is checked
 * as one: `isMigrated()` reads the module names it lists and, on SQLite,
 * requires each module's database file to be there. A marker that arrived
 * without its databases (an artifact copied in, storage/data replaced) is
 * ignored and the idempotent migration runs again. Measured: a fresh install
 * of an artifact that carried the marker and no storage/data answered 500 on
 * `GET /` with `PDOException: no such table: settings`, while the same tree
 * without the marker answered 200 and wrote it back.
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
    /**
     * The marker file's name, and its only spelling.
     *
     * Public because the marker is a fact a test asserts: a marker whose
     * databases are gone must be ignored, and
     * `tests/Modules/BootstrapperMarkerTest` reads the name from here instead
     * of writing a second copy of it.
     */
    public const string MARKER = '.migrations-applied';

    private AppPaths $paths;

    private DatabaseMigrator $migrator;

    public function __construct()
    {
        $this->paths = AppPaths::instance();
        $this->migrator = new DatabaseMigrator();
    }

    /**
     * Ensure storage directories exist AND are writable by this process.
     *
     * The writability half is not decoration. Measured on a deployed artifact:
     * `build-dist.sh` chmods every directory to 755, and a deploy that runs as
     * one user (rsync as root, an FTP client, a CI account) while php-fpm/www-data
     * serves as another leaves storage/ unwritable for the SAPI. Every symptom
     * downstream is a different, misleading one:
     *
     *   - data/ unwritable  → `PDOException: [HY000] [14] unable to open
     *     database file` on the first query. A 500 that reads like a broken
     *     artifact rather than a permissions problem.
     *   - cache/ unwritable → the DDL runs fine, then this class's own marker
     *     write fails. The warning became an ErrorException, so the request
     *     500'd with `file_put_contents(...): Failed to open stream`. Worse,
     *     the marker never landed, so the ENTIRE schema migration re-ran on
     *     every request: three requests, three full migrations across ten
     *     SQLite databases. Per-request DDL is what turns a 500 into a
     *     gateway timeout (502/503) under php-fpm's request timeout.
     *
     * Checking once, here, and naming the path converts both into one
     * actionable failure instead of an obscure one plus a timeout storm.
     *
     * @throws \RuntimeException when a storage directory is not writable
     */
    public function ensureStorage(): void
    {
        foreach (['data', 'logs', 'cache', 'sessions', 'uploads'] as $dir) {
            $path = $this->paths->storage($dir);
            $this->paths->ensureDir($path);

            if (!is_writable($path)) {
                throw new \RuntimeException(sprintf(
                    'storage/%s is not writable by the web server (%s). '
                    . 'The application cannot create its databases, its logs, '
                    . 'its sessions or its caches. Fix the ownership of the '
                    . 'deployed tree, e.g. `chgrp -R %s storage && chmod -R 775 storage`, '
                    . 'where %s is the group php-fpm/www-data runs as.',
                    $dir,
                    $path,
                    'www-data',
                    'www-data',
                ));
            }
        }
    }

    /**
     * Whether the first-request migration marker is present AND still true.
     *
     * The file alone is not the answer. It records "these modules were
     * migrated", which is a claim about databases, and this process can check
     * it: on SQLite every named module has a database file, and a missing one
     * means the schema was never created here. Trusting the file alone turns a
     * marker copied in with an artifact (or left behind while storage/data was
     * replaced) into a permanent 500 on the first query.
     */
    public function isMigrated(): bool
    {
        if (!is_file($this->markerPath())) {
            return false;
        }

        $modules = $this->markerModules();

        if ([] === $modules) {
            return false;
        }

        // Only SQLite stores its databases as files this process can see, and
        // only SQLite state travels inside a deployed tree. A server-backed
        // database outlives the tree, so the marker stands there and is not
        // second-guessed with a connection on every request.
        if (Driver::Sqlite !== Driver::fromName(DatabaseConnectionManager::instance()->driverName())) {
            return true;
        }

        foreach ($modules as $module) {
            if (!is_file($this->paths->database($module))) {
                return false;
            }
        }

        return true;
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

            // `@` plus an explicit check, not a bare file_put_contents(). The
            // global handler in init.php turns a PHP warning into an
            // ErrorException, so a failed write used to surface as
            // `file_put_contents(...): Failed to open stream` — a message
            // about an internal function, on a line that is not the cause.
            // ensureStorage() has already refused an unwritable cache/, so
            // reaching the failure means something changed underneath us; say
            // so plainly instead of re-throwing the warning's own wording.
            if (false === @file_put_contents($this->markerPath(), implode(PHP_EOL, $migrated) . PHP_EOL)) {
                throw new \RuntimeException(sprintf(
                    'Cannot write the migration marker %s — every request would '
                    . 're-run the full schema migration. Check that storage/cache '
                    . 'is writable by the web server.',
                    $this->markerPath(),
                ));
            }
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

    /**
     * The module names the marker lists, or [] when it names nothing usable.
     *
     * Module names are lower-case identifiers. A line that is not one was not
     * written by this class, so the whole marker is untrusted: the migration
     * it would suppress runs again, which is idempotent, and the marker is
     * rewritten with the truth.
     *
     * @return list<string>
     */
    private function markerModules(): array
    {
        $content = @file_get_contents($this->markerPath());

        if (false === $content) {
            return [];
        }

        $modules = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $module = trim($line);

            if ('' === $module) {
                continue;
            }

            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $module)) {
                return [];
            }

            $modules[] = $module;
        }

        return $modules;
    }

    private function markerPath(): string
    {
        return $this->paths->cache(self::MARKER);
    }
}
