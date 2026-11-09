<?php

declare(strict_types = 1);

/*
 * export-core-verify.php — the structural half of `export-core.sh --check`.
 *
 * Everything here is a fact about the EXPORT that the export itself can answer:
 * a required path is present, a pruned path is absent, the registry and the
 * Makefile agree, every recipe path exists, no surface names a pruned thing,
 * and the marker is well formed. The behavioural half (lint, composer validate,
 * PHPUnit) runs in the shell after this.
 *
 * PHP, not a second language: the tree's scripts are bash and PHP, and a gate
 * that needs a third runtime is a gate the next operator cannot run.
 *
 * Usage:
 *   php bin/ops/export-core-verify.php --root=<export> --source-makefile=<path>
 */

function fail(string $message): never
{
    fwrite(STDERR, 'export-core-verify: ' . $message . PHP_EOL);
    exit(1);
}

/** @return array<string, string> */
function options(array $argv): array
{
    $options = [];

    foreach ($argv as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $pair = explode('=', substr($argument, 2), 2);

        if (2 !== count($pair)) {
            fail(sprintf('option --%s needs a value', $pair[0]));
        }

        $options[$pair[0]] = $pair[1];
    }

    foreach (['root', 'source-makefile'] as $required) {
        if (!isset($options[$required]) || '' === $options[$required]) {
            fail(sprintf('missing --%s=<path>', $required));
        }
    }

    return $options;
}

/** @return list<string> */
function makeTargets(string $makefile): array
{
    $contents = (string) @file_get_contents($makefile);

    if ('' === $contents) {
        fail(sprintf('cannot read %s', $makefile));
    }

    preg_match_all('/^([a-z][a-z0-9-]*):.*##/m', $contents, $matched);

    $targets = array_values(array_unique($matched[1]));
    sort($targets);

    return $targets;
}

$options = options(array_slice($argv, 1));
$root = rtrim($options['root'], '/');
$problems = [];

$required = [
    'src/Modules/Core',
    'src/Modules/Database',
    'src/Modules/Tooling',
    'src/Themes/kernel',
    'modules',
    'Frontend/packages',
    'public/index.php',
    'bin/cms',
    'config',
    'tests',
    'LICENSE',
    '.github/workflows/ci.yml',
    '.nativa-core-export.json',
];

foreach ($required as $path) {
    if (!file_exists($root . '/' . $path)) {
        $problems[] = 'missing from the export: ' . $path;
    }
}

$pruned = [
    'docs',
    'modules/Docs',
    'lekalo',
    'lekalo.lock',
    'openspec',
    'storage/blog',
    'storage/pages',
    'bin/export',
    'bin/build/extract-kernel.sh',
    'bin/build/sync-themes.sh',
    'CONTRIBUTING.md',
    '.ai-factory',
];

foreach ($pruned as $path) {
    if (file_exists($root . '/' . $path)) {
        $problems[] = 'still present in the export: ' . $path;
    }
}

$makefile = (string) file_get_contents($root . '/Makefile');
$workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
$targets = makeTargets($root . '/Makefile');

preg_match_all('/\bmake\s+([a-z][a-z0-9-]+)/', $workflow, $calls);
$unknown = array_values(array_diff(array_unique($calls[1]), $targets));
sort($unknown);

if ([] !== $unknown) {
    $problems[] = 'workflow calls targets the export does not have: ' . implode(', ', $unknown);
}

preg_match_all('#bin/[A-Za-z0-9._/-]+#', $makefile, $paths);

foreach (array_unique($paths[0]) as $path) {
    $path = rtrim($path, '/.');

    if ('' !== $path && !file_exists($root . '/' . $path)) {
        $problems[] = 'exported recipe names a missing path: ' . $path;
    }
}

foreach (['lekalo', 'openspec', 'nativa-minimal', 'nativa-themes', 'kernel-status', 'extract-kernel', 'sync-themes'] as $token) {
    if (str_contains($makefile, $token)) {
        $problems[] = 'exported Makefile names a pruned thing: ' . $token;
    }
}

foreach (['lekalo', 'openspec', 'nativa-minimal', 'nativa-themes'] as $token) {
    if (str_contains($workflow, $token)) {
        $problems[] = 'exported workflow names a pruned thing: ' . $token;
    }
}

$registry = json_decode((string) file_get_contents($root . '/toolchain.json'), true);

if (!is_array($registry) || !isset($registry['gates'], $registry['tools'])) {
    fail('the exported toolchain.json is not a registry');
}

$registered = array_column($registry['gates'], 'target');
sort($registered);

if ($registered !== $targets) {
    $problems[] = sprintf(
        'registry and Makefile disagree. only in Makefile: %s; only in registry: %s',
        [] === array_diff($targets, $registered) ? '(none)' : implode(', ', array_diff($targets, $registered)),
        [] === array_diff($registered, $targets) ? '(none)' : implode(', ', array_diff($registered, $targets)),
    );
}

foreach ($registry['tools'] as $tool) {
    foreach ($tool['needles']['files'] as $file) {
        if (!file_exists($root . '/' . $file)) {
            $problems[] = sprintf('registry tool %s names a missing file: %s', (string) $tool['name'], (string) $file);
        }
    }
}

$sourceTargets = makeTargets($options['source-makefile']);
$notInSource = array_values(array_diff($targets, $sourceTargets));

if ([] !== $notInSource) {
    $problems[] = 'the profile lists targets the source Makefile does not have: ' . implode(', ', $notInSource);
}

$marker = json_decode((string) @file_get_contents($root . '/.nativa-core-export.json'), true);

if (!is_array($marker) || 1 !== ($marker['format'] ?? null) || 1 !== preg_match('/^[0-9a-f]{40}$/', (string) ($marker['commit'] ?? ''))) {
    $problems[] = 'the export marker is malformed';
}

if ([] !== $problems) {
    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . PHP_EOL);
    }

    exit(1);
}

printf(
    "    %d target(s), %d gate(s), %d tool(s), source %s\n",
    count($targets),
    count($registry['gates']),
    count($registry['tools']),
    substr((string) $marker['commit'], 0, 7),
);
