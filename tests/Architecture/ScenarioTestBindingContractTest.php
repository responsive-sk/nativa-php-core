<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScenarioTestBindingContractTest — every openspec scenario names the test that
 * holds it.
 *
 * Measured 2026-10-27: 57 scenarios across six spec files, none of them
 * carrying a machine-readable reference to the test that keeps them true.
 * Deleting or renaming such a test left `openspec validate --all` green,
 * because the validator checks the model, not the code — the same gap Lekalo
 * has with `"adapters":[]`, which three PHPUnit contracts close by reading a
 * class AS TEXT (`ModelPayloadContractTest` is the pattern). This is that same
 * move on the openspec side.
 *
 * ## The convention
 *
 * A scenario's bullet list carries one or more of:
 *
 *     - PINS: <repo-relative test file>::<method>
 *     - PINS: none — <reason>
 *
 * Measured 2026-10-27 before this file was written: a temporary change whose
 * scenario carried a `PINS:` bullet passed `openspec validate --strict` with
 * exit 0, so this is a bullet the openspec validator ignores rather than a
 * forked grammar or a sidecar file. The probe directory was deleted after the
 * measurement.
 *
 * ## Why this is a local gate and not a CI gate
 *
 * `/openspec/` is gitignored — "OpenSpec plans and specs are our internal
 * tooling too (decision 2026-10-14): they stay local and never go to GitHub" —
 * so a CI checkout has no specs at all. The check therefore SKIPS when the
 * tree is absent, the shape module tests already use when their module is not
 * installed (`TaxonomyRegistryTest`), and asserts the floor of files and
 * scenarios it read whenever the tree IS there, so it cannot pass by reading
 * nothing. A skipped check makes no claim, and says so; it is not a pass.
 * Four of the five tests guard that way — the CI job-count ratchet does not,
 * because it reads a tracked file and is worth running where the specs are
 * absent.
 *
 * ## The ratchet
 *
 * 57 scenarios are unpinned today and that number is held EXACTLY: more
 * unpinned scenarios than the allowance fail, and fewer fail too, because
 * lowering it is part of the same change as the annotation that lowered it.
 * A new scenario can therefore never hide under the allowance — it arrives
 * without a pin and the count goes over. The phases are recorded in
 * `openspec/changes/scenario-test-binding/tasks.md`: canonical spec first
 * (57 → 48), then one capability per phase (→ 41 → 31 → 20 → 9 → 0).
 *
 * The allowance is a method rather than a class constant for the reason
 * `SlovakProseContractTest` records: `NameOwnershipContractTest` ratchets the
 * tree's `const` count, and a gate's own lookup table is not a name anybody
 * else owns.
 *
 * @internal
 *
 * @coversNothing
 */
final class ScenarioTestBindingContractTest extends TestCase
{
    /**
     * The check cannot claim anything about a checkout without the spec tree.
     * The guard is a method taking the root, so the "no specs here" path is
     * reachable by a test rather than only by unplugging the repository.
     */
    #[Test]
    public function theCheckSkipsWithoutTheSpecTree(): void
    {
        self::skipWithoutSpecTree();

        $absent = self::skipReason(sys_get_temp_dir() . '/' . uniqid('nativa-php-core-no-openspec-', true));

        self::assertNotNull(
            $absent,
            'A checkout without openspec/ must be skipped, not checked: with no spec tree there is'
            . ' nothing to bind, and a check that reads nothing must not report a pass.',
        );
        self::assertStringContainsString('gitignored', $absent, 'The skip has to name why the tree is missing.');
        self::assertStringContainsString('not a pass', $absent, 'The skip has to refuse to be read as green.');

        self::assertNull(
            self::skipReason(self::root()),
            'This checkout carries the spec tree, so the guard must not fire — the rest of this file'
            . ' would be measuring nothing.',
        );
    }

    /**
     * Every scenario is either pinned or inside the allowance, and the
     * allowance is exact.
     */
    #[Test]
    public function everyScenarioNamesATest(): void
    {
        self::skipWithoutSpecTree();

        $scenarios = self::scenarios(self::root());

        self::assertGreaterThanOrEqual(
            60,
            count($scenarios),
            'The spec walk found ' . count($scenarios) . ' scenarios — the roots moved or the parser'
            . ' stopped matching, and this check would pass by reading almost nothing.',
        );

        $unpinned = self::exemptions($scenarios);
        $count = count($unpinned);
        $allowance = self::exemptionAllowance();
        $drift = $count > $allowance
            ? sprintf(
                '%d more than the allowance: pin the new scenario with `- PINS: <file>::<method>`, or'
                . ' raise exemptionAllowance() in the same change as the reason.',
                $count - $allowance,
            )
            : sprintf(
                '%d fewer than the allowance: they got pinned, so lower exemptionAllowance() here —'
                . ' it is the ratchet, and this is the revision that moves it.',
                $allowance - $count,
            );

        self::assertSame(
            $allowance,
            $count,
            sprintf(
                'Scenarios that carry no resolving pin: %d, allowance: %d — %s First unpinned: %s',
                $count,
                $allowance,
                $drift,
                implode(' | ', array_slice(self::describe($unpinned), 0, 5)),
            ),
        );
    }

