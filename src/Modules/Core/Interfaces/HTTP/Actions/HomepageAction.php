<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Core\Application\Services\StructuredDataService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * Homepage action — hero + two feature sections (the blog series' two core
 * themes: "fewer layers" and "super fast PHP") + a dev/prod size ratio strip.
 *
 * Copy is bilingual through the Locales translation system (t()): /sk/
 * renders the Slovak homepage, / and /en/ the English one. Every feature
 * card links to the blog article that explains its theme.
 *
 * The feature grids carry data-cq hooks consumed by the ContainerQuery
 * fallback (task 10.10): on Chrome < 105 the JS fallback adds cq--w-*
 * classes that replicate the @container behaviour in CSS.
 *
 * Hero image is served from Cloudinary (the project's only allowed external
 * service) with a responsive srcset — Cloudinary sends its own long-lived
 * immutable cache headers, so the image never appears as a cache-miss
 * request in Lighthouse.
 */
#[Route(path: '/', method: 'GET', name: 'home')]
final class HomepageAction
{
    /** Cloudinary base for the hero image (cloud epithemic, blog folder). */
    private const string HERO_BASE = 'https://res.cloudinary.com/epithemic/image/upload/c_fill,f_webp,q_auto/v1773169415/blog/d1a18cb5ea2f538c0a8d06e4f6e74264_rdkl7a.webp';

    /**
     * Feature sections — section key → card keys (render order). The copy for
     * each key lives in the translation system (home.<key>_title/_text).
     *
     * @var array<string, list<string>>
     */
    private const array SECTIONS = [
        'layer1' => ['layer1_card1', 'layer1_card2', 'layer1_card3'],
        'layer2' => ['layer2_card1', 'layer2_card2', 'layer2_card3'],
    ];

    /**
     * Article link per card, locale → slug. Slugs are structural URLs, not
     * copy — they live here, not in the translation DB.
     *
     * @var array<string, array<string, string>>
     */
    private const array ARTICLE_LINKS = [
        'layer1_card1' => ['sk_SK' => 'filozofia-menej-vrstiev', 'en_US' => 'philosophy-fewer-layers'],
        'layer1_card2' => ['sk_SK' => 'filozofia-menej-vrstiev', 'en_US' => 'philosophy-fewer-layers'],
        'layer1_card3' => ['sk_SK' => 'c-rozsirenia-php-api', 'en_US' => 'php-c-extensions-api'],
        'layer2_card1' => ['sk_SK' => 'opcache-a-jit-prekompilacia', 'en_US' => 'opcache-and-jit-precompilation'],
        'layer2_card2' => ['sk_SK' => 'c-rozsirenia-php-api', 'en_US' => 'php-c-extensions-api'],
        'layer2_card3' => ['sk_SK' => 'filozofia-super-rychleho-php', 'en_US' => 'super-fast-php-philosophy'],
    ];

    /**
     * EN fallbacks for every home.* copy key — the seeded translations live
     * in locales.db (SeedData); these defaults keep the homepage readable
     * even when a key is missing (fresh install before migrate, or an admin
     * deletion — the migrator's INSERT OR IGNORE re-seeds deleted keys).
     *
     * @var array<string, string>
     */
    private const array COPY_DEFAULTS = [
        'home.hero_title'          => 'Minimal PHP 8.5 Starter Kit',
        'home.hero_subtitle'       => 'A tiny, auditable foundation: modular monolith, per-module SQLite, attribute-based routing and strict security — built from scratch.',
        'home.layer1_title'        => 'Fewer Layers',
        'home.layer1_card1_title'  => 'Zero dependencies',
        'home.layer1_card1_text'   => 'No Composer runtime, no framework. A hand-rolled PSR-4 autoloader boots the app even without vendor/ — the whole production runtime is 5 PHP extensions.',
        'home.layer1_card2_title'  => 'Readable surface',
        'home.layer1_card2_text'   => 'C-pattern: no magic methods, no service locators, no generated maps. Every line is readable and auditable — no black box.',
        'home.layer1_card3_title'  => 'Per-module SQLite',
        'home.layer1_card3_text'   => 'One database file per module — users.db, config.db, locales.db. No infrastructure, no ORM, no migration maze.',
        'home.layer2_title'        => 'Super Fast PHP',
        'home.layer2_card1_title'  => 'PHP 8.5 + OPcache/JIT',
        'home.layer2_card1_text'   => 'Language-level precompilation: OPcache keeps opcodes in memory, JIT compiles hot paths to native machine code.',
        'home.layer2_card2_title'  => 'Data through C',
        'home.layer2_card2_text'   => 'SQLite is pure C — the fastest path to data with no ORM layer. PHP built-ins are precompiled C libraries.',
        'home.layer2_card3_title'  => 'No cache layer',
        'home.layer2_card3_text'   => 'We dropped the cache layer and hit 100/100 PageSpeed. Speed comes from removing layers, not from caching.',
        'home.ratio_value'         => '215 MB dev → 1.7 MB prod',
        'home.ratio_label'         => '~126:1 — the entire production runtime is smaller than one phone photo.',
        'home.read_article'        => 'Read article',
        'home.cta_blog'            => 'Read the blog',
        'home.cta_admin'           => 'Open admin',
    ];

