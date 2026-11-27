<?php

declare(strict_types = 1);

namespace Modules\Locales\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Locales\Application\Services\TranslationService;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Domain\Model\Translation;
use Modules\Locales\Infrastructure\Persistence\Repositories\TranslationRepository;
use Modules\Locales\Infrastructure\Services\JsonTranslationLoader;
use Modules\Locales\Interfaces\HTTP\Actions\LocalesAction;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * LocalesExportAuditTest — the panel half of the export, and its audit row.
 *
 * The service test proves `export()` writes the locale's rows. This one proves
 * the ACTION is the caller an operator actually reaches: it redirects the panel
 * with the written count and records one audit entry naming the locale and that
 * number, which is the only durable answer to who moved this file and when.
 *
 * Runs on temp databases and a temp JSON directory. The export writes a file
 * and the audit bridge writes a row, so touching the working tree's storage
 * would make the test change the checkout it runs in.
 *
 * Like every module test this drives the INSTALLED copy and skips when either
 * module is absent. Audit is named fully qualified rather than imported: the
 * catalogue scanner reads a test's `use Modules\Audit` line as a Locales to
 * Audit install edge, and that edge closes the Locales to Audit to Users to
 * Locales cycle. LocalesAction uses the same guarded `class_exists` bridge
 * for the production path.
 */
final class LocalesExportAuditTest extends TestCase
{
    use SqliteDatabase;

    private string $directory = '';

    private \PDO $locales;

    private \PDO $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Locales') || !is_dir(APP_BASE_PATH . '/src/Modules/Audit')) {
            $this->markTestSkipped('Locales and Audit must be installed for the export audit.');
        }

        $this->directory = sys_get_temp_dir() . '/nativa-locales-export-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o775, true);

        $this->locales = new \PDO('sqlite:' . $this->directory . '/locales.db');
        $this->locales->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->locales->exec(<<<'SQL'
            CREATE TABLE translations (
                id VARCHAR(26) PRIMARY KEY,
                translation_key VARCHAR(200) NOT NULL,
                locale VARCHAR(10) NOT NULL,
                value TEXT NOT NULL DEFAULT '',
                group_name VARCHAR(50) DEFAULT 'general',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(translation_key, locale)
            )
            SQL);

        $this->audit = new \PDO('sqlite:' . $this->directory . '/audit.db');
        $this->audit->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        (new \Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator($this->audit))->run(self::quietOutput());

        putenv('DB_AUDIT=' . $this->directory . '/audit.db');
        putenv('DB_USERS=' . $this->directory . '/users.db');
        DatabaseConnectionManager::resetInstance();
        \Modules\Audit\Interfaces\HTTP\AuditServices::reset();
        LocalesServices::reset();

        if (PHP_SESSION_ACTIVE === session_status()) {
            $_SESSION = [];
        }

        $service = new TranslationService(
            new TranslationRepository(new PdoConnection($this->locales, 'locales')),
            new JsonTranslationLoader($this->directory . '/locales'),
        );

        (new \ReflectionProperty(LocalesServices::class, 'translations'))->setValue(null, $service);
    }

    protected function tearDown(): void
    {
        // setUp() skips without installing either module, but PHPUnit still
        // runs tearDown(); the resets below would then load a class that is
        // not there. The empty directory is the mark of that skipped setUp.
        if ('' === $this->directory) {
            $this->restoreDatabaseEnvironment();
            parent::tearDown();

            return;
        }

        LocalesServices::reset();
        \Modules\Audit\Interfaces\HTTP\AuditServices::reset();
        DatabaseConnectionManager::resetInstance();

        foreach (glob($this->directory . '/locales/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory . '/locales');
        @unlink($this->directory . '/locales.db');
        @unlink($this->directory . '/audit.db');
        @unlink($this->directory . '/users.db');
        @rmdir($this->directory);

        $this->restoreDatabaseEnvironment();

        parent::tearDown();
    }

    #[Test]
    public function theExportIsAudited(): void
    {
        $this->seed('nav.home', 'Kotva');
        $this->seed('nav.cta', 'Začať');

        $response = (new LocalesAction(self::router()))->export(Request::create(
            'POST',
            '/mark/locales/export',
            [],
            ['locale' => 'sk_SK'],
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            '/mark/locales?locale=sk_SK&exported=2',
            $response->getHeader('Location'),
            'The panel reports the count the audit entry names.',
        );

        $entries = \Modules\Audit\Interfaces\HTTP\AuditServices::audit()->recent(10);

        self::assertCount(1, $entries, 'An export that leaves no audit entry is an unattributed write.');
        self::assertSame('locales.export', $entries[0]->action());
        self::assertSame('locale:sk_SK', $entries[0]->target());
        self::assertSame('Exported 2 translations', $entries[0]->detail());

        self::assertSame(
            ['nav.cta' => 'Začať', 'nav.home' => 'Kotva'],
            (new JsonTranslationLoader($this->directory . '/locales'))->load('sk_SK'),
            'The audited export is the one that actually wrote this locale\'s file.',
        );
    }

    private function seed(string $key, string $value): void
    {
        $repository = new TranslationRepository(new PdoConnection($this->locales, 'locales'));

        $repository->save(Translation::create($key, Locale::fromString('sk_SK'), $value, 'nav'));
    }

    private static function router(): RouterInterface
    {
        return new class implements RouterInterface {
            public function match(Request $request): ?array
            {
                return null;
            }

            public function url(string $name, array $params = []): string
            {
                return 'mark.locales' === $name
                    ? '/mark/locales'
                    : '/mark/' . $name;
            }
        };
    }

    private static function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
