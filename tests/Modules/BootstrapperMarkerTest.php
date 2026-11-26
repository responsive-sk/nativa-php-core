<?php

declare(strict_types = 1);

namespace Tests\Modules;

use Modules\Core\Infrastructure\Bootstrap\Bootstrapper;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\Support\DatabaseMigrator;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\Driver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BootstrapperMarkerTest — the marker is a claim about DATABASES.
 *
 * A deployed artifact carried storage/cache/.migrations-applied and no
 * storage/data: the first request trusted the file, skipped the run that
 * creates the schema, and answered 500 with `no such table: settings`. The
 * cases below pin both directions: a marker whose databases are gone is
 * ignored and the migration runs, and one whose databases are there still
 * saves the work.
 *
 * The marker lives in the real tree's storage/cache/, so setUp() saves the
 * existing content and tearDown() puts it back — the same discipline the
 * skip-guarded module tests use, for the same reason: a test that leaves
 * runtime state behind changes what the next test measures.
 */
final class BootstrapperMarkerTest extends TestCase
{
    private string $dir = '';

    private string|false $previousDriver = false;

    /** @var array<string, string|false> */
    private array $previousDb = [];

    private ?string $savedMarker = null;

    protected function setUp(): void
    {
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();

        $path = self::markerPath();
        $this->savedMarker = is_file($path) ? (string) file_get_contents($path) : null;

        $this->dir = sys_get_temp_dir() . '/marker-' . bin2hex(random_bytes(6));

        if (!mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create ' . $this->dir);
        }

        $this->previousDriver = getenv('DB_DRIVER');
        putenv('DB_DRIVER=' . Driver::Sqlite->value);
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even after a skipped setUp(), and this class
        // writes into the real storage/cache — so every step is guarded and
        // the saved marker is restored before the singletons are reset.
        foreach ($this->previousDb as $key => $value) {
            if (false === $value) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }

        if (false === $this->previousDriver) {
            putenv('DB_DRIVER');
        } else {
            putenv('DB_DRIVER=' . $this->previousDriver);
        }

        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($this->dir);
        }

        if (null === $this->savedMarker) {
            if (is_file(self::markerPath())) {
                unlink(self::markerPath());
            }
        } else {
            file_put_contents(self::markerPath(), $this->savedMarker);
        }

        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();
    }

    #[Test]
    public function aMarkerWithoutItsDatabasesIsIgnored(): void
    {
        $this->setDatabase('config', $this->dir . '/missing/config.db');
        $this->writeMarker(['config']);

        self::assertFalse(
            (new Bootstrapper())->isMigrated(),
            'a marker naming a database that is not on disk must not suppress the migration',
        );
    }

    #[Test]
    public function aMarkerWithItsDatabasesIsTrusted(): void
    {
        $database = $this->dir . '/config.db';
        touch($database);
        $this->setDatabase('config', $database);
        $this->writeMarker(['config']);

        self::assertTrue((new Bootstrapper())->isMigrated());
    }

    #[Test]
    public function anEmptyMarkerIsIgnored(): void
    {
        $this->writeMarker([]);

        self::assertFalse((new Bootstrapper())->isMigrated());
    }

    #[Test]
    public function aNameThisTreeNeverWritesMakesTheMarkerUntrusted(): void
    {
        $path = self::markerPath();
        file_put_contents($path, "../../etc/passwd\n");

        self::assertFalse((new Bootstrapper())->isMigrated());
    }

    #[Test]
    public function theFirstRequestMigratesWhenTheDatabasesAreMissing(): void
    {
        $modules = (new DatabaseMigrator())->databases();

        if ([] === $modules) {
            self::markTestSkipped('No module in this tree ships a migrator.');
        }

        foreach ($modules as $module) {
            $this->setDatabase($module, $this->dir . '/' . $module . '.db');
        }

        $this->writeMarker($modules);

        $migrated = (new Bootstrapper())->migrate();

        self::assertSame(
            $modules,
            $migrated,
            'the migration must run when the databases it claims are gone',
        );

        foreach ($modules as $module) {
            self::assertFileExists($this->dir . '/' . $module . '.db', $module . ' was not migrated');
        }

        self::assertSame(
            $modules,
            $this->markerModules(),
            'the marker must be rewritten with the modules that actually ran',
        );
    }

    /**
     * Redirect one module's database to a temp file, remembering the previous
     * value so tearDown() can restore the process environment exactly.
     */
    private function setDatabase(string $module, string $path): void
    {
        $key = 'DB_' . strtoupper($module);

        if (!array_key_exists($key, $this->previousDb)) {
            $this->previousDb[$key] = getenv($key);
        }

        putenv($key . '=' . $path);
        DatabaseConnectionManager::resetInstance();
    }

    /**
     * @param list<string> $modules
     */
    private function writeMarker(array $modules): void
    {
        $path = self::markerPath();
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }

        file_put_contents($path, [] === $modules ? '' : implode(PHP_EOL, $modules) . PHP_EOL);
    }

    /**
     * @return list<string>
     */
    private function markerModules(): array
    {
        $content = (string) file_get_contents(self::markerPath());
        $modules = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $module = trim($line);

            if ('' !== $module) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    private static function markerPath(): string
    {
        return AppPaths::instance()->cache(Bootstrapper::MARKER);
    }
}
