#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * lekalo-reports.php — the two advisory Lekalo reports, as markdown for a CI
 * job summary.
 *
 * Why this exists instead of one inline step per surface: a surface is adopted
 * when a command has been run against THIS model and exited 0, and the raw
 * output of neither command fits a summary — `coupling --module users` is 217
 * lines of per-subject fan-in/fan-out and `architecture-profile assess` is 15
 * rules wide. A job summary is read by someone deciding whether to click
 * through, so it wants the shape and not the transcript; the full text stays
 * one command away.
 *
 * Both surfaces are INFORMATIONAL and this script keeps them that way.
 * `coupling` reports `coupling.evidence-incomplete` on every module today and
 * `architecture-profile assess` is `advisory` with 14 of 15 rules without
 * evidence, because this project installs no adapter package. A step that
 * failed on a permanent, understood condition is a step people learn to
 * ignore — the same reasoning the `verify` step records. The reason codes are
 * PRINTED rather than filtered, so the reader sees the advisory state instead
 * of an unexplained green row.
 *
 * What it does NOT tolerate is a command that cannot produce a report at all:
 * a crash is not an advisory state, so a non-zero exit or an unparseable
 * document is named in the summary AND exits 1.
 *
 * Usage:
 *   php bin/ops/lekalo-reports.php --lekalo="$HOME/.cargo/bin/lekalo" \
 *     [--modules=users,blog]
 *
 * Invoked through `make lekalo-reports` (and therefore from the CI `lekalo`
 * job, which appends the output to `$GITHUB_STEP_SUMMARY`).
 */

/** The modules reported when `--modules` is not given. Two is the floor this
 * report is worth reading at: one module says nothing about whether the shape
 * holds for a model that is not that module, and the surfaces were adopted on
 * the strength of `--module users` alone. */
const LEKALO_REPORT_MODULES = ['users', 'blog'];

$options = lekaloReportOptions($argv);

if ([] === $options['modules']) {
    fwrite(STDERR, "lekalo-reports: --modules must name at least one module\n");
    exit(1);
}

$binary = lekaloResolveBinary($options['lekalo']);

if (null === $binary) {
    fwrite(STDERR, "lekalo-reports: no executable lekalo CLI at '{$options['lekalo']}'\n");
    exit(1);
}

$coupling = [];
$architecture = [];
$failed = false;

foreach ($options['modules'] as $module) {
    [$row, $codes, $ok] = lekaloCouplingRow($binary, $module);
    $coupling[$module] = ['row' => $row, 'codes' => $codes];
    $failed = $failed || !$ok;

    [$row, $observed, $ok] = lekaloArchitectureRow($binary, $module);
    $architecture[$module] = ['row' => $row, 'observed' => $observed];
    $failed = $failed || !$ok;
}

echo "## Lekalo coupling (advisory)\n\n";
echo "`coupling-metrics/1` — per-subject fan-in / fan-out / public radius over the\n";
echo "named module roots. Full report: `lekalo coupling --module <module>`.\n\n";
echo "| Module | Subjects | Public contracts | Supporting | Internal | Cycles | Findings |\n";
echo "|---|---:|---:|---:|---:|---:|---:|\n";

foreach ($coupling as $entry) {
    echo $entry['row'];
}

echo "\n";
echo lekaloSharedCodes($coupling);

echo "\n## Lekalo architecture profile (advisory)\n\n";
echo "`architecture-core/1` — which rules the catalog offers, how many have current\n";
echo "evidence, and what the module is admitted to be. Full report:\n";
echo "`lekalo architecture-profile assess --module <module>`.\n\n";
echo "| Module | Assessment | Rules complete | Rules without evidence | Required observed |\n";
echo "|---|---|---:|---:|---:|\n";

foreach ($architecture as $entry) {
    echo $entry['row'];
}

echo "\n";
echo lekaloObservedRules($architecture);

