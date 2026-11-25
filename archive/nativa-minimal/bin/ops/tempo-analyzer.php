#!/usr/bin/env php
<?php

/**
 * Tempo Analyzer — checks sentence length across sections.
 *
 * ADVISORY, not a gate. Sentence length is a reading aid, not a rule: the
 * numbers below are the template's opinion about tempo, and a warning here
 * does not mean the reader was lost. The exit code is 0 unless --strict is
 * passed. See docs/blog-voice.md.
 *
 * Usage: php bin/ops/tempo-analyzer.php <article.md> [--strict]
 *
 * Validates that:
 * - INTRO: short sentences (5-15 words)
 * - HMLA: short & punchy (8-15 words)
 * - SLNKO: longer, explanatory (15-25 words)
 * - NOC: medium, reflective (12-20 words)
 * - TÉMA: one sentence (10-20 words)
 */

declare(strict_types = 1);

$args = array_slice($argv, 1);
$strict = in_array('--strict', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => '--strict' !== $a));

if ([] === $args) {
    echo "Usage: php bin/ops/tempo-analyzer.php <article.md> [--strict]\n";
    exit(1);
}

$file = $args[0];
if (!is_file($file)) {
    echo "Error: File not found: $file\n";
    exit(1);
}

$content = file_get_contents($file);
if (false === $content) {
    echo "Error: Cannot read file: $file\n";
    exit(1);
}

// Strip frontmatter.
$lines = explode("\n", $content);
$inFrontmatter = false;
$bodyLines = [];
foreach ($lines as $line) {
    if ('---' === trim($line) && !$inFrontmatter) {
        $inFrontmatter = true;
        continue;
    }
    if ('---' === trim($line) && $inFrontmatter) {
        $inFrontmatter = false;
        continue;
    }
    if (!$inFrontmatter) {
        $bodyLines[] = $line;
    }
}
$body = implode("\n", $bodyLines);

// Split into sections.
$sections = preg_split('/^## /m', $body);
$sections = array_map(trim(...), $sections);

// Phase targets (min-max words per sentence).
$targets = [
    'INTRO' => [5, 15],
    'HMLA' => [8, 15],
    'SLNKO' => [15, 25],
    'NOC' => [12, 20],
    'OBJEKT' => [10, 20],
    'TÉMA' => [10, 20],
    'OUTRO' => [8, 18],
];

// Map section headings to phases.
function mapToPhase(string $heading): string
{
    $lower = strtolower($heading);

    $map = [
        'INTRO' => ['hook', 'hák', 'úvod'],
        'HMLA' => ['happened', 'stalo', 'setup', 'nastavenie', 'problem', 'problém', 'crash', 'zrazil', 'reputation', 'povesť', 'rule', 'pravidlo', 'cascade', 'kaskáda'],
        'SLNKO' => ['fix', 'oprav', 'solution', 'riešen', 'diagnos', 'diagnost', 'types', 'typy', 'two types', 'dva typy', 'when cache', 'keď cache', 'real story', 'reálny', 'bcrypt'],
        'NOC' => ['lesson', 'ponaučenie', 'expect', 'očakáva', 'why', 'prečo', 'relates', 'súvis', 'evolution', 'evolúcia', 'limit is not', 'limit nie je', 'deeper', 'hlbšia'],
        'OBJEKT' => ['remains', 'zostáva', 'stays', 'ostáva', 'is still', 'stále', 'when you need', 'keď potrebujete'],
        'TÉMA' => ['summary', 'zhrnutie', 'conclusion', 'záver'],
        'OUTRO' => ['related', 'súvisiace', 'odkazy', 'further', 'ďalšie'],
    ];

    foreach ($map as $phase => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return $phase;
            }
        }
    }

    return 'HMLA'; // Default to HMLA for unrecognized sections.
}

// Analyze each section.
$checks = [];
$pass = 0;
$fail = 0;

foreach ($sections as $i => $section) {
    $firstLine = explode("\n", $section)[0];
    $heading = trim($firstLine);

    // Skip empty sections.
    if ($heading === '' && $i > 0) {
        continue;
    }

    $phase = (0 === $i) ? 'INTRO' : mapToPhase($heading);

    // Extract sentences.
    $sentences = preg_split('/[.!?]+/', $section);
    $sentences = array_filter(array_map(trim(...), $sentences), fn ($s) => str_word_count($s) > 3);

    if (count($sentences) === 0) {
        continue;
    }

    $wordCounts = array_map(str_word_count(...), $sentences);
    $avg = (int) round(array_sum($wordCounts) / count($wordCounts));
    $min = min($wordCounts);
    $max = max($wordCounts);

    $target = $targets[$phase] ?? [8, 20];
    $targetMin = $target[0];
    $targetMax = $target[1];

    $ok = $avg >= $targetMin && $avg <= $targetMax;
    $icon = $ok ? '✅' : '⚠️';

    $label = $phase;
    if (0 !== $i) {
        $label .= " ($heading)";
    }

    $checks[] = [
        'label' => $label,
        'ok' => $ok,
        'avg' => $avg,
        'min' => $min,
        'max' => $max,
        'count' => count($sentences),
        'target' => "$targetMin-$targetMax",
    ];

    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
}

// Output.
$basename = basename($file, '.md');
echo "\n";
echo "═══════════════════════════════════════════════════\n";
echo "  Tempo Analyzer: $basename\n";
echo "  ADVISORY — a reading aid, not a rule.\n";
echo "  These numbers are the template's opinion about tempo.\n";
echo "  The blog is written for the readers; see docs/blog-voice.md.\n";
echo "═══════════════════════════════════════════════════\n\n";

foreach ($checks as $c) {
    $icon = $c['ok'] ? '✅' : '⚠️';
    echo "  $icon {$c['label']}\n";
    echo "     Avg: {$c['avg']} words/sentence (target: {$c['target']})\n";
    echo "     Range: {$c['min']}-{$c['max']} words, {$c['count']} sentences\n\n";
}

echo "───────────────────────────────────────────────────\n";
echo "  Result: $pass OK, $fail warnings\n";
echo "───────────────────────────────────────────────────\n\n";

// Advisory by default: a warning is a hint for the author, not a broken
// build. --strict restores the hard exit for a human who asked for one.
exit($strict && $fail > 0 ? 1 : 0);
