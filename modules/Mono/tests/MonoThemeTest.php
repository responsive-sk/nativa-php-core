<?php

declare(strict_types = 1);

namespace Modules\Mono\Tests;

use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Domain\ShellNavItem;
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
