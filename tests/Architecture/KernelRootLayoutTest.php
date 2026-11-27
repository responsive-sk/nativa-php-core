<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KernelRootLayoutTest — the tree has one kernel home.
 *
 * `README.md` says `Modules\Core` and `Modules\Database` are the kernel and
 * that everything else is a module. Before the kernel-roots round `src/` also
 * carried five kernel namespaces beside `src/Modules` (`Discovery`, `Seo`,
 * `Shell`, `Themes`, `View`), which made the sentence false: a new class could
 * land in either taxonomy and no gate noticed. This is that gate.
 *
 * The three entry files are the allowed root: `init.php` wires the autoloader,
 * `http.php` the HTTP composition, `composition.php` the module handlers. Any
 * other PHP file beside them is a second place to look for kernel code.
 */
final class KernelRootLayoutTest extends TestCase
{
    #[Test]
    public function testTheSourceTreeHoldsOnlyTheModulesDirectory(): void
    {
        $directories = [];

        foreach ($this->entries() as $entry) {
            if (is_dir(APP_BASE_PATH . '/src/' . $entry)) {
                $directories[] = $entry;
            }
        }

        sort($directories);

        self::assertSame(
            ['Modules'],
            $directories,
            'src/ must hold one kernel home. A second directory is a second taxonomy: '
            . 'put the class under Modules\Core and keep this list at one entry.',
        );
    }

    #[Test]
    public function testTheSourceRootHoldsOnlyTheEntryFiles(): void
    {
        $files = [];

        foreach ($this->entries() as $entry) {
            if (is_file(APP_BASE_PATH . '/src/' . $entry)) {
                $files[] = $entry;
            }
        }

        sort($files);
        $expected = ['composition.php', 'http.php', 'init.php'];
        sort($expected);

        self::assertSame(
            $expected,
            $files,
            'the root of src/ is the entry surface, not a place for classes: '
            . 'a new PHP file here needs a home under Modules\Core.',
        );
    }

    /** @return list<string> */
    private function entries(): array
    {
        $entries = scandir(APP_BASE_PATH . '/src');

        self::assertIsArray($entries, 'src/ is unreadable, so this guard would assert nothing');

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => '.' !== $entry && '..' !== $entry,
        ));
    }
}
