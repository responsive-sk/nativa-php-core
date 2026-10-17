<?php

declare(strict_types = 1);

namespace Modules\Themes\Griffe\Tests;

use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Themes\Griffe\Theme\GriffeTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GriffeThemeTest — the checkbox hamburger's DOM order, the slider contract,
 * and the frozen bundle the behaviours ship in.
 *
 * The mobile menu opens through `:checked`, which only works while the input
 * precedes both the menu and the label (one `~` selector, no `:has()`).
 * Nothing in the stylesheet enforces that order, so this file does.
 *
 * pageWrap ships a bare `<main>`: the header/slider/reveal/highlight twins
 * moved into the frozen `griffe` bundle FrontendLayout defers, each init
 * self-gating on its markup. The slider counts still cut at the first
 * `<script` — belt and braces against an inline script sneaking back in.
 */
final class GriffeThemeTest extends TestCase
{
    private const string MODULE = 'Griffe';

    private const string THEME = 'griffe';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/' . self::MODULE)) {
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

        // The colour-mode restore stays verbatim (Mono precedent); the
        // override only appends the sync `gr-js` staging signal.
        $init = $theme->themeInitScript();

        $this->assertStringContainsString("localStorage.getItem('nativa-theme')", $init);
        $this->assertStringContainsString("setAttribute('data-theme', theme)", $init);
        $this->assertStringContainsString("classList.add('gr-js')", $init, 'The multi-slider stages pre-paint — without gr-js the opening frame stacks.');
        $this->assertStringContainsString('prefers-reduced-motion', $init, 'Reduced motion keeps the stacked calm fallback.');
    }

    #[Test]
    public function theRenderedDocumentSignalsJsBeforeFirstPaint(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        $this->assertStringContainsString("classList.add('gr-js')", $head, 'The staging signal must run synchronously in <head>, before first paint.');

        $style = (int) strpos($head, '<style');
        $signal = (int) strpos($head, "classList.add('gr-js')");
        $this->assertLessThan($signal, $style, 'The <style> paints first — the signal after it still precedes the body.');
    }

    #[Test]
    public function theMultiSliderOptsIntoPrePaintStaging(): void
    {
        $multi = (new GriffeTheme())->homepage(self::homepageContext());

        $this->assertStringContainsString(
            '<section class="gr-hero-slider gr-hero-slider--multi"',
            $multi,
            '2+ slides stage before first paint; without --multi the opening frame stacks.',
        );
    }

    #[Test]
    public function stagingOptInFollowsTheSlideCount(): void
    {
        // No articles passed: the theme falls back to published ones, so the
        // count — and therefore the opt-in — follows whatever storage holds.
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'Hero'],
        );

        $html = (new GriffeTheme())->homepage($context);
        $count = substr_count($html, '<li class="gr-slide');

        if ($count >= 2) {
            $this->assertStringContainsString('gr-hero-slider--multi', $html);
        } else {
            $this->assertStringNotContainsString('gr-hero-slider--multi', $html, 'One slide reads the same stacked or staged — no opt-in, no surprise.');
        }
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

    /**
     * The kernel HomepageAction passes no articles (no Blog import by explicit
     * contract), so the theme falls back to the latest published ones itself —
     * guarded: without Blog the hero stands alone, exactly as before. The
     * expectation is computed from the same source the theme reads, so the
     * test holds whatever storage publishes (including nothing).
     */
    #[Test]
    public function homepageWithoutPassedArticlesFallsBackToPublishedOnes(): void
    {
        $facade = 'Modules\\Blog\\Application\\BlogServices';

        $locale = ContentServices::mapper()->defaultLocale();
        $context = PageContext::forHomepage(Request::create('GET', '/'), 'griffe', $locale);

        $html = self::bodyOf((new GriffeTheme())->homepage($context));

        if (!class_exists($facade)) {
            $this->assertSame(1, substr_count($html, '<li class="gr-slide'), 'The hero slide renders with no articles.');
            $this->assertStringNotContainsString('gr-slider__pag', $html, 'No pagination for a single slide.');
            $this->assertStringNotContainsString('gr-slider__arrow', $html, 'No arrows for a single slide.');
            $this->assertStringContainsString('Nothing published yet.', $html);

            return;
        }

        $expected = $facade::articles()->latestCards(6, $locale);

        $this->assertSame(1 + count($expected), substr_count($html, '<li class="gr-slide'), 'Hero slide plus one case slide per published article.');

        if ([] === $expected) {
            $this->assertStringContainsString('Nothing published yet.', $html);

            return;
        }

        foreach ($expected as $card) {
            $this->assertStringContainsString('/blog/' . $card['slug'], $html);
            $this->assertStringContainsString($card['image'], $html, 'Selected work is illustrated — every fallback article ships its cover.');
        }

        $this->assertStringContainsString('gr-case__img', $html, 'The grid cards carry illustrations, not just headlines.');
    }

    /**
     * Slide 1 is the Erik hero shape: eyebrow kicker, title, lede, two
     * actions, inline line-art visual — and only slide 1 (case slides keep
     * their single Read More link).
     */
    #[Test]
    public function theHeroSlideHasTheErikShape(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'Hero', 'lede' => 'Lede.', 'hero_eyebrow' => 'Proof & Co', 'cta' => 'Go'],
            blogHref: '/blog',
            latestArticles: [self::article('a', 'A')],
        );

        $html = self::bodyOf((new GriffeTheme())->homepage($context));

        $this->assertStringContainsString('<p class="gr-slide__kicker">Proof &amp; Co</p>', $html);
        $this->assertStringContainsString('<a class="gr-btn" href="/blog">Go</a>', $html);
        $this->assertStringContainsString('<a class="gr-btn gr-btn--link" href="/mark">Open admin</a>', $html);
        $this->assertStringContainsString('class="gr-slide__actions"', $html);
        $this->assertSame(1, substr_count($html, 'gr-slide__actions'), 'Actions row lives on the hero slide only.');
        $this->assertStringContainsString('gr-slide__visual', $html);
        $this->assertStringContainsString('id="gr-glow"', $html);
        $this->assertStringContainsString('aria-hidden="true">', $html);
        $this->assertStringNotContainsString('.webp', $html, 'The showcase WebP underlay stays out of the kernel port.');

        $css = InlineCss::file('Modules/Griffe/assets/critical/griffe.css');

        foreach (['gr-slide__actions', 'gr-slide__visual', 'gr-slide__svg'] as $class) {
            $this->assertStringContainsString('.' . $class, $css, sprintf('Hero emits `%s` with no rule.', $class));
        }
    }

    #[Test]
    public function headingsShipMaskedLinesForTheReveal(): void
    {
        $html = self::bodyOf((new GriffeTheme())->homepage(self::homepageContext()));

        $this->assertStringContainsString('class="gr-anim"><span class="gr-anim__line"', $html);
    }

    /**
     * The opening hero ships revealed: its slide is already on stage in the
     * first paint, so its lines carry `gr-in` from the server and never wait
     * on the bundle, the observer, or any script position. Case slides keep
     * the mask — they unmask on activation, exactly as before.
     */
    #[Test]
    public function theHeroSlideShipsRevealedWhileCaseSlidesStayMasked(): void
    {
        $html = self::bodyOf((new GriffeTheme())->homepage(self::homepageContext()));

        $hero = substr($html, 0, (int) strpos($html, '<li class="gr-slide"'));

        $this->assertStringContainsString('gr-slide--active', $hero);
        $this->assertStringContainsString('class="gr-anim gr-in"><span class="gr-anim__line"', $hero, 'The hero must not depend on script timing for its visibility.');
        $this->assertStringNotContainsString('class="gr-anim"><span class="gr-anim__line"', $hero, 'No masked line may survive on the opening slide.');

        $cases = substr($html, (int) strpos($html, '<li class="gr-slide"'));

        $this->assertStringContainsString('class="gr-anim"><span class="gr-anim__line"', $cases, 'Hidden slides keep the mask until the slider reveals them.');
    }

    /**
     * The meta line carries a pre-built author anchor
     * (BlogArticleHelper::metaLine — the same bytes the starter renders raw
     * in st-article__meta), so the theme must pass it through: escaping it
     * prints the tag as visible text under the headline.
     */
    /**
     * The article headline sits above the fold, exactly like the homepage
     * hero: it ships `gr-in` from the server and never waits on the bundle
     * or the observer — a dead observer must not eat the headline.
     */
    #[Test]
    public function theArticleHeadlineShipsRevealed(): void
    {
        $html = (new GriffeTheme())->blogArticle(self::articleContext('<p>Body.</p>'));

        $this->assertStringContainsString('class="gr-anim gr-in"><span class="gr-anim__line"', $html);
        $this->assertStringNotContainsString('class="gr-anim"><span class="gr-anim__line"', $html, 'No masked line may survive above the fold.');
    }

    #[Test]
    public function blogArticlePassesTheMetaLineThroughUnescaped(): void
    {
        $html = (new GriffeTheme())->blogArticle(self::articleContext(
            '<p>Body.</p>',
            '2026-10-12 · Architektúra · <a href="/authors/nativa-dev">Nativa Dev</a>',
        ));

        $this->assertStringContainsString('<p class="gr-article__meta">2026-10-12', $html);
        $this->assertStringContainsString('<a href="/authors/nativa-dev">Nativa Dev</a>', $html);
        $this->assertStringNotContainsString('&lt;a', $html, 'The author anchor escaped into visible tag text.');
    }

    /**
     * Masked units keep a text boundary between them: the spans are blocks,
     * so a newline paints nothing — but without one the last word of a unit
     * and the first of the next copy as a single word ("ktoré"+"dokáže,").
     */
    #[Test]
    public function maskedLineUnitsStaySeparatedByWhitespace(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'One two three four five six'],
            blogHref: '/blog',
        );

        $html = (new GriffeTheme())->homepage($context);

        $this->assertStringContainsString(
            '</span></span>' . "\n" . '<span class="gr-anim gr-in">',
            $html,
            'Six words make two masked units; the boundary between them must survive copy.',
        );
    }

    #[Test]
    public function pageWrapShipsNoInlineScripts(): void
    {
        // Surfaces return bare sections; the single pageWrap (FrontendLayout's)
        // wraps them in a bare <main> — the frozen bundle owns every behaviour.
        $theme = new GriffeTheme();
        $home = $theme->pageWrap($theme->homepage(self::homepageContext()));

        $this->assertSame(1, substr_count($home, '<main class="gr-main">'), 'One wrap, one <main> — no nested mains.');
        $this->assertStringNotContainsString('<script', $home, 'No inline scripts — header, slider and reveal run from the bundle.');
        $this->assertStringNotContainsString('gr-slider.js', $home);

        $article = $theme->pageWrap($theme->blogArticle(self::articleContext(
            '<pre><code class="language-php">echo 1;</code></pre>',
        )));

        $this->assertStringNotContainsString('<script', $article, 'Even fenced pages ship no inline highlighter.');
    }

    #[Test]
    public function renderedDocumentDefersTheFrozenBundle(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);

        $this->assertStringContainsString(
            '<script src="/dist/griffe/',
            $html,
            'The document must defer the frozen bundle — without it griffe pages keep the critical CSS but lose every behaviour.',
        );
        $this->assertStringContainsString('defer></script>', $html, 'The bundle script stays deferred, never render-blocking.');
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
    public function slidesCarryTintsAndImageryWhenProvided(): void
    {
        $imaged = self::article('pic', 'Pictured Client');
        $imaged->image = '/img/case.jpg';

        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'Hero'],
            blogHref: '/blog',
            heroSrc: '/img/hero.jpg',
            heroSrcset: '/img/hero-800.jpg 800w',
            latestArticles: [$imaged, self::article('plain', 'Plain Client')],
        );

        $html = self::bodyOf((new GriffeTheme())->homepage($context));

        $this->assertStringContainsString('src="/img/hero.jpg"', $html);
        $this->assertStringContainsString('srcset="/img/hero-800.jpg 800w"', $html);
        $this->assertStringContainsString('src="/img/case.jpg"', $html);
        $this->assertStringContainsString('gr-slide__shade', $html, 'Imagery ships its legibility shade.');
        $this->assertSame(3, substr_count($html, '--gr-slide-bg:'), 'Every slide carries its tint.');

        $bare = self::bodyOf((new GriffeTheme())->homepage(self::homepageContext()));

        $this->assertStringNotContainsString('gr-slide__bg', $bare, 'No source, no <img> — the tint carries the slide.');
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

    private static function articleContext(string $bodyHtml, string $metaLine = ''): PageContext
    {
        return PageContext::forBlogArticle(
            Request::create('GET', '/blog/hello'),
            'griffe',
            'en',
            'hello',
            'Hello',
            $bodyHtml,
            $metaLine,
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