exit($failed ? 1 : 0);

/**
 * The executable to run, or null when there is none.
 *
 * Resolved once, before any module is reported, for two reasons: a missing CLI
 * is ONE condition and not two-per-module, and letting `proc_open` discover it
 * produces a PHP warning with a stack trace in the middle of CI output — noise
 * about a condition this script is perfectly capable of naming. A bare name
 * (the `LEKALO` default) is searched on PATH; anything with a slash is used as
 * given.
 */
function lekaloResolveBinary(string $candidate): ?string
{
    if (!str_contains($candidate, '/')) {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ('' !== $directory && is_executable($directory . '/' . $candidate)) {
                return $directory . '/' . $candidate;
            }
        }

        return null;
    }

    return is_executable($candidate) ? $candidate : null;
}

/**
 * The parsed command line.
 *
 * @param list<string> $argv
 *
 * @return array{lekalo: string, modules: list<string>}
 */
function lekaloReportOptions(array $argv): array
{
    $lekalo = 'lekalo';
    $modules = LEKALO_REPORT_MODULES;

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--lekalo=')) {
            $lekalo = substr($argument, strlen('--lekalo='));

            continue;
        }

        if (str_starts_with($argument, '--modules=')) {
            $modules = array_values(array_filter(
                array_map(trim(...), explode(',', substr($argument, strlen('--modules=')))),
                static fn (string $name): bool => '' !== $name,
            ));

            continue;
        }

        fwrite(STDERR, "lekalo-reports: unknown argument: {$argument}\n");
        exit(1);
    }

    return ['lekalo' => $lekalo, 'modules' => $modules];
}

/**
 * The advisory codes, with the message behind each one.
 *
 * The code alone is half-useful: `coupling.shared-mutable-state` names a real
 * architectural signal, and a summary that prints the identifier without saying
 * what it means hands the reader a lookup. The message comes from the report's
 * own diagnostics, so it cannot drift from what the tool meant.
 *
 * Stated once when every module carries the same set, per module when they
 * differ. Printing them at all is the point: a summary that hides the code
 * teaches the reader that a green row means a clean report, which is the
 * misreading this surface invites.
 *
 * @param array<string, array{row: string, codes: list<array{id: string, code: string, message: string}>}> $coupling
 */
function lekaloSharedCodes(array $coupling): string
{
    $shared = null;
    $uniform = true;
    $any = false;

    foreach ($coupling as $entry) {
        $any = $any || [] !== $entry['codes'];

        if (null === $shared) {
            $shared = $entry['codes'];

            continue;
        }

        if ($shared !== $entry['codes']) {
            $uniform = false;
        }
    }

    if (!$any) {
        return "Advisory codes: none reported.\n";
    }

    if ($uniform) {
        $count = count($coupling);
        $lines = 'Advisory codes on ' . ($count > 1 ? "all {$count} modules" : 'the module') . ":\n";

        return $lines . lekaloCodeBullets($shared ?? []);
    }

    $lines = "Advisory codes:\n";

    foreach ($coupling as $module => $entry) {
        $ids = array_map(static fn (array $code): string => $code['id'], $entry['codes']);
        $lines .= '- `' . $module . '`: `' . implode('`, `', $ids) . "`\n";
    }

    return $lines;
}

/**
 * One bullet per advisory code, naming it and quoting what the tool said.
 *
 * @param list<array{id: string, code: string, message: string}> $codes
 */
function lekaloCodeBullets(array $codes): string
{
    $lines = '';

    foreach ($codes as $code) {
        $lines .= '- `' . $code['id'] . '` (`' . $code['code'] . '`): ' . lekaloTrim($code['message']) . "\n";
    }

    return $lines;
}

/**
 * A diagnostic message, short enough not to bury the table above it.
 */
function lekaloTrim(string $message): string
{
    return strlen($message) > 160 ? rtrim(substr($message, 0, 157)) . '…' : $message;
}

