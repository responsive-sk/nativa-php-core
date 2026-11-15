<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Infrastructure\Registry\AdminNavRegistry;
use Modules\Cardboard\Interfaces\HTTP\Actions\SettingsAction;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\Permissions;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminNavContractTest — the sidebar's source, through the wrapper.
 *
 * `AdminNavService` runs on every `/mark` render, in both the full and the
 * compact layout. It used to answer from three copies of the same list: its own
 * `FALLBACK`, the Settings seed, and (for permissions) a copy of
 * `AdminRoutePolicy`'s table. The nav is now declared by the modules that own
 * the pages, discovered from installed modules only, and this file pins the
 * three things that makes true:
 *
 *   - a fresh install renders the nav with no stored rows at all;
 *   - a stored row still overrides the declared item, so
 *     `/mark/settings/admin-nav` remains an editor rather than a stale copy;
 *   - every declared permission is the one the policy asks for, so the nav can
 *     never offer a link the middleware refuses.
 *
 * A raw `\PDO` is built here only for the migrator, which keeps `\PDO`: DDL
 * runs before any repository exists.
 */
final class AdminNavContractTest extends TestCase
{
    private string $tmpDir = '';

    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Cardboard') || !is_dir(APP_BASE_PATH . '/src/Modules/Settings')) {
            $this->markTestSkipped('Cardboard and Settings are not installed.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-admin-nav-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();
        AdminNavRegistry::reset();

        (new SettingsDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/config.db')))
            ->run($this->quietOutput());

        $this->connection = DatabaseConnectionManager::instance()->connect('config');
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/Cardboard')) {
            putenv('DB_CONFIG');
            DatabaseConnectionManager::resetInstance();
            CardboardServices::reset();
            AdminNavRegistry::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * A fresh install has header rows only, and the admin nav still renders.
     *
     * Before discovery this was `FALLBACK`: 18 hardcoded entries naming four
     * modules with no catalogue slice in this tree.
     */
    #[Test]
    public function aFreshInstallRendersTheNavFromDeclaredAttributes(): void
    {
        $this->clearAdminMenu();

        $items = CardboardServices::adminNav()->allItems();

        self::assertNotSame([], $items, 'the nav follows the installed modules, not a stored list');
        self::assertContains('Dashboard', array_column($items, 'label'));
        self::assertContains('/mark/articles', array_column($items, 'href'));

        // No catalogue slice, no attribute, no entry — the seed used to carry
        // this link to a 404 and `withoutUnreachable()` hid it again.
        self::assertNotContains('/mark/pages', array_column($items, 'href'));
    }

    /**
     * A module that is not installed has no route, so its entry must not
     * survive. The group test uses Blog's Categories child: the parent's own
     * href is absent too, and the group stays because a child is served.
     */
    #[Test]
    public function anItemWithoutAServedRouteIsDroppedWithItsGroupIntact(): void
    {
        $this->clearAdminMenu();

        $router = Router::fromEntries([], [
            ['path' => '/mark', 'method' => 'GET', 'name' => 'mark.dashboard', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/categories', 'method' => 'GET', 'name' => 'categories.manage', 'handlerMethod' => null, 'action' => self::class],
        ]);

        $tree = CardboardServices::adminNav()->hierarchicalItems(
            '',
            null,
            static fn (string $href): bool => $router->hasPath($href),
        );

        $ids = array_column($tree, 'id');
        self::assertContains('_dashboard', $ids);
        self::assertContains('_blog', $ids, 'a group survives when one child is served');
        self::assertNotContains('_notifications', $ids);
        self::assertNotContains('_media', $ids);

        $blog = null;
        foreach ($tree as $item) {
            if ('_blog' === $item['id']) {
                $blog = $item;
            }
        }

        self::assertNotNull($blog);
        self::assertSame(['_categories'], array_column($blog['children'], 'id'));
    }

    #[Test]
    public function aGroupExpandsWhenOneOfItsChildrenIsActive(): void
    {
        $this->clearAdminMenu();
        $this->insertItem('group-blog', 'Writing', '/mark/articles', null, 10);
        $this->insertItem('item-articles', 'Articles', '/mark/articles', 'group-blog', 10);

        $tree = CardboardServices::adminNav()->hierarchicalItems('articles');

        $group = null;
        foreach ($tree as $item) {
            if ('group-blog' === $item['id']) {
                $group = $item;
            }
        }

        self::assertNotNull($group, 'the stored row wins for the href it shares with the declaration');
        self::assertSame('Writing', $group['label'], 'a stored label overrides the declared one');

        // The stored row replaces the declared Articles child; Categories and
        // Tags are still declared and have no stored partner here, so the group
        // keeps three children in order.
        self::assertSame(['item-articles', '_categories', '_tags'], array_column($group['children'], 'id'));
        self::assertTrue($group['is_expanded'], 'a group is expanded from the merged set, not a second query');
    }

    /**
     * The acceptance that keeps the admin-nav screen meaningful: a row stored
     * for an item's href wins outright, so an operator can relabel or move a
     * module's entry without a code edit.
     */
    #[Test]
    public function aStoredRowOverridesTheDeclaredItem(): void
    {
        $this->clearAdminMenu();
        $this->insertItem('row-media', 'Images', '/mark/media', null, 5);

        $media = array_values(array_filter(
            CardboardServices::adminNav()->allItems(),
            static fn (array $item): bool => '/mark/media' === $item['href'],
        ));

        self::assertCount(1, $media, 'the row replaces the declaration instead of adding a second link');
        self::assertSame('row-media', $media[0]['id']);
        self::assertSame('Images', $media[0]['label']);
        self::assertSame(5, $media[0]['sort_order']);
    }

    /**
     * A declaration is a second statement of a permission the policy already
     * owns, so it must agree with it. Two failures matter: a permission no role
     * can hold (a dead item), and a permission the middleware does not ask for
     * (an item that 403s or, worse, promises a gate that is not there).
     */
    #[Test]
    public function everyDeclaredPermissionIsTheOneThePolicyAsksFor(): void
    {
        $declared = AdminNavRegistry::all();

        self::assertNotSame([], $declared, 'no #[AsAdminNavItem] was found on disk, so this test proves nothing.');

        $drift = [];

        foreach ($declared as $item) {
            $permission = $item['permission'];

            if (!is_string($permission) || !in_array($permission, Permissions::all(), true)) {
                $drift[] = sprintf('  %-16s %s is not a Permissions constant', $item['label'], var_export($permission, true));

                continue;
            }

            $owned = AdminRoutePolicy::requiredPermission($item['href']);

            if (null !== $owned && $owned !== $permission) {
                $drift[] = sprintf('  %-16s declares %s, policy asks %s', $item['label'], $permission, $owned);
            }
        }

        self::assertSame([], $drift, "A nav declaration disagrees with the policy that gates it:\n" . implode("\n", $drift));
    }

    /**
     * The MetricRegistry lesson, one directory over: a registry that drops a
     * declaration fails silently, and the missing item reports nothing. Every
     * attribute on disk must be in `all()`.
     */
    #[Test]
    public function everyDeclarationOnDiskIsRegistered(): void
    {
        $files = glob(APP_BASE_PATH . '/src/Modules/*/Application/Nav/*.php') ?: [];

        self::assertNotSame([], $files, 'the scan path found no nav declarations, so this test proves nothing.');

        $onDisk = 0;
        foreach ($files as $file) {
            $onDisk += substr_count((string) file_get_contents($file), 'AsAdminNavItem(');
        }

        self::assertSame(
            $onDisk,
            count(AdminNavRegistry::all()),
            'a declaration exists on disk and is not in AdminNavRegistry::all() — its menu item is missing and nothing says so.',
        );
    }

    /**
     * The settings screen must show a declared item that has no row.
     *
     * Without it the sidebar carries a link the screen cannot account for, and
     * the operator's only move — adding a row for that href — is invisible.
     */
    #[Test]
    public function theSettingsScreenListsDeclaredItemsWithNoRow(): void
    {
        $this->clearAdminMenu();

        $nav = CardboardServices::adminNav();
        $declared = $nav->declaredItems();

        self::assertNotSame([], $declared, 'no declaration was found, so this test proves nothing.');

        $html = SettingsAction::declaredNavTable($declared, $nav->storedItems());

        self::assertStringContainsString('Declared in code', $html);
        self::assertStringContainsString(AdminLayout::e($declared[0]['href']), $html);
        self::assertSame(
            count($declared),
            substr_count($html, 'Code only'),
            'with no stored rows, every declaration is listed as code-only',
        );

        $this->insertItem('row-declared', 'Renamed', $declared[0]['href'], null, 1);

        $html = SettingsAction::declaredNavTable($declared, $nav->storedItems());

        self::assertSame(1, substr_count($html, 'Overridden by a row'));
        self::assertSame(count($declared) - 1, substr_count($html, 'Code only'));
    }

    private function clearAdminMenu(): void
    {
        $this->connection->prepare("DELETE FROM menu_items WHERE location = 'admin'")->execute();
    }

    private function insertItem(string $id, string $label, string $href, ?string $parent, int $order): void
    {
        $this->connection
            ->prepare(
                'INSERT INTO menu_items (id, label, href, parent_item_id, sort_order, location, is_active)'
                . " VALUES (?, ?, ?, ?, ?, 'admin', 1)",
            )
            ->execute([$id, $label, $href, $parent, $order]);
    }

    private function quietOutput(): CliOutput
    {
        return new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
