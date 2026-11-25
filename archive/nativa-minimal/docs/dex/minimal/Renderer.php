<?php

declare(strict_types = 1);

namespace Themes\minimal;

use Modules\Blog\Domain\Entities\Article;
use Themes\minimal\Sections\ArticleView;
use Themes\minimal\Sections\BlogIndex;
use Themes\minimal\Sections\Cards;
use Themes\minimal\Sections\Hero;
use Themes\minimal\Sections\Support;
use Themes\minimal\Sections\Widgets;

/**
 * Renderer — minimal theme page markup (BEM classes per the
 * theme-minimal contract, amber accent tokens).
 *
 * Facade over the Sections\* classes: every public static method keeps its
 * exact signature (the action classes call it with the same call shapes)
 * and delegates explicitly — no __call (C-pattern ban).
 *
 * The minimal theme reuses the starter BlogArticlesService card arrays
 * (slug/title/date/excerpt/category/tags/image/profile) and renders them as
 * min-* components: centered hero, 3-col feature/article grids, section
 * headers, the CTA strip, and the article page (breadcrumb, header with
 * category/excerpt/meta, hero image, content, tags, related 2-col grid).
 *
 * All UI copy goes through the translation system (min.* keys) with EN
 * fallback defaults (same t() pattern as MagazineRenderer).
 */
final class Renderer
{
    public static function card(Article $article, string $variant = 'medium'): string
    {
        return Cards::card($article, $variant);
    }

    /**
     * Article grid wrapper — count-aware columns: 1 article fills the full
     * container width (--1col), 2 articles split it in half (--2col), 3+
     * use the regular 3-col grid (--3col). Filtered result pages (tags,
     * categories, search, author) render few cards, so a lone result no
     * longer sits squeezed in a third of the container.
     *
     * @param list<Article> $articles
     */
    public static function grid(array $articles): string
    {
        return Cards::grid($articles);
    }

    /**
     * Related articles grid (2-col, per the source minimal article page).
     *
     * @param list<Article> $articles
     */
    public static function relatedGrid(array $articles): string
    {
        return Cards::relatedGrid($articles);
    }

    /**
     * Category sections on the blog index — categories with more than one
     * article, newest first, max 3, each a section header with a view-all
     * link + a grid of small cards.
     *
     * @param list<Article> $articles
     */
    public static function categorySections(array $articles): string
    {
        return BlogIndex::categorySections($articles);
    }

    /**
     * Sidebar widgets — popular posts (newest 4) + tags cloud.
     *
     * @param list<Article>      $articles
     * @param list<string>       $allTags
     * @param array<string, int> $viewCounts slug → view count
     */
    public static function sidebar(array $articles, array $allTags, string $activeTag = '', array $viewCounts = []): string
    {
        return Widgets::sidebar($articles, $allTags, $activeTag, $viewCounts);
    }

    /**
     * Unique tags across articles, sorted by name.
     *
     * @param list<Article> $articles
     *
     * @return list<string>
     */
    public static function allTags(array $articles): array
    {
        return Support::allTags($articles);
    }

    /**
     * Main + sidebar layout wrapper — the sidebar column collapses below
     * the article grid on narrow screens (minimal.css handles it).
     */
    public static function layoutWithSidebar(string $main, string $sidebar): string
    {
        return Support::layoutWithSidebar($main, $sidebar);
    }

    /**
     * Blog index — category sections + latest grid + sidebar.
     *
     * @param list<Article>      $articles
     * @param array<string, int> $viewCounts slug → view count
     */
    public static function blogIndex(array $articles, array $viewCounts = []): string
    {
        return BlogIndex::blogIndex($articles, $viewCounts);
    }

    /**
     * Section header — centered title + optional description.
     */
    public static function sectionHeader(string $title, string $desc = ''): string
    {
        return Support::sectionHeader($title, $desc);
    }

    /**
     * Full-width section wrapper (centered container).
     */
    public static function section(string $inner): string
    {
        return Support::section($inner);
    }

    /**
     * Centered hero — eyebrow, title, description, optional CTA actions.
     */
    public static function hero(string $title, string $desc, string $eyebrow = '', string $actions = ''): string
    {
        return Hero::hero($title, $desc, $eyebrow, $actions);
    }

    /**
     * Primary / outline CTA buttons (the minimal-btn contract).
     */
    public static function primaryButton(string $label, string $href): string
    {
        return Hero::primaryButton($label, $href);
    }

    public static function outlineButton(string $label, string $href): string
    {
        return Hero::outlineButton($label, $href);
    }

    /**
     * CTA strip — title, text, action buttons (per the source home page).
     */
    public static function cta(string $title, string $text, string $actions): string
    {
        return Hero::cta($title, $text, $actions);
    }

    /**
     * Article page — breadcrumb, header (category/title/excerpt/meta), hero
     * image, rendered content, tag links and a related-articles grid.
     *
     * @param list<string>  $tags
     * @param list<Article> $related
     */
    public static function articlePage(
        string $blogHref,
        string $title,
        string $category,
        string $excerpt,
        string $authorHtml,
        string $date,
        int $readingTime,
        string $coverUrl,
        string $bodyHtml,
        array $tags,
        array $related,
        int $views = 0,
    ): string {
        return ArticleView::articlePage(
            $blogHref,
            $title,
            $category,
            $excerpt,
            $authorHtml,
            $date,
            $readingTime,
            $coverUrl,
            $bodyHtml,
            $tags,
            $related,
            $views,
        );
    }

    /**
     * Locale-neutral date format (numeric — no English month names on
     * Slovak pages). Falls back to the raw value when unparseable.
     */
    public static function formatDate(string $date): string
    {
        return Support::formatDate($date);
    }

    /**
     * Minimal UI copy — translation system with an EN fallback default
     * (the seeded min.* keys live in locales.db; the default keeps pages
     * readable when a key is missing).
     */
    public static function t(string $key, string $default): string
    {
        return Support::t($key, $default);
    }

    /**
     * Full minimal article page — articlePage() wrapped with sidebar
     * (popular posts + tags cloud).
     *
     * @param list<string>  $tags
     * @param list<Article> $related
     * @param list<Article> $all     All articles for sidebar widgets
     */
    public static function fullArticlePage(
        string $blogHref,
        string $title,
        string $category,
        string $excerpt,
        string $authorHtml,
        string $date,
        int $readingTime,
        string $coverUrl,
        string $bodyHtml,
        array $tags,
        array $related,
        array $all,
        int $views = 0,
    ): string {
        return Support::layoutWithSidebar(
            ArticleView::articlePage(
                $blogHref,
                $title,
                $category,
                $excerpt,
                $authorHtml,
                $date,
                $readingTime,
                $coverUrl,
                $bodyHtml,
                $tags,
                $related,
                $views,
            ),
            Widgets::sidebar($all, Support::allTags($all)),
        );
    }
}
