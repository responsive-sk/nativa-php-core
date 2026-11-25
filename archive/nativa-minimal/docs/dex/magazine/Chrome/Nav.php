<?php

declare(strict_types = 1);

namespace Themes\magazine\Chrome;

use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * Nav — magazine shell navigation furniture: desktop/mobile nav with
 * locale-driven labels, and the multi-column footer.
 *
 * Moved verbatim from the original Shell (theme-section-split).
 */
final class Nav
{
    /** @var list<array{key: string, default: string, href: string}> */
    private const array NAV = [
        ['key' => 'mag.nav_home', 'default' => 'Home', 'href' => '/'],
        ['key' => 'mag.nav_blog', 'default' => 'Blog', 'href' => '/blog'],
        ['key' => 'mag.nav_tags', 'default' => 'Tags', 'href' => '/blog/tags'],
        ['key' => 'mag.nav_categories', 'default' => 'Categories', 'href' => '/blog/categories'],
    ];

    /**
     * Desktop/mobile nav markup — active state is derived from the
     * (locale-stripped) current path.
     */
    public static function navHtml(string $currentPath, string $navClass, string $linkClass): string
    {
        $links = '';

        foreach (self::NAV as $item) {
            $active = self::navActive($currentPath, $item['href']);
            $links .= '<a class="' . $linkClass . ($active ? ' active' : '') . '" href="'
                . FrontendLayout::e($item['href']) . '">'
                . FrontendLayout::e(LocalesServices::t($item['key'], $item['default'])) . '</a>';
        }

        return '<nav class="' . $navClass . '" aria-label="Main">' . $links . '</nav>';
    }

    /**
     * Active state for a hardcoded magazine nav item — exact match or a
     * child path; the blog item stays clear of /blog/tags and
     * /blog/categories (they have their own nav entries).
     */
    private static function navActive(string $currentPath, string $href): bool
    {
        if ('/' === $href) {
            return '/' === $currentPath;
        }

        if ('/blog' === $href) {
            return '/blog' === $currentPath
                || (str_starts_with($currentPath, '/blog/')
                    && !str_starts_with($currentPath, '/blog/tags')
                    && !str_starts_with($currentPath, '/blog/categories'));
        }

        return $currentPath === $href || str_starts_with($currentPath, $href . '/');
    }

    /**
     * Multi-column footer — brand + description, quick links (nav) and an
     * RSS feed column.
     */
    public static function footerHtml(string $siteName, string $description, string $year): string
    {
        $quick = LocalesServices::t('mag.quick_links', 'Quick Links');
        $follow = LocalesServices::t('mag.follow', 'Follow');
        $atom = LocalesServices::t('mag.feed_atom', 'Atom');
        $rss = LocalesServices::t('mag.feed_rss', 'RSS');

        $links = '';
        foreach (self::NAV as $item) {
            $links .= '<a class="mag-footer__link" href="' . FrontendLayout::e($item['href']) . '">'
                . FrontendLayout::e(LocalesServices::t($item['key'], $item['default'])) . '</a>';
        }

        return '<footer class="mag-footer">'
            . '<div class="mag-container">'
            . '<div class="mag-footer__grid">'
            . '<div class="mag-footer__col"><h3 class="mag-footer__heading">' . FrontendLayout::e($siteName) . '</h3>'
            . '<p class="mag-footer__text">' . FrontendLayout::e($description) . '</p></div>'
            . '<div class="mag-footer__col"><h3 class="mag-footer__heading">' . FrontendLayout::e($quick) . '</h3>'
            . '<nav class="mag-footer__nav" aria-label="Footer">' . $links . '</nav></div>'
            . '<div class="mag-footer__col"><h3 class="mag-footer__heading">' . FrontendLayout::e($follow) . '</h3>'
            . '<nav class="mag-footer__nav" aria-label="Feeds">'
            . '<a class="mag-footer__link" href="/blog/feed.atom" type="application/atom+xml">' . FrontendLayout::e($atom) . '</a>'
            . '<a class="mag-footer__link" href="/blog/feed.xml" type="application/rss+xml">' . FrontendLayout::e($rss) . '</a>'
            . '</nav></div>'
            . '</div>'
            . '<div class="mag-footer__bottom"><p class="mag-footer__copyright">© ' . FrontendLayout::e($year) . ' '
            . FrontendLayout::e($siteName) . ' — PHP 8.5, zero dependencies</p></div>'
            . '</div>'
            . '</footer>';
    }
}