    /**
     * A pin is a test that exists. Anything else is a claim nobody checks.
     */
    #[Test]
    public function aPinResolvesToRealFileAndMethod(): void
    {
        self::skipWithoutSpecTree();

        $problems = [];

        foreach (self::scenarios(self::root()) as $scenario) {
            foreach ($scenario['pins'] as $pin) {
                if (null !== self::exemptionReason($pin)) {
                    continue;
                }

                foreach (self::pinProblems(self::root(), $pin) as $problem) {
                    $problems[] = sprintf('%s (%s) — "%s": %s', $scenario['scenario'], $scenario['file'], $pin, $problem);
                }
            }
        }

        self::assertSame(
            [],
            $problems,
            "A pin must resolve to a file under tests/ or modules/*/tests/ and to a method in it.\n"
            . implode("\n", $problems),
        );

        self::assertNotSame(
            [],
            self::pinProblems(self::root(), 'src/Modules/Core/Infrastructure/Http/Request.php::bodyString'),
            'A pin outside the two test roots has to be refused — a production method is not a test,'
            . ' and accepting it would turn the binding into a comment that happens to compile.',
        );
        self::assertNotSame(
            [],
            self::pinProblems(self::root(), 'tests/Architecture/ScenarioTestBindingContractTest.php::aMethodThatDoesNotExist'),
            'A pin naming a method that is not in the file has to be refused — this is the shape a'
            . ' renamed or deleted test leaves behind.',
        );
    }

    /**
     * `PINS: none` is a decision with a written reason, and its count is part
     * of the ratchet rather than a way around it.
     */
    #[Test]
    public function anExemptionCarriesAReasonAndIsCounted(): void
    {
        self::skipWithoutSpecTree();

        self::assertNull(
            self::exemptionReason('modules/Validation/tests/ValidatorTest.php::emptyPasswordReportsOnce'),
            'A test reference is not an exemption.',
        );
        self::assertSame(
            '',
            self::exemptionReason('none'),
            'A bare `none` is a silence: the reason is what makes it a decision.',
        );
        self::assertSame(
            'not annotated yet — phase 1',
            self::exemptionReason('none — not annotated yet — phase 1'),
        );

        $scenarios = self::scenarios(self::root());
        $unreasoned = [];

        foreach ($scenarios as $scenario) {
            foreach ($scenario['pins'] as $pin) {
                if ('' === self::exemptionReason($pin)) {
                    $unreasoned[] = $scenario['file'] . ' — ' . $scenario['scenario'];
                }
            }
        }

        self::assertSame(
            [],
            $unreasoned,
            'These scenarios say `PINS: none` with no reason. Write the decision, or write the test:'
            . "\n" . implode("\n", $unreasoned),
        );

        // The allowance cannot hide a NEW scenario: one more unpinned scenario
        // pushes the count over, whatever headroom the phases left.
        $oneMore = [...$scenarios, [
            'file' => 'openspec/changes/example/specs/example/spec.md',
            'scenario' => 'A scenario that arrived without a pin',
            'pins' => [],
        ]];

        self::assertGreaterThan(
            self::exemptionAllowance(),
            count(self::exemptions($oneMore)),
            'A new unpinned scenario has to fail on the count, so the allowance cannot be used as'
            . ' headroom for work that has not been done.',
        );
    }

    /**
     * The binding promised to cost no CI job. The job count is a ratchet, so
     * the promise is checkable rather than remembered.
     */
    #[Test]
    public function theBindingNeedsNoNewGate(): void
    {
        $workflow = (string) file_get_contents(self::root() . '/.github/workflows/ci.yml');
        $at = strpos($workflow, "\njobs:\n");

        self::assertNotFalse($at, 'The workflow no longer has a top-level `jobs:` block — this check reads it.');

        $matched = [];
        $jobs = preg_match_all('/^  [a-z][a-z0-9_-]*:$/m', substr($workflow, $at), $matched);

        self::assertIsInt($jobs, 'The job keys are not where this check looks for them.');
        self::assertSame(
            15,
            $jobs,
            'CI runs 15 jobs and this capability promised not to add a 16th: it rides in the existing'
            . ' `tests` job. Grow the toolchain on purpose and raise this number in that change, not'
            . ' silently here.',
        );
    }

    /**
     * The four tests that read specs stop here on a checkout without them.
     * `theBindingNeedsNoNewGate` deliberately does NOT call this: it reads the
     * workflow, which is tracked, so the CI job-count ratchet works in CI even
     * though the specs do not.
     */
    private static function skipWithoutSpecTree(): void
    {
        $reason = self::skipReason(self::root());

        if (null !== $reason) {
            self::markTestSkipped($reason);
        }
    }

