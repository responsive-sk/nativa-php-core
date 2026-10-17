<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * NameOwnershipContractTest — one name, one owner, measured instead of believed.
 *
 * Measured 2026-10-17, while a const audit asked the obvious question: is a
 * class constant a good way to hold a value that appears in more than one
 * place? The tree had 414 declarations for 203 names. That number is not a
 * defect — most constants are private invariants and are exactly right. The
 * defect is narrower and this test is shaped around it, not around the total.
 *
 * ## Three rules, and only one of them is absolute
 *
 * 1. **A string value must not be claimed by two names.** Absolute, with an
 *    allow-list of the two that exist today. A string is an identifier —
 *    `'users.manage'` written as `REQUIRED` instead of `USERS` means two
 *    owners for one permission and nothing reports the rename. Two
 *    exemptions is a list a person can hold in their head; the list also fails
 *    when an entry stops applying, so it cannot rot.
 * 2. **A value must not be spelled as a raw literal outside its owner.**
 *    A RATCHET, not a prohibition, and that is the measured conclusion rather
 *    than a compromise. 52 owned string values appear as literals on 502
 *    places across the tree. `'sk_SK'` alone is 132 of them and `'en'` 48:
 *    a locale code, the default language, spelled in seed data, translations
 *    and URL tests, which is data and not a second owner. A gate with 502
 *    exemptions is not a gate. 502 is a CEILING: every consolidation lowers it,
 *    and the gate refuses the 503rd.
 * 3. **The declaration count must not rise.** Ratchet at 238/202. This is the
 *    rule that catches the cheapest wrong fix — "there are two names for this,
 *    let me add a third" — which is the complaint that started all of this.
 *
 * ## The counting definition is the test, not a comment in it
 *
 * A regex over this tree finds 6 constants, not 414, because the tree writes
 * typed constants (`private const string MARKER = '.migrations-applied';`). A regex is
 * also wrong in the other direction: it matches constants inside comments,
 * which this tree is full of because it documents its constants at length. So
 * this test tokenises. Two traps are encoded in `constantDeclarations()` and
 * both were found by measurement, not by reading:
 *
 *   - `array` is T_ARRAY, not T_STRING. A type detector that knows only
 *     T_STRING silently loses every `const array` — 88 here.
 *   - an UNTYPED `const NAME = …` has a name that is itself a T_STRING, so a
 *     loop that consumes T_STRING as "a type" walks past the name onto the `=`
 *     and drops the declaration.
 *
 * ## The scope is git-tracked files only, and that is not cosmetic
 *
 * `src/Modules/<Name>` is gitignored operator state: a kernel-only checkout
 * carries Core + Database and nothing else. Scoping this test at the whole
 * tree would make its result depend on which modules this checkout happens to
 * have installed — the exact defect `ModelCatalogueContractTest` was fixed for
 * two commits ago. So the roots below are the tracked surface, and the result
 * is verified identical on both shapes: 403 files, 54 values, 531 places.
 *
 * Deliberately cheap and deliberately dumb, like `KernelShapeTest` beside it:
 * file listings and a tokeniser, compared. What it protects is narrower than
 * "this codebase has good names" and is stated rather than implied.
 *
 * The ledger — every exemption with its reason, every ceiling with the
 * measurement behind it, and three ways the counting goes wrong — is
 * `docs/name-ownership.md`. The numbers live here, the reasons live there; a
 * ceiling raised without a reason in the commit message is not a reviewable
 * edit, it is a gate that has stopped working.
 *
 * @internal
 *
 * @coversNothing
 */
final class NameOwnershipContractTest extends TestCase
{
    /**
     * Tracked roots only. `public/dist` is build output — copied bytes, not
     * declarations. Every other `src/Modules/<Name>` is an installed slice and
     * is deliberately absent; see the class docblock.
     *
     * @var list<string>
     */
    private const array SCOPE = [
        'src/Modules/Core',
        'src/Modules/Database',
        'src/Modules/KernelTheme',
        'src/Modules/Tooling',
        'src/Themes',
        'modules',
        'tests',
        'bin',
        'public',
    ];

