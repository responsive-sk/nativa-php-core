<?php

declare(strict_types = 1);

namespace Modules\Mono\Tests;

use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Modules\Mono\Theme\MonoTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MonoThemeTest (Boot 25) — the mobile meatball, and the contract that makes it
 * work without a line of JavaScript.
 *
 * The vendored mono snapshot hides `.mo-navbar__menu` below 768px and reopens
 * it by adding `mo-navbar--menu-open` from the live site's JS. This tree ships
 * no JS, so the menu was unreachable on a phone. Boot 23 answered that by
 * showing the strip inline always; the answer now is the theme's own meatball,
 * driven by a checkbox (`:checked`), in the idiom the shared layout already uses
 * for the starter's nav and Erik repeats in `er-nav__toggle-input`.
 *
 * That mechanism rests on DOM ORDER: one `~` sibling selector has to reach both
 * the menu and the label from the input, which only works while the input comes
 * first and the label comes after the menu. Nothing in a stylesheet enforces
 * that, so this file does — the day someone moves the toggle next to the brand,
 * the menu silently stops opening, and these assertions say why.
 */
final class MonoThemeTest extends TestCase
{
    private const string MODULE = 'Mono';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `mono` theme ships with this module.');
        }
    }

    #[Test]
    public function theMeatballAndTheCheckboxItDrivesAreRenderedForAHeaderWithItems(): void
    {
        $header = self::headerWithItems();

        $this->assertStringContainsString('type="checkbox"', $header);
        $this->assertStringContainsString('id="mo-nav-toggle"', $header, 'The label needs an input id to target.');
        $this->assertStringContainsString('class="mo-navbar__toggle-input"', $header);
        $this->assertStringContainsString('aria-label="Open navigation"', $header, 'The hidden control must still be named.');
        $this->assertStringContainsString('for="mo-nav-toggle"', $header);
        $this->assertStringContainsString('mo-navbar__toggle-icon--meatball', $header);
        $this->assertStringContainsString('mo-navbar__toggle-icon--close', $header, 'Both icons ship; CSS shows one at a time.');
    }

    /**
     * The `~` contract: input, then the menu, then the label that activates it.
     */
    #[Test]
    public function theToggleSitsBeforeTheMenuItOpens(): void
    {
        $header = self::headerWithItems();

        $input = strpos($header, 'id="mo-nav-toggle"');
        $menu = strpos($header, 'class="mo-navbar__menu"');
        $label = strpos($header, 'for="mo-nav-toggle"');

        $this->assertIsInt($input);
        $this->assertIsInt($menu);
        $this->assertIsInt($label);
        $this->assertLessThan($menu, $input, 'The input must precede the menu: `~` cannot reach a preceding sibling.');
        $this->assertLessThan($label, $menu, 'The label must follow the menu, inside the actions cluster.');
    }

    /**
     * A toggle that opens nothing is worse than no toggle — and its label would
     * dangle over an id the document does not contain.
     */
    #[Test]
    public function aHeaderWithoutItemsRendersNoToggleAtAll(): void
    {
        $context = ShellContext::from('Mono', [], [], '/', null, 'sk', [], false);

        $header = (new MonoTheme())->header($context);

        $this->assertStringNotContainsString('mo-navbar__toggle', $header);
        $this->assertStringNotContainsString('mo-navbar__menu', $header);
        $this->assertStringContainsString('mo-navbar__brand', $header, 'The brand is always there.');
    }

    /**
     * The open state is a checkbox, not a class some script has to add.
     */
    #[Test]
    public function theStylesheetKeysTheOpenStateOffChecked(): void
    {
        $css = InlineCss::file('Modules/Mono/assets/critical/mono.css');

        $this->assertStringContainsString('.mo-navbar__toggle-input:checked~.mo-navbar__menu', $css);
        $this->assertStringContainsString('.mo-navbar__toggle-input{height:1px;opacity:0;position:absolute;width:1px}', $css);
        $this->assertStringNotContainsString(
            'max-width:767px){.mo-navbar__menu{gap:var(--space-4);overflow-x:auto',
            $css,
            'The always-visible strip was replaced by the toggle — a leftover copy would hide the meatball’s effect.',
        );
    }

    /**
     * The token palette the client-side highlighter needs: without these
     * rules mo-code-highlight.js still runs, but every span it emits is
     * invisible — one colour either way, and nothing says why.
     */
    #[Test]
    public function theStylesheetCarriesTokenColorsForHighlightedBlocks(): void
    {
        $css = InlineCss::file('Modules/Mono/assets/critical/mono.css');

        foreach (['keyword', 'string', 'comment', 'number', 'function', 'variable', 'type'] as $token) {
            $this->assertStringContainsString(
                'main pre .tok-' . $token,
                $css,
                'The token palette is missing .' . $token . ' — highlighted blocks fall back to one colour.',
            );
        }

        $this->assertStringContainsString(
            '[data-theme=light] main pre .tok-keyword',
            $css,
            'The light variant needs its own token colours — the dark palette on white fails contrast.',
        );

        $this->assertStringContainsString(
            'main pre .mo-code-badge',
            $css,
            'The language badge needs positioning — without it the badge overlaps the code.',
        );
    }

    /**
     * The loader ships inline, and only where fenced blocks exist: pages
     * without fences carry no JS at all, and the no-JS fallback stays the
     * plain mono code block.
     */
    #[Test]
    public function pageWrapLoadsTheHighlighterOnlyWhereFencesExist(): void
    {
        $theme = new MonoTheme();

        $fenced = $theme->pageWrap('<pre><code class="language-php">echo 1;</code></pre>');

        $this->assertStringContainsString('<script nonce="__CSP_NONCE_PLACEHOLDER__">', $fenced);
        $this->assertStringContainsString('initMoCodeHighlight', $fenced);
        $this->assertStringContainsString('mo-code-badge', $fenced, 'The loader must carry the language badge — a fence without its label ships half the feature.');

        $plain = $theme->pageWrap('<p>no code here</p>');

        $this->assertStringNotContainsString('<script', $plain, 'A page without fences must ship no JS.');
    }

    /**
     * The scrollspy ships inline, and only where a TOC aside exists: the
     * gate is the same data-toc-link opt-in the tracker keys off, so the
     * two cannot drift apart — markup without the attribute gets no script.
     */
    #[Test]
    public function pageWrapLoadsTheScrollspyOnlyWhereATocAsideExists(): void
    {
        $theme = new MonoTheme();

        $withToc = $theme->pageWrap(
            '<aside class="mo-toc"><a class="mo-toc__link" data-toc-link data-heading="a" href="#a">A</a></aside>',
        );

        $this->assertStringContainsString('<script nonce="__CSP_NONCE_PLACEHOLDER__">', $withToc);
        $this->assertStringContainsString('initMoTocScrollspy', $withToc);
        $this->assertStringContainsString(
            '.mo-toc__link--active',
            InlineCss::file('Modules/Mono/assets/critical/mono.css'),
            'The active rule must ship in the stylesheet — a tracker with no style highlights invisibly.',
        );

        $withoutToc = $theme->pageWrap('<article><h2>Just text</h2></article>');

        $this->assertStringNotContainsString('initMoTocScrollspy', $withoutToc, 'A page without a TOC aside must ship no scrollspy.');
    }

    /**
     * The article aside: h2/h3 become labelled anchor links, h3 indented —
     * the same HTML the browser sees, so every href matches a heading id.
     */
    #[Test]
    public function blogArticleRendersTheTocAsideFromHeadings(): void
    {
        $html = (new MonoTheme())->blogArticle(self::articleContext([
            ['level' => 2, 'text' => 'Getting started', 'anchor' => 'getting-started'],
            ['level' => 3, 'text' => 'Install', 'anchor' => 'install'],
        ]));

        $this->assertStringContainsString('<aside class="mo-toc">', $html);
        $this->assertStringContainsString('aria-label="On this page"', $html, 'The nav needs a name — two navs without one are noise to a screen reader.');
        $this->assertStringContainsString('href="#getting-started"', $html);
        $this->assertStringContainsString('>Install</a>', $html);
        $this->assertStringContainsString('mo-toc__item--sub', $html, 'h3 nests under its h2 — a flat list lies about the structure.');
        $this->assertStringContainsString('data-toc-link data-heading="install"', $html, 'The scrollspy opt-in travels with the link, so a future Mono scrollspy needs no new markup.');
    }

    /**
     * No headings, no aside — and the markup stays exactly the pre-TOC shape,
     * so short articles ship not one byte of navigation chrome.
     */
    #[Test]
    public function blogArticleOmitsTheTocAsideWithoutHeadings(): void
    {
        $html = (new MonoTheme())->blogArticle(self::articleContext([]));

        $this->assertStringNotContainsString('mo-toc', $html);
        $this->assertStringNotContainsString('mo-article-layout', $html);
        $this->assertStringContainsString('<article class="mo-card">', $html);
    }

    /**
     * TOC text and anchors are escaped — a heading is author input, and
     * `"#` in an href is a breakout waiting for a quote.
     */
    #[Test]
    public function blogArticleEscapesTocTextAndAnchors(): void
    {
        $html = (new MonoTheme())->blogArticle(self::articleContext([
            ['level' => 2, 'text' => 'A & B <script>', 'anchor' => 'a-"b'],
            ['level' => 2, 'text' => '', 'anchor' => 'empty-text'],
            ['level' => 2, 'text' => 'No anchor', 'anchor' => ''],
        ]));

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringContainsString('href="#a-&quot;b"', $html);
        $this->assertStringNotContainsString('#empty-text', $html, 'An entry with no text is a link to nowhere.');
        $this->assertStringNotContainsString('No anchor', $html, 'An entry with no anchor cannot be jumped to.');
    }

    /**
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function articleContext(array $headings): PageContext
    {
        return PageContext::forBlogArticle(
            Request::create('GET', '/blog/toc-test'),
            'mono',
            'sk',
            'toc-test',
            'TOC test',
            '<h2 id="getting-started">Getting started</h2>',
            '',
            '',
            '',
            '',
            $headings,
            [],
        );
    }

    private static function headerWithItems(): string
    {
        $context = ShellContext::from(
            'Mono',
            [new ShellNavItem('Home', '/', active: true), new ShellNavItem('Articles', '/blog')],
            [new ShellNavItem('Docs', '/docs')],
            '/',
            null,
            'sk',
            [],
            false,
        );

        return (new MonoTheme())->header($context);
    }
}