    /**
     * Why this checkout cannot be checked, or null when it can.
     */
    private static function skipReason(string $root): ?string
    {
        if (is_dir($root . '/openspec/specs') || is_dir($root . '/openspec/changes')) {
            return null;
        }

        return 'openspec/ is not in this checkout, so there is nothing to bind. The spec tree is'
            . ' gitignored (decision 2026-10-14: plans and specs stay local and never go to GitHub),'
            . ' which is why CI has no specs at all. Nothing was checked here — and that is not a'
            . ' pass, it is a skip.';
    }

    /**
     * How many scenarios may stay unbound. Exact, and deliberately a method
     * (see the class docblock).
     */
    private static function exemptionAllowance(): int
    {
        return 57;
    }

    /**
     * @param list<array{file: string, scenario: string, pins: list<string>}> $scenarios
     *
     * @return list<string>
     */
    private static function describe(array $scenarios): array
    {
        return array_map(
            static fn (array $scenario): string => $scenario['file'] . ' — ' . $scenario['scenario'],
            $scenarios,
        );
    }

    /**
     * Every scenario in the canonical specs and in the change deltas, with the
     * `PINS:` lines it carries. `archive/` is excluded: an archived change is
     * history and is never edited again.
     *
     * @return list<array{file: string, scenario: string, pins: list<string>}>
     */
    private static function scenarios(string $root): array
    {
        $found = [];

        foreach (self::specFiles($root) as $path) {
            $relative = str_replace($root . '/', '', $path);
            $scenario = null;
            $pins = [];

            foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (1 === preg_match('/^#### Scenario:\s*(.+?)\s*$/u', $line, $matched)) {
                    if (null !== $scenario) {
                        $found[] = ['file' => $relative, 'scenario' => $scenario, 'pins' => $pins];
                    }

                    $scenario = $matched[1];
                    $pins = [];

                    continue;
                }

                if (null === $scenario) {
                    continue;
                }

                if (str_starts_with($line, '#')) {
                    $found[] = ['file' => $relative, 'scenario' => $scenario, 'pins' => $pins];
                    $scenario = null;
                    $pins = [];

                    continue;
                }

                if (1 === preg_match('/^\s*-\s*PINS:\s*(.+?)\s*$/u', $line, $matched)) {
                    $pins[] = $matched[1];
                }
            }

            if (null !== $scenario) {
                $found[] = ['file' => $relative, 'scenario' => $scenario, 'pins' => $pins];
            }
        }

        return $found;
    }

    /**
     * `null` when the value is a test reference; otherwise the reason — an
     * empty string when `none` is written without one.
     */
    private static function exemptionReason(string $value): ?string
    {
        if (1 !== preg_match('/^none\b(.*)$/us', trim($value), $matched)) {
            return null;
        }

        return 1 === preg_match('/^—\s*(.+)$/u', trim($matched[1]), $reason) ? trim($reason[1]) : '';
    }

    /**
     * Unpinned scenarios and explicit `none` scenarios — the ones the
     * allowance counts.
     *
     * @param list<array{file: string, scenario: string, pins: list<string>}> $scenarios
     *
     * @return list<array{file: string, scenario: string, pins: list<string>}>
     */
    private static function exemptions(array $scenarios): array
    {
        $exempt = [];

        foreach ($scenarios as $scenario) {
            if ([] === $scenario['pins']) {
                $exempt[] = $scenario;

                continue;
            }

            foreach ($scenario['pins'] as $pin) {
                if (null !== self::exemptionReason($pin)) {
                    $exempt[] = $scenario;

                    break;
                }
            }
        }

        return $exempt;
    }

    /**
     * Everything wrong with one pin, read as text. Empty means it resolves.
     *
     * @return list<string>
     */
    private static function pinProblems(string $root, string $pin): array
    {
        $at = strrpos($pin, '::');

        if (false === $at || '' === substr($pin, 0, $at) || '' === substr($pin, $at + 2)) {
            return ['not `<repo-relative test file>::<method>`'];
        }

        $path = substr($pin, 0, $at);
        $method = substr($pin, $at + 2);

        if (!str_starts_with($path, 'tests/') && 1 !== preg_match('#^modules/[^/]+/tests/#', $path)) {
            return [sprintf('"%s" is outside tests/ and modules/*/tests/ — a pin names a test', $path)];
        }

        if (!is_file($root . '/' . $path)) {
            return [sprintf('%s does not exist', $path)];
        }

        if (!str_contains((string) file_get_contents($root . '/' . $path), 'function ' . $method . '(')) {
            return [sprintf('%s has no function %s()', $path, $method)];
        }

        return [];
    }

    /**
     * The canonical specs and the deltas of every non-archived change.
     *
     * @return list<string>
     */
    private static function specFiles(string $root): array
    {
        $files = glob($root . '/openspec/specs/*/spec.md') ?: [];

        foreach (glob($root . '/openspec/changes/*', GLOB_ONLYDIR) ?: [] as $change) {
            if ('archive' === basename($change)) {
                continue;
            }

            foreach (glob($change . '/specs/*/spec.md') ?: [] as $spec) {
                $files[] = $spec;
            }
        }

        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