    /** Rule 1 — string values that two names claim today, and why each stays. */
    private const array MULTI_NAME_EXEMPTIONS = [
        "'Cardboard'" => 'Two tests that each guard their OWN skip. KernelOnlyBootTest is in'
            . ' tests/Boot and must not statically name an optional module;'
            . ' SettingsThemeSelectTest lives in modules/Cardboard and imports nothing from'
            . ' tests/ — zero of the module\'s tests do, and the first would set a precedent'
            . ' KernelCouplingTest would then have to know about. Merging them would couple'
            . ' two files that are deliberately independent, and the name has no production'
            . ' owner to consolidate onto: nothing outside tests/ writes \'Cardboard\'.',
        "'203.0.113.7'" => 'RateLimitMiddlewareTest::CLIENT_IP (Security) and AuthServiceTest::REMOTE'
            . ' (Users) — TWO MODULES, and the earlier text naming'
            . ' AuthMiddlewareDenialTest::REMOTE was simply wrong: that constant does not'
            . ' exist, and this test file owns 203.0.113.9 instead. The value is an RFC 5737'
            . ' documentation address and each test uses it as a SCENARIO, not an'
            . ' identifier: PasswordResetServiceTest walks 203.0.113.1/.2/.3 to prove one'
            . ' address gets three attempts, which is what the octet MEANS here. A shared'
            . ' tests/Support constant would claim the two runs are the same run, and would'
            . ' be a cross-module import — tests/Support costs nothing at runtime'
            . ' (autoload-dev) so the argument against it is the dependency, not speed.',
    ];

    /**
     * The gate files, which neither rule 2's ceiling nor rule 3's counts
     * measure. One list, read by both: when they were separate the sibling
     * entry lived inline in rule 3 while rule 2 filtered nothing, and the only
     * way that shows up is a ceiling that rises while a reason is being
     * written down.
     */
    private const array GATE_FILES = [
        'tests/Architecture/NameOwnershipContractTest.php',
        'tests/Architecture/MetricRegistryResolutionTest.php',
        // Task 2.7's CSS gate. It needs the theme names to measure ownership
        // and a handful of constants to hold its exceptions; counting those
        // would let the next theme edit move a ceiling that exists to measure
        // PRODUCTION spellings. Rule 1 still reads this file.
        'tests/Theme/ThemeClassOwnershipTest.php',
    ];

    /**
     * Rule 2 — measured 2026-10-17 on the tracked scope. A ceiling, not a list.
     *
     * Was 533, then 532 after `AdminLayout::markCssLinks()` started reading
     * `ThemeResolver::ADMIN_THEME` instead of `'moonshine'` and moving
     * `AdminRoutePolicy` and `Permissions` into `Modules\Core\Domain` deleted
     * Cardboard's local copy of the admin permission table.
     *
     * Then 514, and that is work rather than an accounting change: task 2.2
     * made `QuestionsAdminAction` read `Question::STATUS_*` in both count maps
     * and `CommentsMetric` plus `CommentsAdminAction` read
     * `Comment::STATUS_*`, retiring 18 owned literals.
     *
     * Now 502: task 0.3 removed the two `ADMIN_PATH` constants and the three
     * call sites that retyped `'/mark'` beside them. `AdminRoutePolicy` still
     * declares the default (`DEFAULT_PREFIX`), so the value stays owned and
     * the 31 remaining `/mark` literals are still counted — the gate must not
     * lose sight of a value just because its owner became configurable.
     * The number is measured, the direction is down, and the gate files' own
     * literals (the exemption reasons) are excluded from it.
     */
    private const int MAX_LITERAL_PLACES = 502;

    /**
     * Rule 3 — measured 2026-10-17 on the tracked scope.
     *
     * 241 → 242 for `ModuleTreeState::IGNORED`, the list of directory prefixes
     * excluded from a drift comparison; then down to 240 when the gate files
     * left the count. Then 239: the theme gate's `UNPREFIXED_ALLOWED`
     * list was deleted when PHPStan showed it was an empty array no value
     * could ever match. Removing a constant that guarded nothing is the same
     * direction this ratchet exists to enforce.
     *
     * Now 238: task 0.3 deleted `ADMIN_PATH` from the Settings provider (and
     * its installed slice) and `AdminRoutePolicy` added `DEFAULT_PREFIX` in
     * its place — one default for one panel, not two spellings of one name.
     */
    private const int MAX_DECLARATIONS = 238;