/**
 * Which required rule actually carries the admission, per module — the one row
 * out of fifteen that has evidence.
 *
 * @param array<string, array{row: string, observed: list<string>}> $architecture
 */
function lekaloObservedRules(array $architecture): string
{
    $lines = '';

    foreach ($architecture as $module => $entry) {
        if ([] === $entry['observed']) {
            continue;
        }

        $lines .= '- `' . $module . '`: ' . implode('; ', $entry['observed']) . "\n";
    }

    if ('' === $lines) {
        return "No required rule is observed on any reported module.\n";
    }

    return "Required rules carrying the admission:\n" . $lines;
}

/**
 * One coupling table row, plus the advisory codes it carries and whether the
 * report was readable at all.
 *
 * @return array{0: string, 1: list<array{id: string, code: string, message: string}>, 2: bool}
 */
function lekaloCouplingRow(string $binary, string $module): array
{
    $result = lekaloRun($binary, ['--json', 'coupling', '--module', $module]);
    $envelope = lekaloJson($result, "coupling --module {$module}");

    if (null === $envelope) {
        return [lekaloFailedRow($module, $result['exit'], 7), [], false];
    }

    $report = $envelope['coupling'] ?? null;

    if (!is_array($report)) {
        return [lekaloFailedRow($module, 1, 7), [], false];
    }

    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

    $row = sprintf(
        "| `%s` | %d | %d | %d | %d | %d | %d |\n",
        $module,
        lekaloScopedSubjects($report['subjects'] ?? null, $module),
        lekaloCount($summary, 'publicContracts'),
        lekaloCount($summary, 'supportingSymbols'),
        lekaloCount($summary, 'internalSymbols'),
        lekaloListCount($report['cycles'] ?? null),
        lekaloListCount($report['findings'] ?? null),
    );

    return [$row, lekaloReasonCodes($envelope), true];
}

/**
 * One architecture-profile table row.
 *
 * @return array{0: string, 1: list<string>, 2: bool}
 */
function lekaloArchitectureRow(string $binary, string $module): array
{
    $result = lekaloRun($binary, ['architecture-profile', 'assess', '--module', $module]);
    $document = lekaloJson($result, "architecture-profile assess --module {$module}");

    if (null === $document) {
        return [lekaloFailedRow($module, $result['exit'], 5), [], false];
    }

    $rows = is_array($document['rows'] ?? null) ? $document['rows'] : [];
    $complete = 0;
    $withoutEvidence = 0;
    $observed = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ('complete' === ($row['coverage'] ?? null)) {
            $complete++;
        } else {
            $withoutEvidence++;
        }

        if (true === ($row['required'] ?? null) && 'observed' === ($row['disposition'] ?? null)) {
            $observed[] = sprintf('`%s` observed at %s', (string) ($row['ruleId'] ?? '?'), lekaloValue($row));
        }
    }

    $row = sprintf(
        "| `%s` | %s | %d | %d | %d |\n",
        $module,
        (string) ($document['assessment'] ?? 'unknown'),
        $complete,
        $withoutEvidence,
        count($observed),
    );

    return [$row, $observed, true];
}

/**
 * The measured value behind an observed rule, as a string.
 *
 * @param array<string, mixed> $row
 */
function lekaloValue(array $row): string
{
    $value = is_array($row['value'] ?? null) ? $row['value'] : [];
    $measured = $value['value'] ?? null;

    if (is_int($measured) || is_string($measured)) {
        return (string) $measured;
    }

    return 'n/a';
}

/**
 * The row a summary gets when a command could not be read: the reason inline,
 * where the reader is already looking, instead of only in the log.
 */
function lekaloFailedRow(string $module, int $exit, int $columns): string
{
    $cells = array_fill(0, $columns - 2, '');

    return '| `' . $module . '` | ' . implode(' | ', $cells) . ' | **failed** (exit ' . $exit . ") |\n";
}

