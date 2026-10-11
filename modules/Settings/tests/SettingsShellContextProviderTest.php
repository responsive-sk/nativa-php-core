<?php

declare(strict_types = 1);

namespace Modules\Settings\Tests;

use Modules\Core\Domain\ShellNavItem;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Interfaces\HTTP\SettingsServices;
use Modules\Settings\Interfaces\HTTP\SettingsShellContextProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * SettingsShellContextProviderTest (Boot 23) — the menu the shell port asks for
 * and, until this provider existed, nobody answered.
 *
 * `ShellContextProviderRegistry::navigation()` takes the FIRST non-empty list
 * for a slot, and an empty answer IS the port's "no menu" answer. So a port with
 * no menu owner renders exactly like a site with no menu configured: every
 * public page came out with a navbar carrying a brand and nothing else, no
 * error and no log line. That is the failure this file pins — not the styling of
 * a link, the existence of the answer.
 *
 * ## Why it lives here and not under `tests/`
 *
 * Settings is a module. A kernel-only tree has no provider to construct, so the
 * suite SKIPS rather than fails — the contract TaxonomyRegistryTest uses for
 * Blog. `tests/` is inside PHPStan's scope and `modules/` is not, so a test
 * naming an optional module's class cannot break the analyser of a tree without
 * that module.
 *
 * ## The database
 *
 * The provider reads `menu_items` through `SettingsServices`, whose connection
 * is `storage/data/config.db` unless `DB_CONFIG` says otherwise. The test points
 * that at a temp file and builds the schema with the Settings migrator itself —
 * NOT through `DatabaseMigrator`, which would run every module's migrator and
 * therefore touch the real databases of the working tree.
 */
final class SettingsShellContextProviderTest extends TestCase
{
    private const string MODULE = 'Settings';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the provider ships with this module.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-settings-nav-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        SettingsServices::reset();

        // The migrator the kernel would run, against a database of our own —
        // same schema, same seeded menu, no shared state.
        $migrator = new SettingsDatabaseMigrator(new \PDO('sqlite:' . $this->tmpDir . '/config.db'));
        $migrator->run(new NullOutput());
    }

    protected function tearDown(): void
    {
        putenv('DB_CONFIG');
        DatabaseConnectionManager::resetInstance();
        SettingsServices::reset();

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function headerSlotAnswersTheSeededMenuAsKernelOwnedItems(): void
    {
        $items = (new SettingsShellContextProvider())->navigation('header', '/');

        $this->assertSame(
            ['Home', 'Articles', 'Admin'],
            array_map(static fn (ShellNavItem $item): string => $item->label, $items),
            'The header slot must answer the seeded menu — an empty list here is a navbar with nothing to click.',
        );

        // Kernel-owned values, not the module's DTO: the shell cannot carry a
        // module's type (see ShellNavItem's docblock).
        $this->assertInstanceOf(ShellNavItem::class, $items[0]);
        $this->assertSame('/', $items[0]->href);
        $this->assertTrue($items[0]->active, 'On `/` the Home item is the current one.');
        $this->assertFalse($items[1]->active);
    }

    #[Test]
    public function theItemUnderTheCurrentPathIsTheActiveOne(): void
    {
        $byLabel = [];

        foreach ((new SettingsShellContextProvider())->navigation('header', '/blog/nativa-php-core') as $item) {
            $byLabel[$item->label] = $item;
        }

        $this->assertTrue($byLabel['Articles']->active, 'An article is under /blog, so Articles is current.');
        $this->assertFalse($byLabel['Home']->active, 'A prefix match must not light up the root item.');
    }

    #[Test]
    public function footerAnswersItsOwnLocationAndAnUnknownSlotAnswersNothing(): void
    {
        $provider = new SettingsShellContextProvider();

        $this->assertSame(
            ['Privacy', 'Cookies'],
            array_map(static fn (ShellNavItem $item): string => $item->label, $provider->navigation('footer', '/')),
        );

        $this->assertSame([], $provider->navigation('sidebar', '/'), 'A slot with no rows is the port\'s no-menu answer.');
    }

    #[Test]
    public function theProviderAnswersTheMenuAndAbstainsFromEverythingElse(): void
    {
        $provider = new SettingsShellContextProvider();

        $this->assertNull($provider->siteName(), 'A theme fixture answers the name; a second answer is a second source.');
        $this->assertNull($provider->theme());
        $this->assertNull($provider->defaultLocale());
        $this->assertNull($provider->htmlLang());
        $this->assertSame([], $provider->languages());
    }
}