    /**
     * 203 → 204 with MAX_DECLARATIONS: `ModuleTreeState::IGNORED` is a name as
     * well as a declaration, and both ceilings moved for the same one constant.
     * Then 202: deleting `UNPREFIXED_ALLOWED` took a name with it. It stays 202
     * after task 0.3 — `ADMIN_PATH` left and `DEFAULT_PREFIX` arrived, so the
     * declaration count fell while the distinct-name count did not.
     */
    private const int MAX_NAMES = 202;

    /**
     * Rule 1. A value is the thing two owners disagree about, so the assertion
     * is about values, not about declaration sites — and it holds only for
     * strings. `60` is claimed by nine names across nine different meanings
     * (a timeout, a page size, a row limit); that is arithmetic coincidence,
     * not a second owner, and a gate reporting it would teach people to ignore
     * the gate.
     */
    #[Test]
    public function aStringValueIsClaimedByAtMostOneName(): void
    {
        /** Claimed string value => constant name => the places that name it. */
        $claims = [];

        foreach ($this->declarations() as $declaration) {
            if (null === $declaration['value'] || !$this->isQuotedString($declaration['value'])) {
                continue;
            }

            $claims[$declaration['value']][$declaration['name']][] = $declaration['file']
                . ':' . $declaration['line'];
        }

        $unreported = [];

        foreach ($claims as $value => $names) {
            if (count($names) < 2) {
                continue;
            }

            if (isset(self::MULTI_NAME_EXEMPTIONS[$value])) {
                continue;
            }

            $sites = [];

            foreach ($names as $name => $where) {
                $sites[] = $name . ' (' . implode(', ', $where) . ')';
            }

            $unreported[$value] = implode(' | ', $sites);
        }

        self::assertSame(
            [],
            $unreported,
            "A value with two names has two owners, and nothing reports the rename:\n"
            . implode("\n", array_map(
                static fn (string $value, string $names): string => sprintf('  %-20s <- %s', $value, $names),
                array_keys($unreported),
                $unreported,
            ))
            . "\nEither delete one name or record the exemption with a reason in"
            . ' self::MULTI_NAME_EXEMPTIONS. A new constant is not a fix: it moves the'
            . ' collision and leaves three declarations where two had one.',
        );

        // An exemption that no longer applies is the other half of the same
        // defect: the list stops describing the tree, so nobody can trust it.
        $stale = array_diff(array_keys(self::MULTI_NAME_EXEMPTIONS), $this->multiNameValues($claims));

        self::assertSame(
            [],
            array_values($stale),
            'self::MULTI_NAME_EXEMPTIONS lists a value that no longer has two names.'
            . ' An exemption that stopped applying is rot in the ledger, not a'
            . ' free pass.',
        );
    }

