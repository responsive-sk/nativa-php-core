#!/usr/bin/env php
<?php

declare(strict_types = 1);

/*
 * render-theme-pages.php — render the theme demo pages from the themes.
 *
 * The gallery in nativa-themes publishes static HTML, and hand-written markup
 * drifts from the PHP that actually emits it (RULES.md there records the first
 * time it did). This script removes the second author: it builds a PageContext
 * from one fixture, calls the theme's own method, wraps it with the kernel's
 * real layout, and writes the document.
 *
 *   php bin/build/render-theme-pages.php --out=/path/to/nativa-themes
 *   php bin/build/render-theme-pages.php --theme=boson --out=/tmp/demo
 *   php bin/build/render-theme-pages.php --locale=sk --out=/tmp/demo
 *   php bin/build/render-theme-pages.php --check --out=/path/to/nativa-themes
 *
 * Two locales, one shape. English renders to `<theme>/<surface>/`, Slovak to
 * `<theme>/sk/<surface>/` — Slovak is the project's default locale, so its
 * pages are the ones that exercise the font set: a face without `č`, `ľ` or
 * `ô` falls back mid-sentence there and nowhere on the English pages.
 *
 * `--check` compares bytes and exits 1 on any difference, so a theme change
 * that was not re-rendered fails instead of shipping a stale demo.
 *
 * Output is deterministic: both fixtures are static (no clock, no database) and
 * the documents carry no nonce, no admin chrome and no absolute URL.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/init.php';

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;

/**
 * Demo locale => the directory it renders into under `<theme>/`, and its fixture.
 *
 * The canonical code (`sk_SK`) is not written here: it belongs to the mapper
 * that owns the locale→directory rule, so `localeCode()` asks it instead of
 * this file keeping its own copy of the answer.
 */
const LOCALES = [
    'en' => ['dir' => '', 'fixture' => 'themes-demo.php'],
    'sk' => ['dir' => 'sk/', 'fixture' => 'themes-demo-sk.php'],
];

/**
 * Demo surface => the theme method that renders it.
 *
 * One table instead of a `match` per call site: the list of surfaces a demo
 * page exists for is also the list of methods a theme may implement, and a
 * second spelling of either is how the two drift.
 */
const SURFACES = [
    'home' => 'homepage',
    'blog' => 'blogIndex',
    'article' => 'blogArticle',
    'tags' => 'blogTags',
    'categories' => 'blogCategories',
    'author' => 'authorProfile',
    'search' => 'blogSearch',
];

/**
 * Themes `--theme=all` does not render, each for a measured reason.
 *
 * Erik: its gallery pages predate this generator and are tracked; rendering
 * into that directory would overwrite hand-written work without a diff.
 *
 * Griffe: its homepage is `<hero-layout>` + `<horizontal-scroll-hero>` filled
 * from attributes by the frontend package's web components, so a static render
 * is 53 characters of visible text. Measured with `shoot`: the page loads, the
 * body is empty, and the demo would claim a theme works when its composition
 * is missing. Render it explicitly with `--theme=griffe` once that is solved.
 *
 * @var array<string, string> theme name => why it is skipped
 */
const NOT_RENDERED = [
    'erik' => 'hand-written gallery pages; rendering would overwrite them',
    'griffe' => 'homepage is composed by JS components; a static render is empty',
];

/**
 * Refused even when asked for by name — the directory is tracked and
 * hand-written, so there is nothing a render could do but destroy it.
 */
const REFUSED = ['erik'];

function fail(string $message): never
{
    fwrite(STDERR, 'render-theme-pages: ' . $message . "\n");
    exit(1);
}

function out(string $message): void
{
    fwrite(STDOUT, $message . "\n");
}

/** @return array<string, mixed> */
function fixture(string $locale): array
{
    $path = dirname(__DIR__, 2) . '/fixtures/' . LOCALES[$locale]['fixture'];

    if (!is_file($path)) {
        fail('fixture not found: ' . $path);
    }

    /** @var array<string, mixed> $data */
    $data = require $path;

    return $data;
}

/**
 * The theme's method for a surface, or null when it does not implement it.
 */
function surfaceContent(string $surface, object $theme, PageContext $context): ?string
{
    $method = SURFACES[$surface] ?? null;

    if (null === $method || !method_exists($theme, $method)) {
        return null;
    }

    /** @var string $html */
    $html = $theme->{$method}($context);

    return $html;
}

