<?php

declare(strict_types = 1);

namespace Tests\Content;

use Modules\Core\Application\Services\MarkdownRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MarkdownSeparatorTest, the responsive.sk `>` separator.
 *
 * The separator closes the line it belongs to and the next sentence starts on
 * a new line. It stays the visible character; a line that merely starts with
 * `>` carrying text is still an ordinary blockquote.
 */
final class MarkdownSeparatorTest extends TestCase
{
    #[Test]
    public function theSeparatorStaysAtTheEndOfItsLine(): void
    {
        $html = (new MarkdownRenderer())->render("prvý bod >\ndruhý bod\n");

        $this->assertSame(
            "<p>prvý bod &gt;<br>druhý bod</p>\n",
            $html,
            'The glyph ends its line and the next line starts below it.',
        );
    }

    #[Test]
    public function aBlankLineEndsTheParagraphAfterTheSeparator(): void
    {
        $html = (new MarkdownRenderer())->render("prvý bod >\n\ndruhý bod\n");

        $this->assertSame("<p>prvý bod &gt;</p>\n<p>druhý bod</p>\n", $html);
    }

    #[Test]
    public function aLineStartingWithTheSeparatorIsStillAQuote(): void
    {
        $html = (new MarkdownRenderer())->render("> quoted line\n");

        $this->assertStringContainsString('<blockquote><p>quoted line</p></blockquote>', $html);
    }

    #[Test]
    public function theSeparatorIsNeverReadAsAQuote(): void
    {
        $html = (new MarkdownRenderer())->render("prvý bod >\n\ndruhý bod >\n\n");

        $this->assertStringNotContainsString('<blockquote>', $html);
        $this->assertSame(2, substr_count($html, '&gt;'));
    }
}
