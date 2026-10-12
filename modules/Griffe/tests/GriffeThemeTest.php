<?php

declare(strict_types = 1);

namespace Modules\Griffe\Tests;

use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Griffe\Theme\GriffeTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GriffeThemeTest — the checkbox hamburger's DOM order, the slider contract,
 * and the script gates that keep pages lean.
 *
 * The mobile menu opens through `:checked`, which only works while the input
 * precedes both the menu and the label (one `~` selector, no `:has()`).
 * Nothing in the stylesheet enforces that order, so this file does.
 *
 * The slider counts are asserted on the markup BEFORE the first `<script`:
 * pageWrap inlines the slider/reveal/highlight twins, whose sources name the
 * same classes — counting over the whole document would count the scripts.
 */
final class GriffeThemeTest extends TestCase
{
    private const string MODULE = 'Griffe';

    private const string THEME = 'griffe';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `griffe` theme ships with this module.');
        }
    }

    #[Test]
    public function discoveryFindsTheTheme(): void
    {
        $this->assertTrue(ThemeRegistry::has(self::THEME), 'Griffe ships a theme; discovery did not find it.');

        $theme = ThemeRegistry::get(self::THEME);

        $this->assertInstanceOf(GriffeTheme::class, $theme);
        $this->assertSame('griffe', $theme->bundle());
        $this->assertSame('gr', $theme->prefix());
        $this->assertSame('', $theme->themeInitScript(), 'No override state — the shared pre-paint bytes stay.');
    }

    #[Test]
    public function theToggleSitsBeforeTheMenuItOpens(): void
    {
        $header = self::headerWithItems();

        $input = strpos($header, 'id="gr-nav-toggle"');
        $menu = strpos($header, 'class="gr-nav"');
        $label = strpos($header, 'for="gr-nav-toggle"');

        $this->assertIsInt($input);
        $this->assertIsInt($menu);
        $this->assertIsInt($label);
        $this->assertLessThan($menu, $input, 'The input must precede the menu: `~` cannot reach a preceding sibling.');
        $this->assertLessThan($label, $menu, 'The label must follow the menu, inside the actions cluster.');
    }

    #[Test]
    public function theTogglePairingIsComplete(): void
    {
        $header = self::headerWithItems();

        $this->assertStringContainsString('type="checkbox"', $header);
        $this->assertStringContainsString('class="gr-nav__toggle-input"', $header);
        $this->assertStringContainsString('aria-label="Open navigation"', $header);
        $this->assertStringContainsString('gr-nav__toggle-icon--meatball', $header);
        $this->assertStringContainsString('gr-nav__toggle-icon--close', $header, 'Both icons ship; CSS shows one at a time.');
    }

    #[Test]
    public function aHeaderWithoutItemsRendersNoToggleAtAll(): void
    {
        $context = ShellContext::from('Griffe', [], [], '/', null, 'sk', [], false);

        $header = (new GriffeTheme())->header($context);

        $this->assertStringNotContainsString('gr-nav__toggle', $header);
        $this->assertStringNotContainsString('class="gr-nav"', $header);
        $this->assertStringContainsString('gr-header__brand', $header, 'The brand is always there.');
    }

    #[Test]
    public function theStylesheetKeysTheOpenStateOffChecked(): void
    {
        $css = InlineCss::file('Modules/Griffe/assets/critical/griffe.css');

        $this->assertStringContainsString(
            '.gr-nav__toggle-input:checked ~ .gr-header__inner .gr-nav',
            $css,
            'The open state is a checkbox, not a class some script has to add.',
        );
    }

    #[Test]
    public function theHeaderCarriesTheSharedToggleBeforeTheHamburger(): void
    {
        $header = self::headerWithItems();

        $this->assertStringContainsString('data-theme-toggle', $header, 'Without the opt-in the shared handler never wires the button.');
        $this->assertStringContainsString('gr-theme-toggle__sun', $header);
        $this->assertStringContainsString('gr-theme-toggle__moon', $header, 'Both icons ship; CSS shows one at a time.');

        $toggle = (int) strpos($header, 'data-theme-toggle');
        $hamburger = (int) strpos($header, 'gr-nav__toggle-icon--meatball');
        $this->assertLessThan($hamburger, $toggle, 'Theme switcher first, hamburger last — the hamburger stays the final control.');
    }

    #[Test]
    public function theHomepageIsASliderWithOneSlidePerArticlePlusHero(): void
    {
        $html = self::bodyOf((new GriffeTheme())->homepage(self::homepageContext()));

        $this->assertSame(3, substr_count($html, '<li class="gr-slide'), 'Hero slide + 2 case slides.');
        $this->assertSame(1, substr_count($html, 'gr-slide--active'), 'Slide 1 is active server-side.');
        $this->assertSame(3, substr_count($html, '<button type="button" class="gr-pag'), 'One pagination button per slide.');
        $this->assertStringContainsString('aria-current="true"', $html);
        $this->assertSame(1, substr_count($html, 'gr-slider__arrow--prev'));
        $this->assertSame(1, substr_count($html, 'gr-slider__arrow--next'));
        $this->assertStringContainsString('data-gr-autoplay="6000"', $html);
    }

    #[Test]
    public function anEmptyHomepageStillRendersTheHeroAlone(): void
    {
        $context = PageContext::forHomepage(Request::create('GET', '/'), 'griffe', 'en');

        $html = self::bodyOf((new GriffeTheme())->homepage($context));

        $this->assertSame(1, substr_count($html, '<li class="gr-slide'), 'The hero slide renders with no articles.');
        $this->assertStringNotContainsString('gr-slider__pag', $html, 'No pagination for a single slide.');
        $this->assertStringNotContainsString('gr-slider__arrow', $html, 'No arrows for a single slide.');
        $this->assertStringContainsString('Nothing published yet.', $html);
    }

    #[Test]
    public function headingsShipMaskedLinesForTheReveal(): void
    {
        $html = self::bodyOf((new GriffeTheme())->homepage(self::homepageContext()));

        $this->assertStringContainsString('class="gr-anim"><span class="gr-anim__line"', $html);
    }

    #[Test]
    public function scriptsAreGatedOnTheMarkupTheyDrive(): void
    {
        $home = (new GriffeTheme())->homepage(self::homepageContext());

        $this->assertStringContainsString('gr-header.js', $home, 'The header always renders.');
        $this->assertStringContainsString('gr-slider.js', $home);
        $this->assertStringContainsString('gr-reveal.js', $home);

        $plain = (new GriffeTheme())->blogIndex(self::indexContext([]));

        $this->assertStringContainsString('gr-header.js', $plain);
        $this->assertStringNotContainsString('gr-slider.js', $plain, 'No slider markup, no slider JS.');
        $this->assertStringNotContainsString('gr-reveal.js', $plain, 'No masked lines, no reveal JS.');
        $this->assertStringNotContainsString('gr-code-highlight.js', $plain, 'No fences, no highlighter JS.');
    }

    #[Test]
    public function fencedBlocksPullInTheHighlighter(): void
    {
        $html = (new GriffeTheme())->blogArticle(self::articleContext(
            '<pre><code class="language-php">echo 1;</code></pre>',
        ));

        $this->assertStringContainsString('gr-code-highlight.js', $html);
    }

    #[Test]
    public function nothingLeaksAnotherThemesVocabulary(): void
    {
        $html = (new GriffeTheme())->homepage(self::homepageContext())
            . (new GriffeTheme())->header(self::shellWithItems())
            . (new GriffeTheme())->footer(self::shellWithItems());

        foreach (['er-header', 'er-nav', 'mo-navbar', 'st-header', 'mo-card', 'er-section'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, sprintf('Foreign vocabulary `%s` in griffe output.', $foreign));
        }
    }

    #[Test]
    public function theBlogFamilyRendersCloudsAuthorAndErrors(): void
    {
        $theme = new GriffeTheme();

        $tags = $theme->blogTags(PageContext::forBlogTags(
            Request::create('GET', '/blog/tag/php'),
            'griffe',
            'en',
            ['php', 'web'],
            ['php' => 3],
            'php',
            [self::article('a', 'A')],
        ));

        $this->assertStringContainsString('gr-chip--active', $tags, 'The active term is marked.');
        $this->assertStringContainsString('php (3)', $tags, 'Counts render beside terms.');

        $author = $theme->authorProfile(PageContext::forAuthorProfile(
            Request::create('GET', '/author/jane'),
            'griffe',
            'en',
            ['name' => 'Jane', 'avatar' => null],
            [self::article('a', 'A')],
            '2024',
        ));

        $this->assertStringContainsString('Jane', $author);
        $this->assertStringContainsString('Member since 2024', $author);

        $error = $theme->errorContent(404, 'Lost <here>', '& gone');

        $this->assertStringContainsString('Lost &lt;here&gt;', $error);
        $this->assertStringContainsString('&amp; gone', $error);
        $this->assertStringContainsString('href="/"', $error);
    }

    #[Test]
    public function untrustedCopyEscapes(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'A & B <script>'],
        );

        $html = (new GriffeTheme())->homepage($context);

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>A', $html);
    }

    private static function bodyOf(string $html): string
    {
        $cut = strpos($html, '<script');

        return false === $cut ? $html : substr($html, 0, $cut);
    }

    private static function article(string $slug, string $title): object
    {
        $article = new \stdClass();
        $article->slug = $slug;
        $article->title = $title;

        return $article;
    }

    private static function homepageContext(): PageContext
    {
        return PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'Tailored B2B E-commerce Platforms', 'lede' => 'Business first.'],
            blogHref: '/blog',
            latestArticles: [self::article('alpha', 'Alpha Client'), self::article('beta', 'Beta Client')],
        );
    }

    /**
     * @param list<object> $articles
     */
    private static function indexContext(array $articles): PageContext
    {
        return PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            'griffe',
            'en',
            $articles,
        );
    }

    private static function articleContext(string $bodyHtml): PageContext
    {
        return PageContext::forBlogArticle(
            Request::create('GET', '/blog/hello'),
            'griffe',
            'en',
            'hello',
            'Hello',
            $bodyHtml,
            '',
            '',
            '',
            '',
            [],
            [],
        );
    }

    private static function headerWithItems(): string
    {
        return (new GriffeTheme())->header(self::shellWithItems());
    }

    private static function shellWithItems(): ShellContext
    {
        return ShellContext::from(
            'Griffe',
            [new ShellNavItem('Work', '/work', active: true), new ShellNavItem('Blog', '/blog')],
            [new ShellNavItem('Privacy', '/privacy')],
            '/',
            null,
            'en',
            ['en', 'it'],
            false,
        );
    }
}