    /**
     * Rule 2, as a ceiling.
     *
     * @param array<string, array<string, list<string>>> $claims
     *
     * @return list<string>
     */
    private function multiNameValues(array $claims): array
    {
        $found = [];

        foreach ($claims as $value => $names) {
            if (count($names) > 1) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /**
     * Rule 2. Every place an owned string value is spelled out as a literal
     * somewhere it does not own. The ceiling is what makes this a gate rather
     * than a wish: 502 today, and the only legal direction is down.
     */
    #[Test]
    public function ownedValuesAreNotSpelledAsLiteralsMoreOftenThanTheCeiling(): void
    {
        [$places] = $this->literalPlaces();

        self::assertLessThanOrEqual(
            self::MAX_LITERAL_PLACES,
            $places,
            sprintf(
                '%d places spell an owned value as a raw literal; the ceiling is %d.'
                . ' Every consolidation lowers this, so a rise means a new duplicate.'
                . "\nThe largest single source is `Locales/…/SeedData.php`, where literals are"
                . ' correct: seed data is not a constant, and making it one would be worse.',
                $places,
                self::MAX_LITERAL_PLACES,
            ),
        );
    }

    /**
     * Rule 3. The ratchet that catches the cheapest wrong fix.
     */
    #[Test]
    public function theDeclarationCountDoesNotRise(): void
    {
        // A ruler does not measure itself. This file holds the ceilings, so
        // counting its own constants would mean the gate's next edit — including
        // its own approval list — moves the number it is meant to hold. Rule 1
        // still reads it, so a duplicated value in a gate is still caught.
        //
        // The same exclusion covers every other file in this directory, and that
        // is a deliberate widening rather than a convenience: a gate that exempts
        // only itself can be outgrown by adding a constant to a sibling test.
        // `MetricRegistryResolutionTest` did exactly that on the day it was
        // written — one `REGISTRY` constant took the count from 241 to 242, and
        // the honest fix was to exempt the gates, not to raise the ceiling.
        $declarations = array_values(array_filter(
            $this->declarations(),
            fn (array $declaration): bool => !in_array($declaration['file'], self::GATE_FILES, true),
        ));

        $count = count($declarations);
        $names = count(array_unique(array_column($declarations, 'name')));

        self::assertLessThanOrEqual(
            self::MAX_DECLARATIONS,
            $count,
            sprintf(
                'const declarations rose above %d (now %d). If a duplicate was fixed by'
                . ' adding a constant, the fix moved the problem and the tree grew.',
                self::MAX_DECLARATIONS,
                $count,
            ),
        );

        self::assertLessThanOrEqual(
            self::MAX_NAMES,
            $names,
            sprintf(
                'distinct const names rose above %d (now %d). A new name for an existing'
                . ' value is the same defect wearing a different hat.',
                self::MAX_NAMES,
                $names,
            ),
        );
    }

    /**
     * Owner-keyed literal count. Kept separate from rule 1 so the failure text
     * can say which number moved.
     *
     * @return array{int, array<string, list<string>>}
     */
    private function literalPlaces(): array
    {
        $owners = [];

        foreach ($this->declarations() as $declaration) {
            // Rule 1 reads the gates; the ceiling does not. A gate constant
            // naming its own subject (`KERNEL_VOCABULARY = 'st'`) would
            // otherwise turn every production default argument into a literal
            // owned by the gate, and raise the ceiling for measuring the tree.
            if (in_array($declaration['file'], self::GATE_FILES, true)) {
                continue;
            }

            if (null === $declaration['value'] || !$this->isQuotedString($declaration['value'])) {
                continue;
            }

            $owners[$declaration['value']][$this->ownerKey($declaration['file'])] = true;
        }

        $places = 0;
        $where = [];

        foreach ($this->files() as $file) {
            $relative = $this->relative($file);

            // The gates are excluded from the CEILING, not from rule 1. The
            // remaining entries in MULTI_NAME_EXEMPTIONS are necessarily
            // string literals spelled in this file, and a ceiling that the
            // ledger's own reasons inflate can never fall again. Recording a
            // reason is what makes a collision tolerated; counting the
            // tolerance as duplication is a ratchet that tightens itself every
            // time someone writes down WHY. Rule 1 still reads these files,
            // so a duplicated value declared by a gate is still caught.
            if (in_array($relative, self::GATE_FILES, true)) {
                continue;
            }

            $owner = $this->ownerKey($relative);

            foreach ($this->stringLiterals($file) as $literal) {
                if (!isset($owners[$literal]) || isset($owners[$literal][$owner])) {
                    continue;
                }

                $places++;
                $where[$relative][] = $literal;
            }
        }

        return [$places, $where];
    }

    /**
     * Catalogue entry and installed slice are the same text twice: without
     * this, every constant in `src/Modules/X` reads as a second site for
     * `modules/X` and the ceiling doubles for no reason.
     */
    private function ownerKey(string $file): string
    {
        if (str_starts_with($file, 'src/Modules/')) {
            return 'modules/' . substr($file, strlen('src/Modules/'));
        }

        return $file;
    }

    /** @return list<string> */
    private function files(): array
    {
        $found = [];

        foreach (self::SCOPE as $relative) {
            $base = APP_BASE_PATH . '/' . $relative;

            if (!is_dir($base)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }

                if (!$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }

                $path = $file->getPathname();

                // Build output: copied bytes from the frontend, not declarations.
                if (str_starts_with($path, APP_BASE_PATH . '/public/dist/')) {
                    continue;
                }

                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(APP_BASE_PATH) + 1);
    }

    /**
     * Every string literal in a file, flat.
     *
     * Not keyed by line: `assertSame('x', $row['y'])` puts two literals on one
     * line, and keying by line let the second overwrite the first — dropping
     * precisely the values a test asserts, which are the ones rule 2 is about.
     *
     * @return list<string>
     */
    private function stringLiterals(string $file): array
    {
        $out = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)
                && T_CONSTANT_ENCAPSED_STRING === $token[0]
                && $this->isQuotedString($token[1])
            ) {
                $out[] = $token[1];
            }
        }

