<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * BundlerDecisionContractTest — ADR 0001 is the one owner of "which bundler",
 * and this is the side that fails when a package disagrees with it.
 *
 * The decision itself is prose plus one machine block in
 * `docs/adr/0001-theme-assets-build-with-vite.md`. Reading it from there is the
 * point: a list spelled a second time in this file would be the copy that
 * nobody updates, which is the defect the tree's registry rules exist to
 * prevent. So the test asks the ADR what is accepted and what is rejected, then
 * checks the packages against that answer.
 *
 * Two directions, both cheap:
 *
 * - a declared dependency in any package file under `Frontend/` may not name a
 *   rejected bundler, so "let us try webpack for this one package" fails at
 *   review instead of at a bundle nobody compared;
 * - every theme config must reach `createThemeConfig`, so the target, the
 *   manifest name and the output directory cannot fork per package.
 *
 * What it deliberately does not do: read `pnpm-lock.yaml`. A bundler pulled in
 * transitively is not a choice this gate can make for a dependency's author.
 *
 * @internal
 *
 * @coversNothing
 */
final class BundlerDecisionContractTest extends TestCase
{
    #[Test]
    public function theRecordedDecisionNamesOneBundler(): void
    {
        $decision = self::decision();

        $this->assertSame(
            ['vite'],
            $decision['accepted_bundlers'],
            'ADR 0001 must name exactly one accepted bundler; a second one is a fork of the build shape.',
        );

        $this->assertNotEmpty(
            $decision['rejected_bundlers'],
            'ADR 0001 lists no rejected bundler, so this gate would pass by checking nothing.',
        );

        foreach ($decision['rejected_bundlers'] as $rejected) {
            $this->assertNotContains(
                $rejected,
                $decision['accepted_bundlers'],
                'ADR 0001 accepts and rejects the same bundler: ' . $rejected,
            );
        }
    }

    #[Test]
    public function noFrontendPackageDeclaresARejectedBundler(): void
    {
        $decision = self::decision();
        $offenders = [];
        $read = 0;

        foreach (self::packageFiles() as $file) {
            $read++;

            $package = self::json($file);
            $declared = [
                ...self::keysOf($package['dependencies'] ?? null),
                ...self::keysOf($package['devDependencies'] ?? null),
                ...self::keysOf($package['optionalDependencies'] ?? null),
            ];

            foreach ($declared as $name) {
                foreach ($decision['rejected_bundlers'] as $rejected) {
                    if (self::namesTool((string) $name, $rejected)) {
                        $offenders[] = sprintf('%s declares %s', $file, $name);

                        continue;
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            count(self::workspacePackages()),
            $read,
            'The scan read fewer package files than the workspace has, so a green result would be a read of nothing.',
        );

        $this->assertSame(
            [],
            $offenders,
            'A package declares a bundler ADR 0001 rejected. Either drop it, or'
            . ' supersede the ADR with the measurement that made the change worth'
            . ' it: ' . implode('; ', $offenders),
        );
    }

    #[Test]
    public function everyThemeBuildReachesTheOneConfigShape(): void
    {
        $configs = self::themeConfigs();
        $packages = array_values(array_diff(
            array_map(dirname(...), self::workspacePackages()),
            [dirname(__DIR__, 2) . '/Frontend/packages'],
        ));

        $this->assertSame(
            $packages,
            array_map(dirname(...), $configs),
            'Every workspace package with a package file must have one Vite config, and no config may sit outside the workspace.',
        );

        foreach ($configs as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringContainsString(
                'createThemeConfig',
                $source,
                sprintf(
                    '%s does not build through Frontend/packages/build/vite-theme-factory.ts.'
                    . ' The target, the manifest name and the output directory are one'
                    . ' decision (ADR 0001), and a local copy is a second owner of it.',
                    $file,
                ),
            );
        }
    }

    /**
     * The ADR that carries the machine block. Exactly one file may carry it,
     * because two answers to one question is the state this gate prevents.
     *
     * @return array{accepted_bundlers: list<string>, rejected_bundlers: list<string>}
     */
    private static function decision(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (glob($root . '/docs/adr/*.md') ?: [] as $file) {
            $text = (string) file_get_contents($file);

            if (!str_contains($text, 'accepted_bundlers')) {
                continue;
            }

            if (1 !== preg_match('/```json\n(.*?)\n```/s', $text, $match)) {
                throw new RuntimeException($file . ' carries the machine block without a fenced json block.');
            }

            $decoded = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new RuntimeException($file . ' carries a machine block that is not a JSON object.');
            }

            $found[$file] = $decoded;
        }

        if (1 !== count($found)) {
            throw new RuntimeException(sprintf(
                'Expected exactly one ADR with a bundler machine block under docs/adr/, found %d: %s',
                count($found),
                implode(', ', array_keys($found)),
            ));
        }

        $block = array_values($found)[0];

        return [
            'accepted_bundlers' => self::strings($block['accepted_bundlers'] ?? null),
            'rejected_bundlers' => self::strings($block['rejected_bundlers'] ?? null),
        ];
    }

    /**
     * The workspace's own package files, one per theme package.
     *
     * @return list<string>
     */
    private static function workspacePackages(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/Frontend/packages/*/package.json') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Every package file under `Frontend/`, without the installed tree.
     *
     * @return list<string>
     */
    private static function packageFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/Frontend';
        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => 'node_modules' !== $file->getFilename(),
            ),
        );

        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo && 'package.json' === $file->getFilename()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every theme package's Vite config.
     *
     * @return list<string>
     */
    private static function themeConfigs(): array
    {
        return glob(dirname(__DIR__, 2) . '/Frontend/packages/*/vite.config.ts') ?: [];
    }

    /**
     * Does a declared dependency name this tool, scoped or with a suffix?
     * `@rspack/core`, `webpack-cli` and `rollup-plugin-x` all count.
     */
    private static function namesTool(string $name, string $tool): bool
    {
        $name = strtolower($name);
        $parts = explode('/', ltrim($name, '@'));

        foreach ($parts as $part) {
            if ($part === $tool || str_starts_with($part, $tool . '-') || str_starts_with($part, $tool . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The dependency names a package declares, whatever the value beside them.
     *
     * @return list<string>
     */
    private static function keysOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $keys = [];

        foreach (array_keys($value) as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException($file . ' is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
