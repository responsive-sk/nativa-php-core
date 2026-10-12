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
 * It also pins what a VISITOR may see: the seed puts `Admin` (→ `/mark`) in the
 * header, and an anonymous reader must not be pointed at the admin surface
 * (202 + login is what a dead end looks like in a navbar). Signed-in editors
 * keep it, nested admin items included.
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

    private \PDO $pdo;

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

        $this->pdo = new \PDO('sqlite:' . $this->tmpDir . '/config.db');

        // The migrator the kernel would run, against a database of our own —
        // same schema, same seeded menu, no shared state.
        (new SettingsDatabaseMigrator($this->pdo))->run(new NullOutput());
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped, and setUp()
        // skips in a kernel-only tree — so this must not touch the module
        // (or the tmp dir it never created) when Settings is not installed.
        if (is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            putenv('DB_CONFIG');
            DatabaseConnectionManager::resetInstance();
            SettingsServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function headerSlotAnswersTheSeededMenuAsKernelOwnedItems(): void
    {
        $items = (new SettingsShellContextProvider())->navigation('header', '/');

        $this->assertSame(
            ['Home', 'Articles'],
            self::labels($items),
            'The header slot must answer the seeded menu minus what a guest may not see.',
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
        $byLabel = self::byLabel((new SettingsShellContextProvider())->navigation('header', '/blog/nativa-php-core'));

        $this->assertTrue($byLabel['Articles']->active, 'An article is under /blog, so Articles is current.');
        $this->assertFalse($byLabel['Home']->active, 'A prefix match must not light up the root item.');
    }

    #[Test]
    public function footerAnswersItsOwnLocationAndAnUnknownSlotAnswersNothing(): void
    {
        $provider = new SettingsShellContextProvider();

        $this->assertSame(
            ['Docs'],
            self::labels($provider->navigation('footer', '/')),
            'The footer slot must answer the rows seeded for it.',
        );

        $this->assertSame([], $provider->navigation('sidebar', '/'), 'A slot with no rows is the port\'s no-menu answer.');
    }

    /**
     * The seed used to ship `Privacy -> /privacy` and `Cookies -> /cookies`,
     * and a fresh kernel install serves neither path: two of the footer's links
     * were 404s on a brand-new site. This pins the rule that replaced them —
     * every seeded href is a route the kernel itself owns.
     */
    #[Test]
    public function theSeedAdvertisesNoRouteTheKernelDoesNotServe(): void
    {
        $this->assertSame(
            ['/', '/blog', '/docs', '/mark'],
            $this->seededPublicHrefs(),
            'A seeded menu item points at a path this tree does not serve.',
        );
    }

    /**
     * The seed puts `Admin` in the header location. A reader who is not signed
     * in must not be handed a link into the admin surface.
     */
    #[Test]
    public function adminLinksAreHiddenFromAnonymousVisitors(): void
    {
        $this->assertNotContains(
            'Admin',
            self::labels((new SettingsShellContextProvider())->navigation('header', '/')),
            'An anonymous visitor was handed the admin link.',
        );
    }

    /**
     * The recursion, which the seed's own header does not exercise: a child that
     * points at the admin surface is dropped while its parent stays.
     */
    #[Test]
    public function aNestedAdminItemIsDroppedWhileItsParentStays(): void
    {
        $this->insertMenuItem('Tools', '/tools', 'header', 30);
        $this->insertMenuItem('Moderation', '/mark/comments', 'header', 10, 'Tools');

        $guest = self::byLabel((new SettingsShellContextProvider())->navigation('header', '/'));

        $this->assertArrayHasKey('Tools', $guest, 'The parent does not point at the admin surface and must stay.');
        $this->assertSame([], $guest['Tools']->children, 'A nested admin link reached an anonymous visitor.');

        $this->signIn();

        try {
            $signedIn = self::byLabel((new SettingsShellContextProvider())->navigation('header', '/'));
            $this->assertSame(
                ['Moderation'],
                self::labels($signedIn['Tools']->children),
                'A signed-in editor must keep the nested admin link.',
            );
        } finally {
            $this->signOut();
        }
    }

    #[Test]
    public function anAdminLinkStaysForASignedInEditor(): void
    {
        $this->assertNotContains('Admin', self::labels((new SettingsShellContextProvider())->navigation('header', '/')));

        $this->signIn();

        try {
            $this->assertContains(
                'Admin',
                self::labels((new SettingsShellContextProvider())->navigation('header', '/')),
                'The signed-in editor lost the admin link — the item exists for exactly this visitor.',
            );
        } finally {
            $this->signOut();
        }
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

    /**
     * Every href the Settings migrator seeds into the public slots, sorted —
     * the navigation a visitor can actually see.
     *
     * @return list<string>
     */
    private function seededPublicHrefs(): array
    {
        /** @var list<string> $hrefs */
        $hrefs = $this->pdo->query(
            "SELECT href FROM menu_items WHERE location IN ('header', 'footer')",
        )->fetchAll(\PDO::FETCH_COLUMN);

        sort($hrefs);

        return $hrefs;
    }

    /**
     * @param list<ShellNavItem> $items
     *
     * @return list<string>
     */
    private static function labels(array $items): array
    {
        return array_map(static fn (ShellNavItem $item): string => $item->label, $items);
    }

    /**
     * @param list<ShellNavItem> $items
     *
     * @return array<string, ShellNavItem>
     */
    private static function byLabel(array $items): array
    {
        $byLabel = [];

        foreach ($items as $item) {
            $byLabel[$item->label] = $item;
        }

        return $byLabel;
    }

    /**
     * The session the app itself would have: started, populated with the key the
     * kernel's shell chrome reads, and announced by cookie — which is what the
     * provider's own guard requires before it looks at `$_SESSION` at all.
     */
    private function signIn(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        $_SESSION['auth'] = ['user_id' => 'test-editor'];
        $_COOKIE[session_name()] = session_id();
    }

    private function signOut(): void
    {
        $_SESSION = [];
        session_destroy();
        unset($_COOKIE[session_name()]);
    }

    private function insertMenuItem(string $label, string $href, string $location, int $sort, ?string $parentLabel = null): void
    {
        $parent = null;

        if (null !== $parentLabel) {
            $stmt = $this->pdo->prepare('SELECT id FROM menu_items WHERE label = :label AND location = :loc');
            $stmt->execute([':label' => $parentLabel, ':loc' => $location]);
            $parent = $stmt->fetchColumn();
            $this->assertIsString($parent, 'The parent row must exist before its child is inserted.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_items (id, label, href, sort_order, location, icon, parent_item_id, is_active, created_at, updated_at)
             VALUES (:id, :label, :href, :sort, :loc, :icon, :parent, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':label' => $label,
            ':href' => $href,
            ':sort' => $sort,
            ':loc' => $location,
            ':icon' => '',
            ':parent' => $parent,
        ]);
    }
}