/** @param array<string, mixed> $data */
function contextFor(string $surface, string $theme, array $data): PageContext
{
    $locale = (string) $data['locale'];
    $request = Request::create('GET', '/' . $surface);
    $articles = $data['articles'];
    $counts = $data['counts'];
    $total = count($articles);

    return match ($surface) {
        'home' => PageContext::forHomepage(
            request: $request,
            theme: $theme,
            locale: $locale,
            copy: [],
            links: [],
            cards: [],
            blogHref: '/blog',
            latestArticles: array_slice($articles, 0, 3),
        ),
        'blog' => PageContext::forBlogIndex(
            request: $request,
            theme: $theme,
            locale: $locale,
            articles: $articles,
            viewCounts: [],
            page: 1,
            perPage: 6,
        ),
        'article' => PageContext::forBlogArticle(
            request: $request,
            theme: $theme,
            locale: $locale,
            slug: (string) $data['article']['slug'],
            title: (string) $data['article']['title'],
            bodyHtml: (string) $data['body'],
            metaLine: (string) $data['article']['meta_line'],
            tagChips: (string) $data['tag_chips'],
            coverImage: (string) $data['article']['cover'],
            coverUrl: '',
            headings: $data['headings'],
            meta: [],
            views: (int) $data['article']['views'],
        ),
        'tags' => PageContext::forBlogTags(
            request: $request,
            theme: $theme,
            locale: $locale,
            tags: $data['tags'],
            counts: $counts,
            articles: $articles,
            totalArticles: $total,
            totalPages: 1,
        ),
        'categories' => PageContext::forBlogCategories(
            request: $request,
            theme: $theme,
            locale: $locale,
            categories: $data['categories'],
            counts: $counts,
            articles: $articles,
            totalArticles: $total,
            totalPages: 1,
        ),
        'author' => PageContext::forAuthorProfile(
            request: $request,
            theme: $theme,
            locale: $locale,
            author: $data['author'],
            articles: $articles,
            memberSince: (string) $data['member_since'],
            totalArticles: $total,
            totalPages: 1,
        ),
        'search' => PageContext::forBlogSearch(
            request: $request,
            theme: $theme,
            locale: $locale,
            query: 'themes',
            results: array_slice($articles, 0, 3),
        ),
        default => fail('unknown surface: ' . $surface),
    };
}

/**
 * Inline the built behaviour script.
 *
 * A tree that ran `make assets` ships `<script src="/dist/<bundle>/…">`; the
 * demo may not depend on that path (GitHub Pages serves the gallery from a
 * subdirectory), and a page that names a missing bundle is a 404 the gallery
 * would publish. Inlining the same bytes keeps the demo self-contained.
 */
function inlineBuiltScript(string $html, string $bundle): string
{
    $pattern = '#<script src="/dist/' . preg_quote($bundle, '#') . '/assets/[^"]+"[^>]*></script>\n?#';

    return (string) preg_replace_callback($pattern, static function (array $match) use ($bundle): string {
        if (1 !== preg_match('#src="(/dist/[^"]+)"#', $match[0], $src)) {
            return '';
        }

        $file = APP_BASE_PATH . '/public' . $src[1];

        if (!is_file($file)) {
            return '';
        }

        return "<script>\n" . (string) file_get_contents($file) . "\n</script>\n";
    }, $html);
}

/** The demo pages are not all equally deep, so a root path becomes <depth>../<path>. */
function relative(string $url, int $depth): string
{
    return str_repeat('../', $depth) . ltrim($url, '/');
}

/**
 * The language switcher on a demo page, as demo URLs.
 *
 * The themes build these links from the shell; here they are computed from the
 * page's own position, so `sk` on an English page opens the Slovak page of the
 * SAME surface instead of the theme's home. Passed as ready-made relative
 * paths — `rewrite()` only touches root-absolute hrefs, so these survive.
 *
 * Keyed by the FULL locale code (`sk_SK`), because that is what the shell's
 * `availableLocales` carries and what every theme looks the href up by; keyed
 * by `sk`, the lookup misses and the theme silently falls back to the locale
 * prefix — both links pointing at the same page.
 *
 * @return array<string, string>
 */
function demoLocaleLinks(string $locale, string $surface, int $depth): array
{
    $suffix = 'home' === $surface ? '' : $surface . '/';
    $up = str_repeat('../', $depth - 1);

    return 'sk' === $locale
        ? [localeCode('sk') => './', localeCode('en') => $up . $suffix]
        : [localeCode('sk') => $up . 'sk/' . $suffix, localeCode('en') => './'];
}

/**
 * Rewrite every root-absolute URL, and point the theme's own navigation at the
 * demo pages that exist. Anything unmapped still resolves relative to the
 * gallery root, which is where the fonts, covers and avatar live.
 */
