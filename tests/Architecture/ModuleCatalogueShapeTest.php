<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Tooling\ModuleCatalog;
use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModuleCatalogueShapeTest — `modules/` has two shapes, and the catalogue knows
 * which is which.
 *
 * The four presentation modules (`Erik`, `Griffe`, `Jednotka`, `Mono`) are
 * themes, not features, and they live under `modules/Themes/<Name>/` so the
 * catalogue root reads as a list of things that DO something. The install
 * target does not move: `module:add Erik` still copies to
 * `src/Modules/Erik/` and the namespace is still `Modules\Erik`, so nothing
 * downstream (ThemeRegistry, discovery, `lekalo/modules/erik/`) can tell the
 * difference. That is exactly why the catalogue needs this test: the only place
 * the move is visible is the place that enumerates `modules/`, and a missed
 * enumerator fails quietly — the theme simply disappears from `module:list`,
 * or `Themes` starts being treated as an installable module.
 *
 * `Themes` passes `isValidName()` (PascalCase), so it cannot be excluded by
 * syntax. It is excluded by shape: a group is a directory whose children are
 * modules, never a module itself.
 *
 * @internal
 *
 * @coversNothing
 */
final class ModuleCatalogueShapeTest extends TestCase
{
    /** Directory under `modules/` that groups modules instead of being one. */
    private const string GROUP = 'Themes';

    /** @var list<string> */
    private const array THEMES = ['Erik', 'Griffe', 'Jednotka', 'Mono'];

    /**
     * Catalogue entries that are TEST FIXTURES rather than modules: `Scratch`
     * is installed, exercised and removed by `ModuleLifecycleTest`, and
     * `install-catalogue.sh` skips it for the same reason. Named, not excused
     * by a pattern — a silent addition here would be this test weakening.
     *
     * @var list<string>
     */
    private const array CATALOGUE_FIXTURES = ['Scratch'];

    /**
     * Installed slices with no catalogue entry of their own: `KernelTheme`
     * ships with the kernel tree the way `Core` and `Database` do.
     *
     * @var list<string>
     */
    private const array KERNEL_ONLY = ['KernelTheme'];

    private static function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function catalogue(): ModuleCatalog
    {
        return ModuleCatalog::fromAppPaths();
    }

    #[Test]
    public function theThemesGroupIsNotAModule(): void
    {
        $catalogue = self::catalogue();

        // The reason this needs saying at all: the name is syntactically valid.
        $this->assertTrue(ModuleCatalog::isValidName(self::GROUP));

        $this->assertNotContains(
            self::GROUP,
            $catalogue->catalogued(),
            '`modules/Themes/` groups modules; it must not be listed as one.',
        );

        $this->assertFalse(
            $catalogue->isCatalogued(self::GROUP),
            'A group has no manifest, so `isCatalogued()` must say no.',
        );

        $this->assertNull(
            $catalogue->cataloguePath(self::GROUP),
            'A group name must not resolve to a catalogue path.',
        );
    }

    #[Test]
    public function everyThemeIsCataloguedUnderTheGroup(): void
    {
        $catalogue = self::catalogue();

        foreach (self::THEMES as $theme) {
            $this->assertContains(
                $theme,
                $catalogue->catalogued(),
                sprintf('Theme `%s` vanished from the catalogue when it moved into %s/.', $theme, self::GROUP),
            );

            $path = $catalogue->cataloguePath($theme);

            $this->assertNotNull($path, sprintf('Theme `%s` has no catalogue path.', $theme));
            $this->assertSame(
                self::base() . '/modules/' . self::GROUP . '/' . $theme,
                $path,
                sprintf('Theme `%s` must live under modules/%s/.', $theme, self::GROUP),
            );

            $this->assertDirectoryDoesNotExist(
                self::base() . '/modules/' . $theme,
                sprintf('`modules/%s/` is the OLD location; the catalogue must not carry both.', $theme),
            );
        }
    }