    public function handle(Request $request): Response
    {
        $locale = LocalesServices::locale()->current()->code();
        $theme = (new ThemeResolver())->resolve($request);

        // One ThemeKernel call per surface — page composition lives in the
        // theme module (theme-as-module); the action fetches data + copy.
        $copy = [];
        foreach (array_merge(['home.hero_title', 'home.hero_subtitle', 'home.eyebrow', 'home.ratio_value', 'home.ratio_label', 'home.read_article', 'home.cta_blog', 'home.cta_admin'], self::sectionCopyKeys()) as $key) {
            $copy[$key] = $this->t($key);
        }
        $links = [];
        foreach (['layer1_card1', 'layer1_card2', 'layer1_card3', 'layer2_card1', 'layer2_card2', 'layer2_card3'] as $cardKey) {
            $links[$cardKey] = $this->articleHref($locale, $cardKey);
        }
        $hero = $this->heroSrcset();

        $content = ThemeKernel::for($theme)->homepage(
            PageContext::forHomepage(
                $request,
                $theme,
                $locale,
                copy: $copy,
                links: $links,
                cards: self::SECTIONS,
                latestArticles: 'moonshine' === $theme ? BlogServices::articles()->all($locale) : [],
                blogHref: ('en_US' === $locale ? '/en' : '') . '/blog',
                heroSrc: $hero['src'],
                heroSrcset: $hero['srcset'],
            ),
        );

        $homeUrl = $request->absoluteUrl($this->homePath($request));
        $baseUrl = $request->absoluteUrl('');

        return Response::html(FrontendLayout::render(
            'Nativa Starter — Home',
            $content,
            currentPath: $request->getPathInfo(),
            canonicalUrl: $homeUrl,
            hreflangLinks: [
                'sk' => $baseUrl . '/',
                'en' => $baseUrl . '/en/',
                'x-default' => $baseUrl . '/',
            ],
            ogType: 'website',
            ogImage: self::HERO_BASE,
            structuredData: StructuredDataService::website('Nativa Starter', $homeUrl),
            theme: $theme,
        ));
    }

    /**
     * Copy keys per feature section (kernel-resolved into the payload).
     *
     * @return list<string>
     */
    private static function sectionCopyKeys(): array
    {
        $keys = [];
        foreach (self::SECTIONS as $layer => $cards) {
            $keys[] = 'home.' . $layer . '_title';
            foreach ($cards as $cardKey) {
                $keys[] = 'home.' . $cardKey . '_title';
                $keys[] = 'home.' . $cardKey . '_text';
            }
        }

        return $keys;
    }

    /**
     * Locale-aware article URL for a card — /blog/{slug} on the default
     * locale, /en/blog/{slug} for en_US (same prefix scheme as the blog
     * cards). Returns '' when the card has no mapped article.
     */
    private function articleHref(string $locale, string $cardKey): string
    {
        $slug = self::ARTICLE_LINKS[$cardKey][$locale] ?? null;

        return null === $slug ? '' : ContentServices::mapper()->urlPrefix($locale) . '/blog/' . $slug;
    }

    /**
     * Homepage copy with an EN fallback — the translation system resolves the
     * active locale (sk_SK on /sk/, en_US on / and /en/).
     */
    private function t(string $key, string $default = ''): string
    {
        return LocalesServices::t($key, self::COPY_DEFAULTS[$key] ?? $default);
    }

    /**
     * Locale-aware canonical home path — bare / for the default locale,
     * /{lang}/ for every other locale (the same prefix scheme the blog uses).
     */
    private function homePath(Request $request): string
    {
        $prefix = ContentServices::mapper()->urlPrefix(BlogArticlesService::requestLocale($request));

        return '' === $prefix ? '/' : $prefix . '/';
    }

    /**
     * Build the src + srcset for the Cloudinary hero.
     *
     * @return array{src: string, srcset: string}
     */
    private function heroSrcset(): array
    {
        // CSS constrains the hero to max-width: 720px, so the srcset has
        // fine-grained steps around that size (480/640/720/800/960/1440) —
        // the browser picks the closest variant instead of over-downloading
        // the 960w image for a ~720px slot (Lighthouse image delivery).
        // 800w covers mobile DPR 1.75 (412px viewport -> 721px needed).
        $variants = [
            480  => 'w_480,h_240',
            640  => 'w_640,h_320',
            720  => 'w_720,h_360',
            800  => 'w_800,h_400',
            960  => 'w_960,h_480',
            1440 => 'w_1440,h_720',
        ];

        $src = $this->heroUrl('w_720,h_360');
        $srcset = [];

        foreach ($variants as $width => $transform) {
            $srcset[] = $this->heroUrl($transform) . " {$width}w";
        }

        return [
            'src'    => $src,
            'srcset' => implode(', ', $srcset),
        ];
    }

    private function heroUrl(string $transform): string
    {
        return str_replace('c_fill,', 'c_fill,' . $transform . ',', self::HERO_BASE);
    }
}
