<?php

declare(strict_types=1);

namespace Modules\Cardboard\Application\Services;

use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Tooling\ModuleCatalog;

/**
 * ModulesStateMetric — is the tree running the code it says it is?
 *
 * `src/Modules/<Name>` is gitignored operator state: a clone carries Core +
 * Database and nothing else, and `module:add` copies the catalogue in. So the
 * running tree and the committed catalogue are allowed to disagree — and they
 * do, quietly.
 *
 * Measured 2026-10-17, six modules after each other. `AdminRoutePolicy` and
 * `Permissions` moved to `Modules\Core\Domain` and Cardboard's two local copies
 * of the permission table were deleted. Every gate was green: phpstan, the
 * suite, the ratchet. And the **installed slice still carried the old code**,
 * because nothing had compared the two. `git status` cannot see it — the slice
 * is not tracked — and `module:show` reports `isInstalled()`, which answers "is
 * there a slice", not "is it the same slice".
 *
 * So this card makes it visible. It is deliberately **neutral rather than
 * alarming**: drift between catalogue and slice is the expected state after a
 * commit and before a `module:add`, so a red card would cry wolf on every normal
 * edit of the catalogue. The subtitle names the count; the judgment about when
 * to reinstall stays with the operator.
 *
 * ## Why it lives in Cardboard and not in Tooling
 *
 * `ModuleCatalog` is in `Modules\Tooling`, which is kernel — and a file there
 * naming Cardboard is a NEW EDGE on `KernelCouplingTest`'s ledger, whose whole
 * claim is that no kernel file names another module. Tooling is also *below*
 * Cardboard in the load order, so a metric provider there would be the first
 * thing to reach for a contract Cardboard owns. Measured: `KernelCouplingTest`
 * failed with six edges and `Ledger: 0 rows` the moment the class was written
 * there, and `phpstan` was happy with it — the coupling guard is the one that
 * sees this shape, which is why it exists.
 *
 * Every other provider already lives in the module whose numbers it reports,
 * and Cardboard is where the dashboard that renders them lives. This one is
 * different only in that it reports on modules rather than about its own.
 *
 * Priority 30: the lowest number on the board, so it sorts below every content
 * metric. This is infrastructure state, not something anyone triages.
 *
 * @internal
 *
 * @coversNothing
 */
#[AsMetricProvider(priority: 30)]
final readonly class ModulesStateMetric implements MetricContract
{
    public function __construct(
        private ?ModuleCatalog $catalogue = null,
    ) {
    }

    public function registerCards(Period $period): void
    {
        try {
            $catalogue = $this->catalogue ?? ModuleCatalog::fromAppPaths();
            $report = $this->report($catalogue);

            CardboardRegistry::register(
                Card::make('modules', 'Modules', $report['total'])
                    ->withSubtitle(sprintf(
                        '%d ok · %d drift · %d not installed',
                        $report['identical'],
                        $report['drifted'],
                        $report['missing'],
                    ))
                    ->withActions(CardLink::make('Module List', '/mark/settings'))
                    ->withPriority(30),
            );
        } catch (\Throwable) {
            // No filesystem, no catalogue — skip silently, the way every other
            // provider does. A dashboard that 500s over a stat card helps nobody.
        }
    }

    /**
     * Byte-compare each installed slice against the catalogue it came from.
     *
     * @return array{total: int, identical: int, drifted: int, missing: int}
     */
    private function report(ModuleCatalog $catalogue): array
    {
        $identical = 0;
        $drifted = 0;
        $missing = 0;

        foreach ($catalogue->catalogued() as $name) {
            if (!$catalogue->isInstalled($name)) {
                ++$missing;

                continue;
            }

            if ([] === $this->differences($catalogue, $name)) {
                ++$identical;
            } else {
                ++$drifted;
            }
        }

        return [
            'total' => count($catalogue->catalogued()),
            'identical' => $identical,
            'drifted' => $drifted,
            'missing' => $missing,
        ];
    }

    /**
     * Which files of a module's slice differ from the catalogue.
     *
     * `tests/` is excluded on both sides: tests live in the catalogue and are run
     * from there, so a slice without them is correct rather than drifted. That is
     * the same finding `Locales` carries.
     *
     * @return list<string> paths relative to the module root
     */
    private function differences(ModuleCatalog $catalogue, string $name): array
    {
        $catalogued = AppPaths::instance()->base() . '/modules/' . $name;
        $installed = AppPaths::instance()->src('Modules/' . $name);

        if (!is_dir($catalogued) || !is_dir($installed)) {
            return [];
        }

        $different = [];

        foreach ($this->comparedFiles($catalogued) as $relative) {
            $from = $catalogued . '/' . $relative;
            $to = $installed . '/' . $relative;

            if (!is_file($to)) {
                $different[] = $relative;

                continue;
            }

            if ($this->digest($from) !== $this->digest($to)) {
                $different[] = $relative;
            }
        }

        return $different;
    }

    /**
     * Files worth comparing: code and critical CSS, not assets or tests.
     *
     * Build output is a legitimate difference — `dist/` is regenerated by
     * `make assets` and the slice carries whatever was last built. A file that
     * only exists in the slice is the installer's business (`module:add` copies
     * assets deliberately), so only the catalogue's own files are read here.
     *
     * @return list<string> paths relative to $dir, slash-separated
     */
    private function comparedFiles(string $dir): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            foreach (['/tests/', '/assets/', '/dist/', '/node_modules/'] as $skip) {
                if (str_contains($path, $skip)) {
                    continue 2;
                }
            }

            if ('php' !== $file->getExtension() && 'css' !== $file->getExtension()) {
                continue;
            }

            $found[] = substr($path, strlen($dir) + 1);
        }

        sort($found);

        return $found;
    }

    /**
     * Length and hash, because two files differing only in trailing whitespace
     * are still different files and this reports drift rather than peace.
     */
    private function digest(string $file): string
    {
        $contents = (string) file_get_contents($file);

        return strlen($contents) . ':' . hash('sha256', $contents);
    }
}