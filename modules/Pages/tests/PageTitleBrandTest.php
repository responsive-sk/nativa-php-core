<?php

declare(strict_types = 1);

namespace Modules\Pages\Tests;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Pages\Interfaces\HTTP\Actions\PageViewAction;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;
use Modules\Settings\Interfaces\HTTP\SettingsServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shell\ShellContextProviderRegistry;
use Tests\Support\SqliteDatabase;
use Themes\FrontendLayout;

/**
 * PageTitleBrandTest — the page title names the site, not the kernel.
 *
 * Measured 2026-11-19 on the responsive.sk artifact: `site_name` was
 * `responsive.sk` and every article ended with it, because Blog asks the shell
 * port for the name. Every Page ended with the literal `Nativa`, because
 * `PageViewAction` spelled the suffix itself. `<title>` is the field a search
 * result shows first, and the wrong brand in it is invisible from inside the
 * panel — nothing there renders it.
 *
 * The two branches are the two states of the port: a provider that names the
 * site (Settings) and a tree where nothing answers. The second is not
 * hypothetical — a kernel-only checkout has no provider at all, which is what
 * the `Nativa PHP Core` fallback exists for.
 *
 * ## Why it lives here and not under `tests/`
 *
 * The subject is this module's title, and it needs the Settings database, whose
 * classes `tests/` may not name (PHPStan analyses `tests/` and not `modules/`).
 * A tree without Settings skips: with no way to configure a name only the
 * fallback branch exists.
 */
final class PageTitleBrandTest extends TestCase
{
    use SqliteDatabase;

    private string $tmpDir = '';

    private string $slug = '';

    private string $locale = '';

    private \PDO $pdo;

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!class_exists(PageViewAction::class)) {
            $this->markTestSkipped('Pages is not installed — the title ships with this module.');
        }

        if (!class_exists(SettingsServices::class)) {
            $this->markTestSkipped('Settings is not installed — no provider can name the site.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-page-title-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        SettingsServices::reset();

        $this->pdo = new \PDO('sqlite:' . $this->tmpDir . '/config.db');
        (new SettingsDatabaseMigrator($this->pdo))->run($this->quietOutput());
        $this->settings = new SettingsRepository(DatabaseConnectionManager::instance()->connect('config'));

        $this->locale = ContentLocaleServices::mapper()->defaultLocale();
        $this->slug = 'title-brand-' . bin2hex(random_bytes(4));

        (new PageCrudService())->save($this->slug, [
            'title' => 'About us',
            'published' => true,
        ], 'Body of the page.', $this->locale);
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/Pages')) {
            if ('' !== $this->slug) {
                (new PageCrudService())->delete($this->slug, $this->locale);
            }

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
    public function thePageTitleEndsWithTheConfiguredSiteName(): void
    {
        $this->storeSiteName('responsive.sk');

        self::assertSame(
            'About us — responsive.sk',
            $this->renderedTitle(),
            'The title must end with the stored site name — a literal brand here is a wrong name in every search result.',
        );
    }

    #[Test]
    public function thePageTitleFallsBackToTheKernelNameWhenNoSiteNameIsConfigured(): void
    {
        $this->settings->delete('site_name');
        SettingsServices::reset();

        self::assertSame(
            'About us — ' . FrontendLayout::DEFAULT_SITE_NAME,
            $this->renderedTitle(),
            'With no provider answering, the kernel default is the last line — not an empty suffix or a dangling dash.',
        );
    }

    /**
     * Render the page through the real action and read back what landed in
     * `<title>` — the string a crawler sees, not the one the action intended.
     */
    private function renderedTitle(): string
    {
        ShellContextProviderRegistry::reset();

        $request = Request::create('GET', '/' . $this->slug);
        $request->setRouteParams(['slug' => $this->slug]);

        $response = (new PageViewAction())->handle($request);

        self::assertSame(200, $response->getStatusCode(), 'The fixture page must render, not 404.');

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