function rewrite(string $html, int $depth, bool $home = false): string
{
    // A surface page links to its siblings (`../blog/`); the home page links to
    // its children (`blog/`). Spelling both as `../blog/` sent every "Blog"
    // click on a theme's home to a directory the gallery does not publish.
    $sibling = $home ? '' : '../';

    /**
     * App path => the demo page that stands in for it.
     *
     * Applied to `href` AND `action`: the search forms post to the blog index
     * through `action="/en/blog"`, and rewriting only hrefs left them pointing
     * at `/en/blog`, a path this gallery never publishes — a control that looks
     * live and submits into a 404.
     */
    $surfaces = [
        '#^/(?:en/|sk/)?blog/tags(?:/|$)#' => $sibling . 'tags/',
        '#^/(?:en/|sk/)?blog/categories(?:/|$)#' => $sibling . 'categories/',
        '#^/(?:en/|sk/)?blog/search(?:/|$)#' => $sibling . 'search/',
        '#^/(?:en/|sk/)?blog(?:\?[^"]*)?$#' => $sibling . 'blog/',
        '#^/(?:en/|sk/)?blog/[a-z0-9-]+(?:/|$)#' => $sibling . 'article/',
        // Surfaces the gallery does not have: keep the control, drop the 404.
        '#^/(?:en/|sk/)?(?:docs|cookies|privacy|login|signup|admin)(?:/|$)#' => $home ? './' : '../',
        '#^/mark(?:/.*)?$#' => '#',
        '#^/(?:en/|sk/)?$#' => $home ? './' : '../',
    ];

    // Icons the gallery does not ship, the canonical URL of a page that has no
    // origin, and feed alternates: the demo publishes no feed, and a <link> to
    // one is a URL a reader (or a feed reader) follows into a 404. Dropping the
    // tags beats handing out paths that do not exist.
    $html = (string) preg_replace(
        '#<link[^>]+(?:apple-touch-icon|image/x-icon|rel="canonical"|type="application/(?:atom|rss)\+xml")[^>]*>\n?#',
        '',
        $html,
    );

    // The "Subscribe" block that pointed at those feeds: the links are gone, so
    // a paragraph left holding the word "Subscribe:" is a control with nowhere
    // to go. Matched by the class name every theme gives that block.
    $html = (string) preg_replace('#<p\b[^>]*class="[^"]*\bfeed\b[^"]*"[^>]*>[\s\S]*?</p>\n?#i', '', $html);

    $html = (string) preg_replace_callback(
        '#\b(href|action)="([^"]*)"#',
        static function (array $m) use ($surfaces): string {
            foreach ($surfaces as $pattern => $target) {
                if (1 === preg_match($pattern, $m[2])) {
                    return $m[1] . '="' . $target . '"';
                }
            }

            return $m[0];
        },
        $html,
    );

    // Attributes that still name a root path: href, src, srcset, action, content.
    $html = (string) preg_replace_callback(
        '#(\b(?:href|src|action)=")/([^"]*)"#',
        static fn (array $m): string => $m[1] . relative($m[2], $depth) . '"',
        $html,
    );

    // srcset carries a comma-separated list of URLs.
    $html = (string) preg_replace_callback(
        '#(\bsrcset=")([^"]*)"#',
        static function (array $m) use ($depth): string {
            $rewritten = array_map(
                static function (string $candidate) use ($depth): string {
                    $candidate = trim($candidate);
                    [$url, $descriptor] = array_pad(preg_split('/\s+/', $candidate) ?: [$candidate], 2, '');

                    return relative($url, $depth) . ('' === $descriptor ? '' : ' ' . $descriptor);
                },
                explode(',', $m[2]),
            );

            return $m[1] . implode(', ', array_filter($rewritten)) . '"';
        },
        $html,
    );

    // url(/fonts/…) inside the inlined stylesheet.
    $html = (string) preg_replace_callback(
        '#url\((/[^)\'"]+)\)#',
        static fn (array $m): string => 'url(' . relative($m[1], $depth) . ')',
        $html,
    );

    // The nonce is a server header's job; a static page has no CSP.
    return (string) preg_replace('#\s+nonce="__CSP_NONCE_PLACEHOLDER__"#', '', $html);
}

/**
 * The themes this script renders: the ones shipped by the theme catalogue.
 *
 * NOT every registered theme. `kernel` is a theme too, and it is not one of
 * the five this repository publishes — rendering it would write a sixth demo
 * directory that no gallery card links to.
 *
 * @return list<string>
 */