        return $out;
    }

    /**
     * Tokens that can form part of a constant's type. `array` is T_ARRAY and
     * `static` is T_STATIC, so a T_STRING-only list loses every `const array`.
     *
     * @var list<int>
     */
    private const array TYPE_TOKENS = [
        T_STRING,
        T_ARRAY,
        T_CALLABLE,
        T_STATIC,
        T_NS_SEPARATOR,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
    ];

    /**
     * Every `const NAME = …` declaration in the scope, typed ones included.
     *
     * @return list<array{file: string, name: string, value: ?string, line: int}>
     */
    private function declarations(): array
    {
        $found = [];

        foreach ($this->files() as $file) {
            foreach ($this->declarationsIn($file) as $declaration) {
                $declaration['file'] = $this->relative($file);
                $found[] = $declaration;
            }
        }

        return $found;
    }

    /**
     * @return list<array{file: string, name: string, value: ?string, line: int}>
     */
    private function declarationsIn(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || T_CONST !== $tokens[$i][0]) {
                continue;
            }

            $nameIndex = $this->nameIndexAfterConst($tokens, $count, $i);

            if (null === $nameIndex) {
                continue;
            }

            $assign = $this->skipSpace($tokens, $count, $nameIndex + 1);

            if ('=' !== ($tokens[$assign] ?? null)) {
                continue;
            }

            $value = $this->literalValue($tokens, $count, $assign + 1);

            $found[] = [
                'file' => '',
                'name' => $tokens[$nameIndex][1],
                'value' => $value,
                'line' => $tokens[$i][2],
            ];
        }

        return $found;
    }

    /**
     * The index of the constant's NAME, whether or not a type precedes it.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return int|null
     */
    private function nameIndexAfterConst(array $tokens, int $count, int $constIndex): ?int
    {
        $cursor = $this->skipSpace($tokens, $count, $constIndex + 1);
        $start = $cursor;

        while ($cursor < $count) {
            $token = $tokens[$cursor];

            if (!is_array($token)) {
                // `?` and `|` are bare characters, not tokens.
                if ('?' === $token || '|' === $token) {
                    $cursor = $this->skipSpace($tokens, $count, $cursor + 1);

                    continue;
                }

                break;
            }

            if (!in_array($token[0], self::TYPE_TOKENS, true)) {
                break;
            }

            $next = $this->skipSpace($tokens, $count, $cursor + 1);
            $after = $tokens[$next] ?? null;

            if (is_array($after)
                && T_STRING === $after[0]
                && 1 === preg_match('/^[A-Z][A-Z0-9_]*$/', $after[1])
            ) {
                return $next;
            }

            if (is_array($after) && in_array($after[0], self::TYPE_TOKENS, true)) {
                $cursor = $next;

                continue;
            }

            // A type token with nothing type-like or name-like after it was
            // the name all along — this is the untyped `const NAME = …` case.
            return $cursor;
        }

        if ($start === $cursor) {
            $token = $tokens[$cursor] ?? null;

            if (is_array($token)
                && T_STRING === $token[0]
                && 1 === preg_match('/^[A-Z][A-Z0-9_]*$/', $token[1])
            ) {
                return $cursor;
            }
        }

        return null;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private function skipSpace(array $tokens, int $count, int $from): int
    {
        while ($from < $count && is_array($tokens[$from]) && T_WHITESPACE === $tokens[$from][0]) {
            $from++;
        }

        return $from;
    }

    /**
     * A single scalar literal, or null for anything computed.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function literalValue(array $tokens, int $count, int $from): ?string
    {
        $index = $this->skipSpace($tokens, $count, $from);
        $token = $tokens[$index] ?? null;

        if (!is_array($token)) {
            return null;
        }

        return match ($token[0]) {
            T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER => $token[1],
            default => null,
        };
    }

    /** Not `isString()`: that name is a final method on PHPUnit's Assert. */
    private function isQuotedString(string $raw): bool
    {
        return str_starts_with($raw, "'") || str_starts_with($raw, '"');
    }
}
