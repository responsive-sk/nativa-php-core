<?php

declare(strict_types = 1);

namespace Modules\Themes\Boson\Tests;

use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Themes\Boson\Theme\BosonTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BosonThemeTest — the boson port's contracts.
 *
 * The reference is a Lit/Twig site; this port is server-rendered PHP plus one
 * vendored script. Three things therefore need pinning that a screenshot
 * cannot: the checkbox menu's DOM order (the panel is reached with `~`), the
 * fact that every class the markup emits is `bo-` prefixed (the stylesheet is
 * one file, and an unprefixed name would silently fall out of it), and that
 * no surface names a remote host (the reference's artwork is deliberately
 * absent rather than fetched at runtime).
 *
 * Skips when the module is not installed, like every module test here: the
 * kernel-only suite must stay green without it.
 */
final class BosonThemeTest extends TestCase
{
    private const string MODULE = 'Boson';

    private const string THEME = 'boson';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `boson` theme ships with this module.');
        }
    }

    #[Test]
    public function discoveryFindsTheTheme(): void
    {
        $this->assertTrue(ThemeRegistry::has(self::THEME), 'Boson ships a theme; discovery did not find it.');

        $theme = ThemeRegistry::get(self::THEME);

        $this->assertInstanceOf(BosonTheme::class, $theme);
        $this->assertSame('boson', $theme->bundle());
        $this->assertSame('boson', $theme->jsKey());
        $this->assertSame('bo', $theme->prefix());
        $this->assertSame('', $theme->themeInitScript(), 'The shared pre-paint block stays the kernel\'s bytes.');
        $this->assertFileExists(APP_BASE_PATH . '/modules/Themes/Boson/assets/critical/boson.css');
    }

    /**
     * The behaviour script is inline (there is no Vite package), and it is the
     * only script the wrap adds — FrontendLayout owns the head's pre-paint
     * block.
     */
    #[Test]
    public function pageWrapShipsTheBehaviourScriptOnce(): void
    {
        $theme = new BosonTheme();
        $wrapped = $theme->pageWrap($theme->homepage(self::homepageContext()));

        $this->assertSame(1, substr_count($wrapped, '<main class="bo-main">'), 'One wrap, one <main>.');
        $this->assertSame(1, substr_count($wrapped, '<script'), 'One behaviour script, no per-surface scripts.');
        $this->assertStringContainsString('nonce="__CSP_NONCE_PLACEHOLDER__"', $wrapped);
        $this->assertStringContainsString('initHeader()', $wrapped);
        $this->assertStringContainsString('initAccordion()', $wrapped);
        $this->assertStringContainsString('initCode()', $wrapped);
    }

    /**
     * The checkbox contract: one `~` selector reaches both the panel and the
     * label, so the input must be the header's first child. The CSS uses the
     * same order, which is pinned beside the DOM here.
     */
    #[Test]
    public function theToggleSitsBeforeThePanelAndTheLabel(): void
    {
        $header = (new BosonTheme())->header(self::shell());

        $input = strpos($header, 'id="bo-nav-toggle"');
        $panel = strpos($header, 'id="bo-mobile-menu"');
        $label = strpos($header, 'for="bo-nav-toggle"');

        $this->assertIsInt($input);
        $this->assertIsInt($panel);
        $this->assertIsInt($label);
        $this->assertLessThan($panel, $input, 'The input must precede the panel: `~` cannot reach a preceding sibling.');
        $this->assertLessThan($label, $input);

        $css = InlineCss::file('Modules/Boson/assets/critical/boson.css');

        $this->assertStringContainsString('.bo-burger-input:checked ~ .bo-mobile', $css);
        $this->assertStringContainsString('.bo-burger-input:checked ~ .bo-header__bar', $css);
    }

    #[Test]
    public function theHeaderRendersTheShellNavAndTheAdminPrefix(): void
    {
        $header = (new BosonTheme())->header(self::shell());

        $this->assertStringContainsString('bo-header__brand', $header);
        $this->assertStringContainsString('>Boson Test<', $header);
        $this->assertStringContainsString('href="/blog"', $header);
        $this->assertStringContainsString('aria-current="page"', $header, 'The active nav item is announced.');
        $this->assertStringContainsString('bo-nav__sub', $header, 'Children render as a ruled submenu.');
        $this->assertStringContainsString('href="' . AdminRoutePolicy::prefix() . '"', $header, 'The admin link comes from the policy, never a literal.');
        $this->assertStringContainsString('bo-locale__link--active', $header);
        $this->assertStringContainsString('bo-search__input', $header);
        $this->assertStringContainsString('bo-mobile__link', $header);
    }

    /**
     * The monogram follows the configured site name. It used to be a literal
     * `N` carried over from the reference site, so a site called
     * `responsive.sk` wore someone else's initial beside its own wordmark.
     */
    #[Test]
    public function theMonogramFollowsTheSiteName(): void
    {
        $header = (new BosonTheme())->header(
            ShellContext::from('responsive.sk', [], [], '/', null, 'sk', [], false),
        );

        $this->assertStringContainsString(
            '<span class="bo-header__mark" aria-hidden="true">R</span>',
            $header,
        );
    }

    /**
     * The split/closing bands derive the blog URL when the context carries
     * none (`$context->blogHref === ''`). They were declared static and reached
     * for `$this->blogBase()` — a latent `Using $this when not in object
     * context` 500 that stayed hidden only because the homepage always passes
     * a blog href, so PHP never evaluated the fallback branch.
     */
    #[Test]
    public function theBandsWithoutABlogHrefFallBackToTheLocalePrefix(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'sk',
            copy: ['title' => 'Boson Test'],
            blogHref: '',
        );

        $html = (new BosonTheme())->homepage($context);

        $this->assertStringContainsString('bo-split', $html);
        $this->assertStringContainsString('bo-cta', $html);
        $this->assertStringContainsString('href="/blog"', $html, 'The fallback resolves through the locale prefix, not a literal.');
    }

    #[Test]
    public function aHeaderWithoutNavStillRendersTheBrandAndNoToggle(): void
    {
        $header = (new BosonTheme())->header(ShellContext::from('Boson Test', [], [], '/blog', null, 'sk', [], false));

        $this->assertStringContainsString('bo-header__brand', $header);
        $this->assertStringNotContainsString('bo-nav-toggle', $header);
        $this->assertStringNotContainsString('bo-mobile', $header);
    }

    /**
     * Every band from the reference is present, and the accordion ships item 1
     * open so the section reads without the script.
     */
    #[Test]
    public function theHomepageRendersEveryBand(): void
    {
        $html = (new BosonTheme())->homepage(self::homepageContext());

        foreach ([
            'bo-hero',
            'bo-segment',
            'bo-surfaces',
            'bo-stacks',
            'bo-columns',
            'bo-accordion',
            'bo-compare',
            'bo-split',
            'bo-principles',
            'bo-cta',
            'bo-discover',
        ] as $block) {
            $this->assertStringContainsString('class="' . $block, $html, 'Missing band: ' . $block);
        }

        $this->assertSame(5, substr_count($html, 'data-bo-accordion-item'), 'Five "under the hood" items.');
        $this->assertSame(1, substr_count($html, 'bo-accordion__item--open'), 'Exactly one item ships open.');
        $this->assertStringContainsString('aria-expanded="true"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    /**
     * The kernel's copy wins over the defaults, and a hostile value cannot
     * leave the attribute or the text node it was placed in.
     */
    #[Test]
    public function theHomepageReadsTheCopyFirstAndEscapesIt(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'sk',
            copy: [
                'home.hero_title_1' => 'Title <script>alert(1)</script>',
                'home.hero_title_2' => 'Second "line"',
                'home.cta_blog' => 'Go & read',
            ],
        );

        $html = (new BosonTheme())->homepage($context);

        $this->assertStringContainsString('Title &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('Title <script>', $html);
        $this->assertStringContainsString('Second &quot;line&quot;', $html);
        $this->assertStringContainsString('Go &amp; read', $html);
    }

    #[Test]
    public function theHeroUsesTheKernelsBlogHref(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'en',
            blogHref: '/en/blog',
        );

        $this->assertStringContainsString('href="/en/blog"', (new BosonTheme())->homepage($context));
    }

    /**
     * The index slices by page/perPage — the action hands the theme the full
     * list and the theme owns the window.
     */
    #[Test]
    public function theBlogIndexSlicesAndPaginates(): void
    {
        $html = (new BosonTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'sk',
            [self::article('a', 'Alpha'), self::article('b', 'Beta'), self::article('c', 'Gamma')],
            page: 2,
            perPage: 2,
        ));

        $this->assertStringNotContainsString('Alpha', $html, 'Page 2 must not repeat page 1.');
        $this->assertStringNotContainsString('Beta', $html, 'Page 2 is the second window, not the first.');
        $this->assertStringContainsString('Gamma', $html);
        $this->assertStringContainsString('href="/blog/c"', $html, 'Article links are relative to the locale.');
        $this->assertStringContainsString('bo-pager__page--current" aria-current="page" href="/blog?page=2">2', $html);
        $this->assertStringContainsString('href="/blog">1</a>', $html);
    }

    #[Test]
    public function theEnglishBlogLinksCarryTheLocalePrefix(): void
    {
        $html = (new BosonTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/en/blog'),
            self::THEME,
            'en',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringContainsString('href="/en/blog/a"', $html);
    }

    #[Test]
    public function theBlogSearchRendersAndEscapesTheQuery(): void
    {
        $html = (new BosonTheme())->blogSearch(PageContext::forBlogSearch(
            Request::create('GET', '/blog?q=%3Cb%3E'),
            self::THEME,
            'sk',
            '<b>',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringContainsString('Search: &lt;b&gt;', $html);
        $this->assertStringNotContainsString('Search: <b>', $html);
        $this->assertStringContainsString('value="&lt;b&gt;"', $html);
        $this->assertStringContainsString('1 result', $html);
    }

    /**
     * Raw-by-contract fields pass through; the headline and the TOC labels do
     * not.
     */
    #[Test]
    public function theArticlePassesRawFieldsAndEscapesItsOwnMarkup(): void
    {
        $html = (new BosonTheme())->blogArticle(self::articleContext());

        $this->assertStringContainsString('<p>Body <em>raw</em>.</p>', $html, 'bodyHtml is HTML by contract.');
        $this->assertStringContainsString('<a href="/authors/x">By X</a>', $html, 'metaLine carries a pre-built anchor.');
        $this->assertStringContainsString('bo-article__tags', $html);
        $this->assertStringContainsString('bo-toc__link', $html);
        $this->assertStringContainsString('data-heading="first-part"', $html);
        $this->assertStringContainsString('open', $html, 'The contents rail must ship open.');
        $this->assertStringContainsString('2 views', $html);
    }

    #[Test]
    public function theArticleTitleIsEscaped(): void
    {
        $html = (new BosonTheme())->blogArticle(self::articleContext('<h1>Injected</h1>'));

        $this->assertStringNotContainsString('<h1>Injected</h1>', $html);
        $this->assertStringContainsString('&lt;h1&gt;Injected&lt;/h1&gt;', $html);
    }

    #[Test]
    public function theTagAndCategorySurfacesRenderTheirCloudsAndCards(): void
    {
        $theme = new BosonTheme();

        $tags = $theme->blogTags(PageContext::forBlogTags(
            Request::create('GET', '/blog/tags/php'),
            self::THEME,
            'sk',
            ['php', 'web'],
            ['php' => 3],
            'php',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringContainsString('bo-chip--active', $tags);
        $this->assertStringContainsString('php (3)', $tags);
        $this->assertStringContainsString('href="/blog/tags/web"', $tags);
        $this->assertStringContainsString('Alpha', $tags);

        $categories = $theme->blogCategories(PageContext::forBlogCategories(
            Request::create('GET', '/blog/categories/news'),
            self::THEME,
            'sk',
            ['news'],
            ['news' => 1],
            'news',
            [self::article('b', 'Beta')],
        ));

        $this->assertStringContainsString('bo-chip--active', $categories);
        $this->assertStringContainsString('Beta', $categories);
    }

    #[Test]
    public function theAuthorProfileRendersTheProfileAndTheGrid(): void
    {
        $html = (new BosonTheme())->authorProfile(PageContext::forAuthorProfile(
            Request::create('GET', '/authors/x'),
            self::THEME,
            'sk',
            ['id' => 'x', 'name' => 'Ada', 'avatar' => '/storage/a.png'],
            [self::article('a', 'Alpha')],
            '2026-01-01',
        ));

        $this->assertStringContainsString('bo-author__avatar', $html);
        $this->assertStringContainsString('Ada', $html);
        $this->assertStringContainsString('Member since 2026-01-01', $html);
        $this->assertStringContainsString('Alpha', $html);
    }

    #[Test]
    public function theErrorContentCarriesStatusAndEscapesDetail(): void
    {
        $html = (new BosonTheme())->errorContent(404, 'Not found', '<script>x</script>');

        $this->assertStringContainsString('Error 404', $html);
        $this->assertStringContainsString('Not found', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x', $html);
    }

    /**
     * Every class the theme writes itself carries `bo-`. The highlighter's
     * `tok-*` spans are generated by the script, never server-rendered, so
     * they are correctly absent here; the body shell class belongs to the
     * layout.
     */
    #[Test]
    public function everyClassTheMarkupEmitsIsPrefixed(): void
    {
        $theme = new BosonTheme();
        $pages = [
            $theme->homepage(self::homepageContext()),
            $theme->blogIndex(self::indexContext()),
            $theme->blogSearch(PageContext::forBlogSearch(
                Request::create('GET', '/blog?q=x'),
                self::THEME,
                'sk',
                'x',
                [self::article('a', 'Alpha')],
            )),
            $theme->blogArticle(self::articleContext()),
            $theme->blogTags(PageContext::forBlogTags(
                Request::create('GET', '/blog/tags'),
                self::THEME,
                'sk',
                ['php'],
                ['php' => 1],
            )),
            $theme->blogCategories(PageContext::forBlogCategories(
                Request::create('GET', '/blog/categories'),
                self::THEME,
                'sk',
                ['news'],
                ['news' => 1],
            )),
            $theme->authorProfile(PageContext::forAuthorProfile(
                Request::create('GET', '/authors/x'),
                self::THEME,
                'sk',
                ['id' => 'x', 'name' => 'Ada', 'avatar' => null],
                [],
                '',
            )),
            $theme->errorContent(500, 'Error', 'Detail.'),
            $theme->header(self::shell()),
            $theme->footer(self::shell()),
        ];

        $stray = [];

        foreach ($pages as $html) {
            preg_match_all('/class="([^"]*)"/', $html, $matches);

            foreach ($matches[1] as $value) {
                foreach (preg_split('/\s+/', trim($value)) ?: [] as $class) {
                    if ('' === $class || str_starts_with($class, 'bo-')) {
                        continue;
                    }

                    $stray[$class] = true;
                }
            }
        }

        $this->assertSame([], array_keys($stray), 'Unprefixed classes would fall out of the one boson stylesheet: ' . implode(', ', array_keys($stray)));
    }

    /**
     * The reference's CSS and artwork are not fetched at runtime, and the
     * inline block cannot carry an at-rule InlineCss strips.
     */
    #[Test]
    public function theStylesheetIsSelfContained(): void
    {
        $css = InlineCss::file('Modules/Boson/assets/critical/boson.css');

        $this->assertStringContainsString(':root', $css);
        $this->assertStringContainsString('--bo-brand: #f93904;', $css);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('@charset', $css);
        $this->assertStringNotContainsString('@layer', $css);
        $this->assertStringNotContainsString('/images/', $css);
        $this->assertStringNotContainsString('fonts.googleapis.com', $css);
        $this->assertStringNotContainsString('http://', $css);
        $this->assertStringNotContainsString('https://', $css);
    }

    #[Test]
    public function noRenderedSurfaceNamesARemoteHost(): void
    {
        $theme = new BosonTheme();
        $html = $theme->homepage(self::homepageContext())
            . $theme->header(self::shell())
            . $theme->footer(self::shell());

        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
        $this->assertStringNotContainsString('//cdn', $html);
    }

    private static function homepageContext(): PageContext
    {
        return PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'sk',
            copy: ['title' => 'Boson Test'],
        );
    }

    private static function indexContext(): PageContext
    {
        return PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'sk',
            [self::article('a', 'Alpha')],
        );
    }

    private static function articleContext(string $title = 'A title'): PageContext
    {
        return PageContext::forBlogArticle(
            Request::create('GET', '/blog/a'),
            self::THEME,
            'sk',
            'a',
            $title,
            '<p>Body <em>raw</em>.</p>',
            '<a href="/authors/x">By X</a>',
            '<a href="/blog/tags/php">php</a>',
            '',
            '',
            [
                ['level' => 2, 'text' => 'First part', 'anchor' => 'first-part'],
                ['level' => 3, 'text' => 'Detail', 'anchor' => 'detail'],
            ],
            [],
            2,
        );
    }

    private static function shell(): ShellContext
    {
        return ShellContext::from(
            'Boson Test',
            [
                new ShellNavItem('Home', '/', true),
                new ShellNavItem('Blog', '/blog', false, false, [
                    new ShellNavItem('PHP', '/blog/tags/php'),
                ]),
            ],
            [new ShellNavItem('Privacy', '/privacy')],
            '/blog',
            ['sk' => '/blog', 'en' => '/en/blog'],
            'sk',
            ['sk', 'en'],
            false,
        );
    }

    private static function article(string $slug, string $title): object
    {
        return (object) [
            'slug' => $slug,
            'title' => $title,
            'date' => '2026-01-01',
            'category' => 'News',
            'author' => 'Ada',
            'excerpt' => 'An excerpt.',
            'image' => '/storage/cover.png',
        ];
    }
}
