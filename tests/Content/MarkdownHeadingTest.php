<?php

declare(strict_types = 1);

namespace Tests\Content;

use Modules\Core\Application\Services\MarkdownRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MarkdownHeadingTest — the `Časť I — Title` heading shape.
 *
 * A section heading that opens with its own numbering reads as a label, not as
 * a sentence, so the renderer exposes the prefix and the title as separate
 * spans. The TOC still reads the full text, and a heading with inline markup is
 * left untouched rather than cut in the middle of a tag.
 */
final class MarkdownHeadingTest extends TestCase
{
    #[Test]
    public function aPrefixedHeadingIsSplitIntoAPrefixAndATitle(): void
    {
        $renderer = new MarkdownRenderer();
        $html = $renderer->render("## Časť I — Prvý krok\n");

        $this->assertStringContainsString('<span data-heading-prefix>Časť I</span>', $html);
        $this->assertStringContainsString('<span data-heading-dash> — </span>', $html);
        $this->assertStringContainsString('<span data-heading-title>Prvý krok</span>', $html);

        $headings = $renderer->extractHeadings($html);

        $this->assertSame('Časť I — Prvý krok', $headings[0]['text'], 'The TOC still reads the whole heading.');
    }

    #[Test]
    public function aHeadingWithInlineMarkupIsNotSplit(): void
    {
        $html = (new MarkdownRenderer())->render("## `src/http.php` — early logging\n");

        $this->assertStringContainsString('<code>src/http.php</code> — early logging', $html);
        $this->assertStringNotContainsString('data-heading-prefix', $html);
    }
}
