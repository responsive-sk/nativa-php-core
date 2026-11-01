<?php

declare(strict_types = 1);

namespace Tests\Content;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProseRhythmContractTest, the written form of the `>` separator.
 *
 * `prose-separator` names the rule; this is the part of it a machine can see.
 * The list below is the set of files already wrapped in the new form, and it
 * only grows: a file joins it when its prose is converted, so the ratchet never
 * needs a reason to shrink. What this cannot judge is where a sentence may be
 * cut (verb and object stay together, a noun keeps its attribute); that stays a
 * decision an author makes, with the examples in the spec as the model.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProseRhythmContractTest extends TestCase
{
    /**
     * Files wrapped in the new form. Add one per converted file, and only after
     * it passes every check below.
     *
     * @return list<string>
     */
    private static function wrapped(): array
    {
        return [
            'docs/core-philosophy.md',
            'storage/blog/hyprland-namiesto-xfce.md',
        ];
    }

    /**
     * Function words that may not end a line: a preposition, a conjunction or
     * an auxiliary left dangling is the defect the eye catches first, and it is
     * the mechanical shadow of a phrase cut in the wrong place.
     *
     * @return list<string>
     */
    private static function danglingWords(): array
    {
        return [
            // prepositions
            'na', 'v', 'vo', 's', 'so', 'z', 'zo', 'do', 'pre', 'o', 'po', 'pri',
            'k', 'ku', 'za', 'pred', 'cez', 'medzi', 'bez', 'pod', 'nad', 'proti',
            'oproti', 'počas', 'podľa', 'kvôli', 'vďaka',
            // conjunctions
            'a', 'aj', 'ale', 'no', 'ani', 'či', 'lebo', 'pretože', 'takže',
            'však', 'hoci', 'aby', 'keď',
            // auxiliaries and particles
            'je', 'sa', 'si', 'by', 'som', 'sme', 'sú', 'bol', 'bola', 'bolo',
            'nie', 'už', 'len', 'ten', 'tá', 'tie', 'ktorý', 'ktorá',
            'ktoré', 'the', 'a', 'an', 'of', 'in', 'is', 'are', 'was',
            'with', 'and', 'but', 'or', 'as', 'at', 'for', 'on', 'by',
        ];
    }

    /**
     * A paragraph, as the lines a reader would see joined: headings, lists,
     * tables and fenced code are not prose and are skipped.
     *
     * @return list<array{file: string, lines: list<string>}>
     */
    private static function paragraphs(string $file): array
    {
        $content = (string) @file_get_contents(self::root() . '/' . $file);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $paragraphs = [];
        $current = [];
        $fence = false;
        $front = false;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (0 === $index && '---' === $trimmed) {
                $front = true;
                continue;
            }

            if ($front) {
                if ('---' === $trimmed) {
                    $front = false;
                }

                continue;
            }

            if (str_starts_with($trimmed, '```')) {
                $fence = !$fence;
                continue;
            }

            if ($fence) {
                continue;
            }

            $isProse = '' !== $trimmed
                && !str_starts_with($trimmed, '#')
                && !str_starts_with($trimmed, '|')
                && !str_starts_with($trimmed, '>')
                && !str_starts_with($trimmed, '---')
                && 1 !== preg_match('/^[-*+]\s+/u', $trimmed)
                && 1 !== preg_match('/^\d+\.\s+/u', $trimmed)
                && $line === ltrim($line);

            if (!$isProse) {
                if ([] !== $current) {
                    $paragraphs[] = ['file' => $file, 'lines' => $current];
                    $current = [];
                }

                continue;
            }

            $current[] = $trimmed;
        }

        if ([] !== $current) {
            $paragraphs[] = ['file' => $file, 'lines' => $current];
        }

        return $paragraphs;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    #[Test]
    public function noWrappedFileCarriesAnEmDash(): void
    {
        self::assertNotSame([], self::wrapped(), 'The wrapped list is empty, so this check reads nothing.');

        foreach (self::wrapped() as $file) {
            self::assertStringNotContainsString(
                '—',
                (string) @file_get_contents(self::root() . '/' . $file),
                sprintf('%s still carries an em dash; the separator is `>`.', $file),
            );
        }
    }

    #[Test]
    public function aLineEndsWithTheSeparatorOnlyInsideItsSentence(): void
    {
        $problems = [];

        foreach (self::wrapped() as $file) {
            foreach (self::paragraphs($file) as $paragraph) {
                $lines = $paragraph['lines'];

                foreach ($lines as $index => $line) {
                    $last = $index === count($lines) - 1;
                    $hasSeparator = str_ends_with($line, ' >');
                    $endsSentence = 1 === preg_match('/[.!?:]$/u', (string) preg_replace('/\s>$/', '', $line));

                    if ($last && $hasSeparator) {
                        $problems[] = sprintf('%s: last line of a paragraph ends with the separator: %s', $file, $line);
                    }

                    if (!$last && $endsSentence && $hasSeparator) {
                        $problems[] = sprintf(
                            '%s: a line that ends its sentence carries the separator: %s',
                            $file,
                            $line,
                        );
                    }

                    if ($last || $hasSeparator) {
                        continue;
                    }

                    if (!$endsSentence) {
                        $problems[] = sprintf(
                            '%s: a line breaks mid-sentence without the separator: %s',
                            $file,
                            $line,
                        );
                    }
                }
            }
        }

        self::assertSame([], $problems, "The separator belongs inside a sentence, at the end of the line it closes:\n" . implode("\n", $problems));
    }

    #[Test]
    public function noLineEndsOnAFunctionWord(): void
    {
        $problems = [];

        foreach (self::wrapped() as $file) {
            foreach (self::paragraphs($file) as $paragraph) {
                foreach ($paragraph['lines'] as $line) {
                    if (!str_ends_with($line, ' >')) {
                        continue;
                    }

                    $words = preg_split('/\s+/u', trim(substr($line, 0, -2))) ?: [];
                    $last = strtolower((string) end($words));
                    $last = trim($last, '.,;:!?„"()[]');

                    if (in_array($last, self::danglingWords(), true)) {
                        $problems[] = sprintf('%s: a line ends on `%s`: %s', $file, $last, $line);
                    }
                }
            }
        }

        self::assertSame([], $problems, "A phrase cut in the wrong place leaves a function word at the end of the line:\n" . implode("\n", $problems));
    }

    #[Test]
    public function noLineExceedsTheWidth(): void
    {
        $problems = [];

        foreach (self::wrapped() as $file) {
            foreach (self::paragraphs($file) as $paragraph) {
                foreach ($paragraph['lines'] as $line) {
                    if (mb_strlen($line, 'UTF-8') <= 100) {
                        continue;
                    }

                    $problems[] = sprintf('%s: %d characters: %s', $file, mb_strlen($line, 'UTF-8'), $line);
                }
            }
        }

        self::assertSame([], $problems, "A line may run long rather than cut a phrase, but not forever:\n" . implode("\n", $problems));
    }
}