function themesToRender(?string $requested): array
{
    $registered = ThemeRegistry::all();
    $catalogue = APP_BASE_PATH . '/modules/Themes';
    $all = [];

    foreach (glob($catalogue . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $name = strtolower(basename($directory));

        if (isset($registered[$name])) {
            $all[] = $name;
        }
    }

    sort($all);

    if ([] === $all) {
        fail('no theme modules found in ' . $catalogue);
    }

    if (null === $requested || 'all' === $requested) {
        return array_values(array_filter(
            $all,
            static fn (string $name): bool => !isset(NOT_RENDERED[$name]),
        ));
    }

    if (in_array($requested, REFUSED, true)) {
        fail($requested . ': ' . NOT_RENDERED[$requested]);
    }

    if (!in_array($requested, $all, true)) {
        fail('unknown theme: ' . $requested . ' (have: ' . implode(', ', $all) . ')');
    }

    if (isset(NOT_RENDERED[$requested])) {
        out('warning: ' . $requested . ': ' . NOT_RENDERED[$requested] . ' (rendering anyway, as asked)');
    }

    return [$requested];
}

/** The canonical locale code (`sk` → `sk_SK`), asked of the mapper that owns it. */
function localeCode(string $locale): string
{
    return ContentServices::mapper()->code($locale);
}

/**
 * The locales this run renders: all of them, or the one asked for.
 *
 * @return list<string>
 */
function localesToRender(?string $requested): array
{
    if (null === $requested || 'all' === $requested) {
        return array_keys(LOCALES);
    }

    if (!isset(LOCALES[$requested])) {
        fail('unknown locale: ' . $requested . ' (have: ' . implode(', ', array_keys(LOCALES)) . ')');
    }

    return [$requested];
}

/**
 * Mark the locale this page is written in, the way `LocaleMiddleware` does for
 * a request.
 *
 * Without it the shell answers with the default locale on every page, so an
 * English demo rendered `<html lang="sk">` over English content and its own
 * switcher marked Slovak current. Guarded rather than required: a tree without
 * the Locales module has no locale answers, and therefore no switcher to be
 * wrong about.
 */
function activate(string $locale): void
{
    $service = \Modules\Locales\Interfaces\HTTP\LocalesServices::class;

    if (!class_exists($service)) {
        return;
    }

    $service::locale()->set(localeCode($locale));
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');
        $options[$key] = $value;
    }
}

$outDir = $options['out'] ?? null;

if (null === $outDir) {
    fail('--out=<dir> is required');
}

$check = isset($options['check']);
$themes = themesToRender($options['theme'] ?? null);
$locales = localesToRender($options['locale'] ?? null);
$written = 0;
$identical = 0;
$diverged = [];

foreach ($themes as $theme) {
    $module = ThemeRegistry::get($theme);

    if (null === $module) {
        fail('theme is not installed: ' . $theme);
    }

    foreach ($locales as $locale) {
        $data = fixture($locale);
        activate($locale);

        foreach (array_keys(SURFACES) as $surface) {
            $context = contextFor($surface, $theme, $data);
            $content = surfaceContent($surface, $module, $context);

            if (null === $content) {
                out(sprintf('skip   theme=%s locale=%s surface=%s (not implemented)', $theme, $locale, $surface));

                continue;
            }

            $relativeDirectory = LOCALES[$locale]['dir'] . ('home' === $surface ? '' : $surface . '/');
            $depth = substr_count($theme . '/' . $relativeDirectory, '/');

            $title = sprintf('%s — %s theme', 'Nativa', ucfirst($theme));
            $html = FrontendLayout::render(
                $title,
                $content,
                theme: $theme,
                currentPath: '/',
                localeLinks: demoLocaleLinks($locale, $surface, $depth),
                lang: $locale,
            );
            $html = inlineBuiltScript($html, $module->bundle());
            $html = rewrite($html, $depth, 'home' === $surface);

            $target = $outDir . '/' . $theme . '/' . $relativeDirectory . 'index.html';

            if ($check) {
                $existing = is_file($target) ? (string) file_get_contents($target) : null;

                if ($existing === $html) {
                    $identical++;
                } else {
                    $diverged[] = $target;
                }

                continue;
            }

            $directory = dirname($target);

            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                fail('cannot create ' . $directory);
            }

            file_put_contents($target, $html);
            $written++;

            out(sprintf('render theme=%s locale=%s page=%s bytes=%d', $theme, $locale, $surface, strlen($html)));
        }
    }
}

if ($check) {
    out(sprintf('check: identical=%d diverged=%d', $identical, count($diverged)));

    foreach ($diverged as $path) {
        out('  stale ' . $path);
    }

    exit([] === $diverged ? 0 : 1);
}

out(sprintf('pages=%d themes=%d locales=%d out=%s', $written, count($themes), count($locales), $outDir));
