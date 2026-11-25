<?php

declare(strict_types = 1);

namespace Themes\magazine\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * Index — the blog index surfaces: featured hero grid (2fr + 1fr),
 * category sections (horizontal scroll) and the latest grid.
 */
final class Index
{
    /** First N articles become the featured hero grid (2fr + 1fr). */
    private const int FEATURED_COUNT = 3;

    /** Max category sections on the blog index. */
    private const int MAX_CATEGORY_SECTIONS = 3;

    /** Cards per category section (horizontal scroll). */
    private const int CARDS_PER_CATEGORY = 4;

    /**
     * Featured hero grid — spotlight card (2fr) + 2 supporting cards (1fr).
     *
     * @param list<Article> $articles
     */
    public static function heroGrid(array $articles): string
    {
        $featured = array_slice($articles, 0, self::FEATURED_COUNT);
        if ([] === $featured) {
            return '';
        }

        $main = array_shift($featured);
        $side = '';

        foreach ($featured as $article) {
            $side .= Cards::card($article, 'small');
        }

        return '<section class="mag-hero-section">'
            . '<div class="mag-hero-grid">'
            . Cards::card($main, 'hero')
            . '<div class="mag-hero-side">' . $side . '</div>'
            . '</div>'
            . '</section>';
    }

    /**
     * Category sections on the blog index — categories with more than one
     * article, newest first, max 3, horizontal scroll of 4 cards each.
     *
     * @param list<Article> $articles
     */
    public static function categorySections(array $articles): string
    {
        $byCategory = [];

        foreach ($articles as $article) {
            if ('' !== $article->category) {
                $byCategory[$article->category][] = $article;
            }
        }

        $sections = '';
        $shown = 0;

        foreach ($byCategory as $category => $catArticles) {
            if ($shown >= self::MAX_CATEGORY_SECTIONS) {
                break;
            }
            if (count($catArticles) < 2) {
                continue;
            }
            $shown++;

            $cards = '';
            foreach (array_slice($catArticles, 0, self::CARDS_PER_CATEGORY) as $article) {
                $cards .= Cards::card($article, 'small');
            }

            $viewAll = Support::t('mag.view_all', 'View all');
            $sections .= '<section class="mag-category-section">'
                . '<div class="mag-section-header">'
                . '<h2>' . FrontendLayout::e($category) . '</h2>'
                . '<a class="mag-section-link" href="/blog/categories/'
                . FrontendLayout::e(MarkdownRenderer::slugify($category)) . '">'
                . FrontendLayout::e($viewAll) . '</a>'
                . '</div>'
                . '<div class="mag-category-scroll">' . $cards . '</div>'
                . '</section>';
        }

        return $sections;
    }

    /**
     * Latest articles grid (everything after the featured row).
     *
     * @param list<Article> $articles
     */
    public static function latestSection(array $articles): string
    {
        $grid = [];

        foreach (array_slice($articles, self::FEATURED_COUNT) as $article) {
            $grid[] = Cards::card($article, 'medium');
        }

        $list = [] === $grid
            ? '<div class="mag-placeholder"><p>' . FrontendLayout::e(Support::t('mag.no_articles', 'No articles published yet.')) . '</p></div>'
            : '<div class="mag-grid">' . implode('', $grid) . '</div>';

        return '<section class="mag-latest">'
            . '<div class="mag-section-header"><h2>' . FrontendLayout::e(Support::t('mag.latest_title', 'Latest Articles')) . '</h2></div>'
            . $list
            . '</section>';
    }

    /**
     * Blog index page — hero grid + category sections + latest grid + sidebar.
     *
     * @param list<Article>      $articles
     * @param array<string, int> $viewCounts slug → view count
     */
    public static function blogIndex(array $articles, array $viewCounts = []): string
    {
        return self::heroGrid($articles)
            . '<div class="mag-layout-with-sidebar">'
            . '<div>'
            . self::categorySections($articles)
            . self::latestSection($articles)
            . '</div>'
            . Widgets::sidebar($articles, Support::allTags($articles), '', $viewCounts)
            . '</div>';
    }
}
