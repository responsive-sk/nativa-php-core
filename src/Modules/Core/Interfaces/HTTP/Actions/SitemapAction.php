<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Application\Services\BlogAuthorResolver;
use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Application\Services\ContentLocaleMapper;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Pages\Application\Services\PageCrudService;

/**
 * SitemapAction — dynamic XML sitemap (Google sitemap protocol 0.9).
 *
 * Lists every public indexable URL with absolute URLs built from the
 * request (loopback-safe via Request::absoluteUrl, matching the feeds):
 * home + locale roots, the blog index, every article in BOTH locales
 * (default-locale /blog/{slug} + /en/blog/{slug}, the same URL scheme the
 * locale switcher and hreflang tags use), author profiles reachable from
 * articles, and the feed endpoints. Translated articles carry xhtml:link
 * hreflang alternates so Google sees each language version plus x-default.
 * All values are XML-escaped; output is deterministic per request.
 */
#[Route(path: '/sitemap.xml', method: 'GET', name: 'sitemap')]
final class SitemapAction
{
    public function handle(Request $request): Response
    {
        $base = $request->absoluteUrl('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
            . $this->staticEntries($base)
            . $this->articleEntries($base)
            . $this->pageEntries($base)
            . $this->authorEntries($base)
            . '</urlset>' . "\n";

        return Response::xml($xml, 'application/xml; charset=utf-8');
    }

    /**
     * Home (both locale roots) always; the blog index (both locales) and the
     * feeds only when the module that owns them is installed.
     *
     * The sitemap lists what this site SERVES. A kernel-only tree serves no
     * `/blog`, and a sitemap that advertises a 404 teaches a crawler to find
     * one. The check is a `class_exists` on the module's service rather than a
     * port read because the question is literally "is that module here"; the
     * ledger's answer for this surface is `M` — it belongs to the layer that
     * knows the app's surfaces, and it moves there once the module catalogue
     * can answer instead.
     */
    private function staticEntries(string $base): string
    {
        $mapper = ContentServices::mapper();
        $hasBlog = class_exists(BlogArticlesService::class);
        $entries = '';

        foreach ($mapper->locales() as $locale) {
            $prefix = $mapper->urlPrefix($locale);
            $entries .= $this->entry($base, '' === $prefix ? '/' : $prefix . '/', 'weekly', '1.0');

            if ($hasBlog) {
                $entries .= $this->entry($base, $prefix . '/blog', 'daily', '0.9');
            }
        }

        if (!$hasBlog) {
            return $entries;
        }

        // Feeds live under the default locale only — one canonical feed per
        // site, not one per language.
        $defaultPrefix = $mapper->urlPrefix($mapper->defaultLocale());

        return $entries
            . $this->entry($base, $defaultPrefix . '/blog/feed.atom', 'daily', '0.3')
            . $this->entry($base, $defaultPrefix . '/blog/feed.xml', 'daily', '0.3');
    }

    /**
     * Every article in every locale, newest first, with xhtml:link hreflang
     * alternates to the translated counterpart (front-matter 'translation'
     * key). The default locale owns the bare /blog/ prefix; each other locale
     * gets its own.
     */
    private function articleEntries(string $base): string
    {
        if (!class_exists(BlogArticlesService::class)) {
            return '';
        }

        $service = new BlogArticlesService(new BlogAuthorResolver(DatabaseConnectionManager::instance()));
        $mapper = ContentServices::mapper();
        $locales = $mapper->locales();
        $entries = '';

        foreach ($locales as $index => $locale) {
            $other = $locales[($index + 1) % \count($locales)];
            $entries .= $this->localeArticleEntries(
                $service->all($locale),
                $base,
                $this->blogPrefix($mapper, $locale),
                $this->blogPrefix($mapper, $other),
                $mapper->languageCode($locale),
                $mapper->languageCode($other),
            );
        }

        return $entries;
    }

    /**
     * Locale-scoped blog path prefix, always with a trailing slash — '/blog/'
     * for the default locale, '/en/blog/' for the others.
     */
    private function blogPrefix(ContentLocaleMapper $mapper, string $locale): string
    {
        return $mapper->urlPrefix($locale) . '/blog/';
    }

    /**
     * @param list<Article> $articles
     */
    private function localeArticleEntries(
        array $articles,
        string $base,
        string $selfPrefix,
        string $otherPrefix,
        string $language,
        string $otherLanguage,
    ): string {
        $entries = '';

        foreach ($articles as $article) {
            $loc = $base . $selfPrefix . $article->slug;

            $alternates = '<xhtml:link rel="alternate" hreflang="' . $language . '" href="' . FrontendLayout::e($loc) . '"/>' . "\n";

            $translation = $article->translation ?? '';
            if ('' !== $translation) {
                $alternates .= '<xhtml:link rel="alternate" hreflang="' . $otherLanguage . '" href="'
                    . FrontendLayout::e($base . $otherPrefix . $translation) . '"/>' . "\n";
            }

            $lastmod = $this->lastmod($article->date);
            $entries .= '  <url>' . "\n"
                . '    <loc>' . FrontendLayout::e($loc) . '</loc>' . "\n"
                . ($lastmod !== '' ? '    <lastmod>' . $lastmod . '</lastmod>' . "\n" : '')
                . '    <changefreq>weekly</changefreq>' . "\n"
                . '    <priority>0.8</priority>' . "\n"
                . $alternates
                . '  </url>' . "\n";
        }

        return $entries;
    }

    /**
     * Static pages (both locales), published only.
     */
    private function pageEntries(string $base): string
    {
        if (!class_exists(PageCrudService::class)) {
            return '';
        }

        $service = new PageCrudService();
        $mapper = ContentServices::mapper();
        $locales = $mapper->locales();
        $entries = '';

        foreach ($locales as $index => $locale) {
            $other = $locales[($index + 1) % \count($locales)];
            $entries .= $this->localePageEntries(
                $service,
                $base,
                $locale,
                $mapper->urlPrefix($locale),
                $mapper->urlPrefix($other),
                $mapper->languageCode($locale),
                $mapper->languageCode($other),
            );
        }

        return $entries;
    }

    /**
     * Published pages of one locale with hreflang alternates.
     */
    private function localePageEntries(
        PageCrudService $service,
        string $base,
        string $locale,
        string $prefix,
        string $otherPrefix,
        string $language,
        string $otherLanguage,
    ): string {
        $entries = '';

        foreach ($service->list($locale) as $page) {
            if (!$page['published']) {
                continue;
            }

            $loc = $base . $prefix . '/' . $page['slug'];

            $alternates = '<xhtml:link rel="alternate" hreflang="' . $language . '" href="' . FrontendLayout::e($loc) . '"/>' . "\n";

            // The alternate URL carries the OTHER locale's OWN slug — the
            // pair (`translation:`) is what makes /o-nas and /en/about one
            // page in two languages. A dangling key resolves to no pair.
            $pair = $service->translationOf($page['slug'], $locale);

            if (null !== $pair) {
                $alternates .= '<xhtml:link rel="alternate" hreflang="' . $otherLanguage . '" href="'
                    . FrontendLayout::e($base . $otherPrefix . '/' . $pair) . '"/>' . "\n";
            }

            $entries .= '  <url>' . "\n"
                . '    <loc>' . FrontendLayout::e($loc) . '</loc>' . "\n"
                . '    <changefreq>monthly</changefreq>' . "\n"
                . '    <priority>0.6</priority>' . "\n"
                . $alternates
                . '  </url>' . "\n";
        }

        return $entries;
    }

    /**
     * Author profiles reachable from any article, deduped — only pages the
     * site actually links to are listed. Each profile is emitted in BOTH
     * locales with xhtml:link alternates: the profile renders in any locale
     * (only its article grid is locale-filtered), and a profile listed once
     * under the default locale hides its translated version from the index.
     */
    private function authorEntries(string $base): string
    {
        if (!class_exists(BlogArticlesService::class)) {
            return '';
        }

        $service = new BlogArticlesService(new BlogAuthorResolver(DatabaseConnectionManager::instance()));
        $mapper = ContentServices::mapper();
        $slugs = [];

        foreach ($mapper->locales() as $locale) {
            foreach ($service->all($locale) as $article) {
                $profile = $article->profile;
                if (null !== $profile && '' !== $profile->slug) {
                    $slugs[strtolower($profile->slug)] = $profile->slug;
                }
            }
        }

        $entries = '';
        foreach ($slugs as $slug) {
            $entries .= $this->localeAuthorEntries($base, $mapper, $slug);
        }

        return $entries;
    }

    /**
     * One author profile emitted in every locale — a profile listed only under
     * the default locale would hide its translated version from the index.
     */
    private function localeAuthorEntries(string $base, ContentLocaleMapper $mapper, string $slug): string
    {
        $locales = $mapper->locales();
        $entries = '';

        foreach ($locales as $locale) {
            $other = $locales[(array_search($locale, $locales, true) + 1) % \count($locales)];
            $loc = $base . $mapper->urlPrefix($locale) . '/authors/' . $slug;
            $entries .= '  <url>' . "\n"
                . '    <loc>' . FrontendLayout::e($loc) . '</loc>' . "\n"
                . '    <changefreq>weekly</changefreq>' . "\n"
                . '    <priority>0.6</priority>' . "\n"
                . '    <xhtml:link rel="alternate" hreflang="' . $mapper->languageCode($locale) . '" href="'
                . FrontendLayout::e($loc) . '"/>' . "\n"
                . '    <xhtml:link rel="alternate" hreflang="' . $mapper->languageCode($other) . '" href="'
                . FrontendLayout::e($base . $mapper->urlPrefix($other) . '/authors/' . $slug) . '"/>' . "\n"
                . '  </url>' . "\n";
        }

        return $entries;
    }

    private function entry(string $base, string $path, string $changefreq, string $priority): string
    {
        return '  <url>' . "\n"
            . '    <loc>' . FrontendLayout::e($base . $path) . '</loc>' . "\n"
            . '    <changefreq>' . $changefreq . '</changefreq>' . "\n"
            . '    <priority>' . $priority . '</priority>' . "\n"
            . '  </url>' . "\n";
    }

    /**
     * Article date as an ISO lastmod (YYYY-MM-DD) or '' when not usable.
     */
    private function lastmod(string $date): string
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }
}