/**
 * Run the CLI and hand back its exit code and captured output.
 *
 * The command is passed as an argument VECTOR, never a shell string: a module
 * name reaches this function from the command line, and `proc_open` with an
 * array does not give it a shell to be interesting in.
 *
 * @param list<string> $args
 *
 * @return array{exit: int, output: string}
 */
function lekaloRun(string $binary, array $args): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    // Suppressed deliberately, and not swallowed: the binary was checked before
    // the loop, so a failure HERE is a race (it disappeared mid-run) and the
    // return value below reports it as exit 127. What the suppression removes
    // is a PHP warning plus stack trace for a condition we already name.
    $process = @proc_open(array_merge([$binary], $args), $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['exit' => 127, 'output' => ''];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $stdout = false === $stdout ? '' : $stdout;
    $stderr = false === $stderr ? '' : $stderr;

    return ['exit' => $exit, 'output' => '' !== $stdout ? $stdout : $stderr];
}

/**
 * Decode a JSON object, or null when the command failed or printed something
 * that is not an object.
 *
 * @param array{exit: int, output: string} $result
 *
 * @return array<string, mixed>|null
 */
function lekaloJson(array $result, string $label): ?array
{
    if (0 !== $result['exit']) {
        fwrite(STDERR, "lekalo-reports: {$label} exited {$result['exit']}\n");

        return null;
    }

    $decoded = json_decode($result['output'], true);

    if (!is_array($decoded)) {
        fwrite(STDERR, "lekalo-reports: {$label} did not print a JSON object\n");

        return null;
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * The distinct advisory codes a report carries — the honest half of an advisory
 * verdict, surfaced rather than left in the log — each with the stable `LEK-…`
 * diagnostic code and the message the tool attached to it.
 *
 * Read from `diagnostics`, keyed on the message id, so the same id reported for
 * fifty symbols is one bullet rather than fifty.
 *
 * @param array<string, mixed> $envelope
 *
 * @return list<array{id: string, code: string, message: string}>
 */
function lekaloReasonCodes(array $envelope): array
{
    $diagnostics = is_array($envelope['diagnostics'] ?? null) ? $envelope['diagnostics'] : [];
    $byId = [];

    foreach ($diagnostics as $diagnostic) {
        if (!is_array($diagnostic)) {
            continue;
        }

        $id = $diagnostic['message_id'] ?? $diagnostic['id'] ?? null;

        if (!is_string($id) || '' === $id || isset($byId[$id])) {
            continue;
        }

        $byId[$id] = [
            'id' => $id,
            'code' => is_string($diagnostic['code'] ?? null) ? $diagnostic['code'] : '',
            'message' => is_string($diagnostic['message'] ?? null) ? $diagnostic['message'] : '',
        ];
    }

    ksort($byId);

    return array_values($byId);
}

/**
 * How many subjects the report lists for one module.
 *
 * The report's OTHER counts are project-wide on purpose (a module's impact
 * reaches other modules' endpoints through shared supporting symbols), so the
 * subject count is the one column that is genuinely per-module and it is
 * derived from the subjects themselves rather than from a summary total.
 *
 * @param mixed $subjects
 */
function lekaloScopedSubjects(mixed $subjects, string $module): int
{
    if (!is_array($subjects)) {
        return 0;
    }

    $scoped = 0;

    foreach ($subjects as $subject) {
        if (is_array($subject) && ($subject['module'] ?? null) === $module) {
            $scoped++;
        }
    }

    return $scoped;
}

/**
 * How many entries a summary block holds, without assuming it is a list.
 *
 * @param array<string, mixed> $summary
 */
function lekaloCount(array $summary, string $key): int
{
    return lekaloListCount($summary[$key] ?? null);
}

/**
 * @param mixed $value
 */
function lekaloListCount(mixed $value): int
{
    return is_array($value) ? count($value) : 0;
}
