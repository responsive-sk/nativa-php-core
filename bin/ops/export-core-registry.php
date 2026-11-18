<?php

declare(strict_types = 1);

/*
 * export-core-registry.php — the exported tree's toolchain.json, derived.
 *
 * The source registry is the one owner of every pin, every gate question and
 * whether a gate can fail. The export carries a SUBSET of those gates, because
 * a target that needs ../nativa-minimal or a nativa-themes checkout cannot run
 * there. Copying the registry would leave the export claiming nine jobs it does
 * not have; regenerating it by hand would create a second owner.
 *
 * This script filters, and it refuses to filter quietly:
 *
 *   1. a gate survives when its target is a target of the exported Makefile;
 *   2. a target in the exported Makefile with no source gate is an error, so a
 *      typo cannot ship a gate-less recipe;
 *   3. a gate's `ci` list is dropped: the source job keys do not exist in the
 *      export, and the exporter asserts the exported workflow's `make` calls
 *      separately;
 *   4. a tool survives when every needle file exists in the export and every
 *      substituted needle string is found in one of them. A tool that survives
 *      with a `cache` entry must have its key in the exported workflow;
 *   5. a reference to a pruned path in a surviving gate's `answers` is an
 *      error, not a sentence nobody reads.
 *
 * Usage:
 *   php bin/ops/export-core-registry.php \
 *       --root=<export root> --registry=<source toolchain.json> \
 *       --makefile=<export Makefile> --out=<export toolchain.json>
 */

/**
 * Paths the export does not carry. A method, not a class constant: the tree's
 * `const` count is a ratchet (`NameOwnershipContractTest`), and a filter list
 * in one script is not a name anyone else owns.
 *
 * @return list<string>
 */
function prunedMarkers(): array
{
    return ['lekalo', 'openspec', 'kernel-status', 'docs/', 'nativa-minimal', 'nativa-themes'];
}

/**
 * @param list<string> $argv
 *
 * @return array<string, string>
 */
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

    foreach (['root', 'registry', 'makefile', 'out'] as $required) {
        if (!isset($options[$required]) || '' === $options[$required]) {
            fail(sprintf('missing --%s=<path>', $required));
        }
    }

    return $options;
}

function fail(string $message): never
{
    fwrite(STDERR, 'export-core-registry: ' . $message . PHP_EOL);
    exit(1);
}

/** @return list<string> */
function makeTargets(string $makefile): array
{
    $contents = (string) @file_get_contents($makefile);

    if ('' === $contents) {
        fail(sprintf('cannot read %s', $makefile));
    }

    $count = preg_match_all('/^([a-z][a-z0-9-]*):.*##/m', $contents, $matched);

    if (false === $count || 0 === $count) {
        fail('the exported Makefile carries no `target: ## help` lines');
    }

    $targets = array_values(array_unique($matched[1]));
    sort($targets);

    return $targets;
}

/**
 * @param list<string> $needles
 *
 * @return list<string>
 */
function resolveNeedles(array $needles, array $tool): array
{
    $resolved = [];

    foreach ($needles as $needle) {
        $resolved[] = str_replace(
            ['{{version}}', '{{target}}', '{{sha256}}'],
            [(string) $tool['version'], (string) $tool['target'], (string) $tool['sha256']],
            $needle,
        );
    }

    return $resolved;
}

$options = options(array_slice($argv, 1));
$root = rtrim($options['root'], '/');

$decoded = json_decode((string) @file_get_contents($options['registry']), true, 512, JSON_THROW_ON_ERROR);

if (!is_array($decoded) || !isset($decoded['gates'], $decoded['tools'])) {
    fail('the source registry has no gates or tools list');
}

$targets = makeTargets($options['makefile']);
$targetLookup = array_flip($targets);
$sourceGates = [];

foreach ($decoded['gates'] as $gate) {
    if (!is_array($gate) || !isset($gate['target'])) {
        fail('a source gate has no target');
    }

    $sourceGates[(string) $gate['target']] = $gate;
}

foreach ($targets as $target) {
    if (!isset($sourceGates[$target])) {
        fail(sprintf('the exported Makefile has `%s` but the source registry does not register it', $target));
    }
}

$gates = [];

foreach ($sourceGates as $target => $gate) {
    if (!isset($targetLookup[$target])) {
        continue;
    }

    $answers = (string) ($gate['answers'] ?? '');

    foreach (prunedMarkers() as $marker) {
        if (str_contains($answers, $marker)) {
            fail(sprintf('gate `%s` answers "%s", which names a pruned path (%s)', $target, $answers, $marker));
        }
    }

    $gates[] = [
        'target' => $target,
        'tier' => (string) $gate['tier'],
        'can_fail' => (bool) $gate['can_fail'],
        'answers' => $answers,
    ];
}

$workflow = (string) @file_get_contents($root . '/.github/workflows/ci.yml');
$tools = [];
$dropped = [];

foreach ($decoded['tools'] as $tool) {
    if (!is_array($tool) || !isset($tool['name'], $tool['needles']['files'], $tool['needles']['strings'])) {
        fail('a source tool has no name or needles');
    }

    $found = true;

    foreach ($tool['needles']['files'] as $file) {
        $path = $root . '/' . $file;

        if (!is_file($path)) {
            $found = false;

            continue;
        }

        $contents = (string) file_get_contents($path);

        foreach (resolveNeedles($tool['needles']['strings'], $tool) as $needle) {
            if (!str_contains($contents, $needle)) {
                $found = false;
            }
        }
    }

    if (!$found) {
        $dropped[] = (string) $tool['name'];

        continue;
    }

    $entry = [
        'name' => (string) $tool['name'],
        'version' => (string) $tool['version'],
        'target' => (string) $tool['target'],
        'sha256' => (string) $tool['sha256'],
        'why' => (string) ($tool['why'] ?? ''),
        'needles' => [
            'files' => array_values($tool['needles']['files']),
            'strings' => array_values($tool['needles']['strings']),
        ],
    ];

    if (isset($tool['cache']) && is_array($tool['cache'])) {
        $entry['cache'] = [
            'path' => (string) $tool['cache']['path'],
            'key_prefix' => (string) $tool['cache']['key_prefix'],
        ];

        $key = sprintf('%s-%s-%s', $entry['cache']['key_prefix'], $entry['version'], $entry['target']);

        if (!str_contains($workflow, 'key: ' . $key)) {
            fail(sprintf('%s is cached under `%s`, which the exported workflow does not spell', $entry['name'], $key));
        }
    }

    $tools[] = $entry;
}

if ([] === $tools) {
    fail('no tool survived the filter, so the exported gates would name a pin nobody uses');
}

$registry = [
    'schema_version' => (int) ($decoded['schema_version'] ?? 1),
    'why' => 'One registry for what the exported tree is checked with and by what. Generated by bin/ops/export-core-registry.php from nativa-php-core: the gates it carries are the exported Makefile\'s targets, and a tool is kept only when its pin resolves in this tree. Read by `make gates`.',
    'tools' => $tools,
    'gates' => $gates,
    'ci_jobs' => [],
];

$encoded = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (false === $encoded) {
    fail('the registry could not be encoded');
}

if (false === @file_put_contents($options['out'], $encoded . PHP_EOL)) {
    fail(sprintf('cannot write %s', $options['out']));
}

printf(
    "    registry: %d gate(s), %d tool(s)%s\n",
    count($gates),
    count($tools),
    [] === $dropped ? '' : ', dropped ' . implode(', ', $dropped),
);
