<?php

declare(strict_types = 1);

namespace Themes\magazine;

use Modules\Blog\Domain\Entities\Article;
use Modules\Blog\Domain\Entities\ArticleAuthor;
use Themes\magazine\Sections\ArticleView;
use Themes\magazine\Sections\Cards;
use Themes\magazine\Sections\Index;
use Themes\magazine\Sections\Support;
use Themes\magazine\Sections\Widgets;

/**
 * Renderer — magazine theme page markup (BEM classes per the
 * theme-magazine contract, amber accent tokens).
 *
 * Facade over the Sections\* classes: every public static method keeps its
 * exact signature (the action classes call it with the same call shapes)
 * and delegates explicitly — no __call (C-pattern ban).
 *
 * The magazine theme reuses the starter BlogArticlesService card arrays
 * (slug/title/date/excerpt/category/tags/image/profile) and renders them as
 * mag-* components: featured hero grid (2fr + 1fr), category sections,
 * latest grid, sidebar widgets (popular = newest 4 — the source template
 * slices the newest, there is no view tracking — + tags cloud), article
 * TOC/author-bio/prev-next/related, and the tags/categories pages.
 *
 * All UI copy goes through the translation system (mag.* keys) with EN
 * fallback defaults (same t() pattern as HomepageAction).
 */
final class Renderer
{
    public static function card(Article $article, string $variant = 'medium'): string
    {
        return Cards::card($article, $variant);
    }

    /**
     * Featured hero grid — spotlight card (2fr) + 2 supporting cards (1fr).
     *
     * @param list<Article> $articles
     */
    public static function heroGrid(array $articles): string
    {
        return Index::heroGrid($articles);
    }

    /**
     * Category sections on the blog index — categories with more than one
     * article, newest first, max 3, horizontal scroll of 4 cards each.
     *
     * @param list<Article> $articles
     */
    public static function categorySections(array $articles): string
    {
        return Index::categorySections($articles);
    }

    /**
     * Latest articles grid (everything after the featured row).
     *
     * @param list<Article> $articles
     */
    public static function latestSection(array $articles): string
    {
        return Index::latestSection($articles);
    }

    /**
     * Article grid wrapper (no featured/category logic) — used by search
     * results, tags, categories and the author profile page.
     *
     * @param list<Article> $articles
     */
    public static function grid(array $articles): string
    {
        return Cards::grid($articles);
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
     * Blog index page — hero grid + category sections + latest grid + sidebar.
     *
     * @param list<Article>      $articles
     * @param array<string, int> $viewCounts slug → view count
     */
    public static function blogIndex(array $articles, array $viewCounts = []): string
    {
        return Index::blogIndex($articles, $viewCounts);
    }

    /**
     * Desktop "On this page" TOC sidebar — magazine styling, reusing the
     * headings extracted from the rendered article body.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    public static function tocSidebar(array $headings): string
    {
        return ArticleView::tocSidebar($headings);
    }

    /**
     * Mobile "On this page" floating toggle + panel (CSP-safe inline toggle,
     * same pattern as the starter st-toc-fab).
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    public static function tocMobile(array $headings): string
    {
        return ArticleView::tocMobile($headings);
    }

    /**
     * Author bio box — real profile (avatar, name, link to /authors/{slug})
     * when the article resolves one, else the plain author string.
     */
    public static function authorBio(?ArticleAuthor $profile, string $authorName): string
    {
        return ArticleView::authorBio($profile, $authorName);
    }

    /**
     * Prev / next article navigation.
     */
    public static function prevNext(?Article $prev, ?Article $next): string
    {
        return ArticleView::prevNext($prev, $next);
    }

    /**
     * Related articles section (tag match) — small cards in a grid.
     *
     * @param list<Article> $articles
     */
    public static function relatedSection(array $articles): string
    {
        return Cards::relatedSection($articles);
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
     * Magazine UI copy — translation system with an EN fallback default
     * (the seeded mag.* keys live in locales.db; the default keeps pages
     * readable when a key is missing).
     */
    public static function t(string $key, string $default): string
    {
        return Support::t($key, $default);
    }

    /**
     * Full magazine article page — hero image, header (badge + title + meta),
     * the rendered Markdown, tag chips, author bio, prev/next, related articles,
     * a sidebar with TOC + popular + tags and the mobile TOC toggle.
     *
     * @param list<string>                                          $tags
     * @param list<array{level: int, text: string, anchor: string}> $headings
     * @param list<Article>                                         $all
     * @param list<Article>                                         $related
     */
    public static function articlePage(
        string $slug,
        string $title,
        string $tagChips,
        string $bodyHtml,
        array $headings,
        string $coverUrl,
        ?ArticleAuthor $profile,
        string $authorName,
        array $all,
        array $tags,
        int $readingTime,
        ?Article $prev,
        ?Article $next,
        array $related,
        int $views = 0,
    ): string {
        return ArticleView::articlePage(
            $slug,
            $title,
            $tagChips,
            $bodyHtml,
            $headings,
            $coverUrl,
            $profile,
            $authorName,
            $all,
            $tags,
            $readingTime,
            $prev,
            $next,
            $related,
            $views,
        );
    }

    /**
     * Magazine article hero image (the LCP candidate — eager + high).
     */
    public static function hero(string $coverUrl, string $title): string
    {
        return ArticleView::hero($coverUrl, $title);
    }

    /**
     * Magazine article header — category badge, title, meta (date · reading
     * time) and tag links.
     *
     * @param list<string> $tags
     */
    public static function articleHeader(string $title, array $tags, int $readingTime, int $views = 0): string
    {
        return ArticleView::articleHeader($title, $tags, $readingTime, $views);
    }
}
