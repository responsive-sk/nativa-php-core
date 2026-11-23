<?php

declare(strict_types = 1);

/*
 * export-core-verify.php — the structural half of `export-core.sh --check`.
 *
 * Everything here is a fact about the EXPORT that the export itself can answer:
 * a required path is present, a pruned path is absent, the profile's modules
 * are installed, the generated deploy identity matches the profile, the
 * registry and the Makefile agree, every recipe path exists, no surface names a
 * pruned thing, and the marker is well formed. The behavioural half (lint,
 * composer validate, PHPUnit) runs in the shell after this.
 *
 * The profile is passed as a DIRECTORY, not read from the export: both profiles
 * prune `bin/export` so the published tree has no copy of the facts it was
 * built from. `export-core.sh` passes its staged copy.
 *
 * PHP, not a second language: the tree's scripts are bash and PHP, and a gate
 * that needs a third runtime is a gate the next operator cannot run.
 *
 * Usage:
 *   php bin/ops/export-core-verify.php \
 *       --root=<export> --source-makefile=<path> --profile=<path>
 *
 * `--profile-dir` is accepted as the older spelling of the same directory.
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

    if (!isset($options['profile-dir']) || '' === $options['profile-dir']) {
        if (!isset($options['profile']) || '' === $options['profile']) {
            fail('missing --profile=<path> (or --profile-dir=<path>)');
        }

        $options['profile-dir'] = $options['profile'];
    }

    return $options;
}

/** @return array<string, mixed> */
function profile(string $directory): array
{
    $path = rtrim($directory, '/') . '/profile.json';
    $decoded = json_decode((string) @file_get_contents($path), true);

    if (!is_array($decoded)) {
        fail(sprintf('cannot read the profile at %s', $path));
    }

    foreach (['slug', 'source', 'marker', 'required_paths', 'pruned_paths', 'deploy'] as $key) {
        if (!array_key_exists($key, $decoded)) {
            fail(sprintf('the profile has no %s', $key));
        }
    }

    if (!is_string($decoded['marker']) || '' === $decoded['marker']) {
        fail('the profile marker is empty');
    }

    if (!is_array($decoded['required_paths']) || !is_array($decoded['pruned_paths'])) {
        fail('the profile required_paths or pruned_paths is not a list');
    }

    if (!is_array($decoded['deploy']) || '' === (string) ($decoded['deploy']['theme'] ?? '')) {
        fail('the profile has no deploy.theme');
    }

    return $decoded;
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
$profile = profile($options['profile-dir']);
$problems = [];

$required = array_map(strval(...), $profile['required_paths']);
$markerName = (string) $profile['marker'];
$required[] = $markerName;

foreach (array_unique($required) as $path) {
    if (!file_exists($root . '/' . $path)) {
        $problems[] = 'missing from the export: ' . $path;
    }
}

foreach (array_map(strval(...), $profile['pruned_paths']) as $path) {
    if (file_exists($root . '/' . $path)) {
        $problems[] = 'still present in the export: ' . $path;
    }
}

$modules = isset($profile['modules']) && is_array($profile['modules'])
    ? array_map(strval(...), $profile['modules'])
    : [];
$installModules = true === ($profile['install_modules'] ?? false);

if ($installModules && [] === $modules) {
    $problems[] = 'install_modules is true but the profile names no modules';
}

foreach ($modules as $module) {
    if (!is_dir($root . '/src/Modules/' . $module) && !is_dir($root . '/src/Modules/Themes/' . $module)) {
        $problems[] = 'the profile module is not installed: ' . $module;
    }

    if (!is_dir($root . '/modules/' . $module) && !is_dir($root . '/modules/Themes/' . $module)) {
        $problems[] = 'the profile module is not catalogued: ' . $module;
    }
}

$gitignore = (string) @file_get_contents($root . '/.gitignore');

if ('' === $gitignore) {
    $problems[] = 'the export carries no .gitignore';
} elseif ($installModules) {
    if (1 === preg_match('#^/?src/Modules(?:/\*)?$#m', $gitignore)) {
        $problems[] = 'the export ignores src/Modules, but its installed slices are tracked';
    }
} elseif (!str_contains($gitignore, 'src/Modules/*')) {
    $problems[] = 'a kernel-only export must ignore src/Modules/*';
}

$deployPath = $root . '/config/deploy.php';

if (is_file($deployPath)) {
    /** @var mixed $deploy */
    $deploy = include $deployPath;

    if (!is_array($deploy)) {
        $problems[] = 'config/deploy.php did not return an array';
    } else {
        $expected = $profile['deploy'];

        foreach (['theme', 'site_name', 'site_description', 'timezone'] as $key) {
            if (array_key_exists($key, $expected) && ($deploy[$key] ?? null) !== $expected[$key]) {
                $problems[] = sprintf('config/deploy.php has the wrong %s', $key);
            }
        }

        $extra = array_diff(array_keys($deploy), array_keys($expected));

        if ([] !== $extra) {
            $problems[] = 'config/deploy.php carries keys the profile does not: ' . implode(', ', $extra);
        }
    }
}

$markerPath = $root . '/' . $markerName;
$marker = json_decode((string) @file_get_contents($markerPath), true);

if (!is_array($marker)) {
    $problems[] = 'the export marker is malformed';
} else {
    if (1 !== ($marker['format'] ?? null)) {
        $problems[] = 'the export marker has the wrong format';
    }

    if (1 !== preg_match('/^[0-9a-f]{40}$/', (string) ($marker['commit'] ?? ''))) {
        $problems[] = 'the export marker has no commit sha';
    }

    if (($marker['profile'] ?? null) !== $profile['slug']) {
        $problems[] = 'the export marker names a different profile';
    }

    if (($marker['source'] ?? null) !== $profile['source']) {
        $problems[] = 'the export marker names a different source';
    }
}

$makefile = (string) @file_get_contents($root . '/Makefile');
$workflow = (string) @file_get_contents($root . '/.github/workflows/ci.yml');
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

foreach (['lekalo', 'openspec', 'nativa-minimal', 'nativa-themes', 'kernel-status'] as $token) {
    if (str_contains($makefile, $token)) {
        $problems[] = 'exported Makefile names a pruned thing: ' . $token;
    }
}

foreach (['lekalo', 'openspec', 'nativa-minimal', 'nativa-themes'] as $token) {
    if (str_contains($workflow, $token)) {
        $problems[] = 'exported workflow names a pruned thing: ' . $token;
    }
}

$registry = json_decode((string) @file_get_contents($root . '/toolchain.json'), true);

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

if ([] !== $problems) {
    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . PHP_EOL);
    }

    exit(1);
}

printf(
    "    profile %s: %d module(s), %d target(s), %d gate(s), %d tool(s), source %s\n",
    (string) $profile['slug'],
    count($modules),
    count($targets),
    count($registry['gates']),
    count($registry['tools']),
    substr((string) $marker['commit'], 0, 7),
);