    #[Test]
    public function theGroupNameCannotBeInstalled(): void
    {
        $catalogue = self::catalogue();

        try {
            $catalogue->manifest(self::GROUP);
            $this->fail('`manifest()` accepted a group name — it would install the whole group as one module.');
        } catch (\RuntimeException $refusal) {
            $this->assertStringContainsString(self::GROUP, $refusal->getMessage());
        }

        try {
            ModuleInstaller::fromAppPaths()->add(self::GROUP);
            $this->fail('`module:add Themes` was not refused.');
        } catch (\RuntimeException) {
            // The refusal is what matters; nothing may be copied.
        }

        $this->assertDirectoryDoesNotExist(
            self::base() . '/src/Modules/' . self::GROUP,
            'A refused install must not leave a slice behind.',
        );
    }

    /**
     * The catalogue and the installed tree describe the same SET of modules.
     * Two fixtures sit outside that set by design: `Scratch` is only ever
     * installed by its test, and `KernelTheme` is part of the tree, not an
     * entry in it.
     */
    #[Test]
    public function theCatalogueAndTheInstalledTreeDescribeTheSameModules(): void
    {
        $catalogue = self::catalogue();
        $installed = $catalogue->installed();

        // A kernel-only checkout carries the kernel tree plus `KernelTheme` and
        // no optional slices: `installed()` is never empty there, so the SET
        // that matters is the one with the kernel-only names removed. Skipping
        // on `[] === $installed` would fail this test on a clean kernel job —
        // exactly the tree it must stay green in.
        if ([] === array_diff($installed, self::KERNEL_ONLY)) {
            $this->markTestSkipped('Kernel-only checkout: no optional slices installed, nothing to compare.');
        }

        $catalogued = array_values(array_diff($catalogue->catalogued(), self::CATALOGUE_FIXTURES));
        $slices = array_values(array_diff($installed, self::KERNEL_ONLY));

        sort($catalogued);
        sort($slices);

        $this->assertSame(
            $slices,
            $catalogued,
            'A module is catalogued but not installed, or installed with no catalogue entry — '
            . 'moving a module into a group is a change the enumerator must follow.',
        );
    }

    /**
     * A group with no member is a directory nobody can install and nobody reads:
     * it would silently drop its modules from `catalogued()` if one ever left,
     * so the set is checked rather than assumed.
     */
    #[Test]
    public function everyCatalogueGroupHasAtLeastOneMember(): void
    {
        $catalogue = self::catalogue();

        foreach (ModuleCatalog::catalogueGroups() as $group) {
            $dir = self::base() . '/modules/' . $group;

            $this->assertDirectoryExists($dir, sprintf('Group `%s` is declared but does not exist.', $group));

            $members = array_values(array_filter(
                scandir($dir) ?: [],
                static fn (string $entry): bool => '.' !== $entry && '..' !== $entry && is_dir($dir . '/' . $entry),
            ));

            $this->assertNotSame(
                [],
                $members,
                sprintf('Group `%s` is empty — it would hide its modules instead of grouping them.', $group),
            );

            foreach ($members as $member) {
                $this->assertContains(
                    $member,
                    $catalogue->catalogued(),
                    sprintf('`%s/%s` is inside a group but missing from `catalogued()`.', $group, $member),
                );
            }
        }
    }

    /**
     * The admin module tree (`/mark/settings/modules`) and its metric compare
     * the installed slice against the catalogue on their own. A second, hand-built
     * `'/modules/' . $name` is how that page would report `identical` for every
     * theme after the move — `is_dir()` fails, `differences()` returns `[]`, and
     * the page says "in sync". One source of the path, or the page lies.
     */
    #[Test]
    public function theAdminModuleTreeReadsTheCataloguePathInsteadOfComposingItsOwn(): void
    {
        $file = self::base() . '/modules/Cardboard/Application/Services/ModuleTreeState.php';

        $this->assertFileExists($file);
        $source = (string) file_get_contents($file);

        $this->assertStringNotContainsString(
            "'/modules/'",
            $source,
            'ModuleTreeState must ask ModuleCatalog::cataloguePath(), not rebuild the path.',
        );
        $this->assertStringContainsString(
            'cataloguePath(',
            $source,
            'ModuleTreeState must resolve the catalogue path through ModuleCatalog.',
        );
    }
}
