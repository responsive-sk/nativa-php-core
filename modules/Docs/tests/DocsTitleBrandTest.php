<?php

declare(strict_types = 1);

namespace Modules\Docs\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Docs\Application\Services\DocsCatalog;
use Modules\Docs\Interfaces\HTTP\Actions\DocsIndexAction;
use Modules\Docs\Interfaces\HTTP\Actions\DocsPageAction;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;
use Modules\Settings\Interfaces\HTTP\SettingsServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Tests\Support\SqliteDatabase;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * DocsTitleBrandTest — a guide's title names the site, not the kernel.
 *
 * Both Docs surfaces spelled the suffix themselves ('Documentation — Nativa',
 * '$title — Nativa'), so on the responsive.sk artifact the two pages a
 * developer opens first carried the kernel's brand while the articles beside
 * them carried the site's. They now ask `FrontendLayout::brandSuffix()`, the
 * same answer Blog has always used.
 *
 * The fixture is the repository's own `docs/` through a `DocsCatalog` pointed at
 * it, because the documented keys are a fixed list and a fake file could pass
 * while the real catalogue stopped publishing.
 */
final class DocsTitleBrandTest extends TestCase
{
    use SqliteDatabase;

    private string $tmpDir = '';

    private SettingsRepository $settings;

    private DocsCatalog $catalog;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!class_exists(DocsIndexAction::class)) {
            $this->markTestSkipped('Docs is not installed — the titles ship with this module.');
        }

        if (!class_exists(SettingsServices::class)) {
            $this->markTestSkipped('Settings is not installed — no provider can name the site.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-docs-title-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        SettingsServices::reset();

        $migrator = new SettingsDatabaseMigrator(new \PDO('sqlite:' . $this->tmpDir . '/config.db'));
        $migrator->run($this->quietOutput());

        $this->settings = new SettingsRepository(DatabaseConnectionManager::instance()->connect('config'));
        $this->catalog = new DocsCatalog();

        if ([] === $this->catalog->entries()) {
            $this->markTestSkipped('This tree ships no docs/ guides — there is no title to render.');
        }
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/Docs')) {
            ShellContextProviderRegistry::reset();

            putenv('DB_CONFIG');
            DatabaseConnectionManager::resetInstance();
            SettingsServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        $this->restoreDatabaseEnvironment();

        parent::tearDown();
    }

    #[Test]
    public function theIndexTitleEndsWithTheConfiguredSiteName(): void
    {
        $this->storeSiteName('responsive.sk');

        self::assertSame(
            'Documentation — responsive.sk',
            $this->titleOf(new DocsIndexAction($this->catalog), Request::create('GET', '/docs')),
        );
    }

    #[Test]
    public function theIndexTitleFallsBackToTheKernelNameWhenNoSiteNameIsConfigured(): void
    {
        $this->settings->delete('site_name');
        SettingsServices::reset();

        self::assertSame(
            'Documentation — ' . FrontendLayout::DEFAULT_SITE_NAME,
            $this->titleOf(new DocsIndexAction($this->catalog), Request::create('GET', '/docs')),
        );
    }

    #[Test]
    public function aGuideTitleEndsWithTheConfiguredSiteName(): void
    {
        $this->storeSiteName('responsive.sk');

        $entry = $this->catalog->entries()[0];
        $request = Request::create('GET', '/docs/' . $entry['key']);
        $request->setRouteParams(['path' => $entry['key']]);

        self::assertSame(
            $entry['title'] . ' — responsive.sk',
            $this->titleOf(new DocsPageAction($this->catalog), $request),
        );
    }

    /**
     * Dispatch the real action and read the `<title>` out of the response.
     *
     * @param object $action a Docs action; the two differ only in their route
     */
    private function titleOf(object $action, Request $request): string
    {
        ShellContextProviderRegistry::reset();

        /** @var \Modules\Core\Infrastructure\Http\Response $response */
        $response = $action->handle($request);

        self::assertSame(200, $response->getStatusCode(), 'The surface must render, not 404.');

        $matched = preg_match('/<title>(.*?)<\/title>/s', $response->getContent(), $title);

        self::assertSame(1, $matched, 'The page carries no <title> at all.');

        return $title[1];
    }

    private function storeSiteName(string $value): void
    {
        $this->settings->set('site_name', $value, 'general', false);
        SettingsServices::reset();
    }

    private function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
