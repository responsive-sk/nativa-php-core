<?php

declare(strict_types = 1);

namespace Themes\minimal\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * BlogIndex — category sections on the blog index (max 3, min 2 articles
 * each) and the index-page assembly (sections + latest grid + sidebar).
 */
final class BlogIndex
{
    /** Max category sections on the blog index. */
    private const int MAX_CATEGORY_SECTIONS = 3;

    /** Cards per category section. */
    private const int CARDS_PER_CATEGORY = 4;

    /**
     * Category sections on the blog index — categories with more than one
     * article, newest first, max 3, each a section header with a view-all
     * link + a grid of small cards.
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

            $viewAll = Support::t('min.view_all', 'View all');
            $sections .= Support::section(
                '<div class="minimal-section-header minimal-section-header--split">'
                . '<h2 class="minimal-section-header__title">' . FrontendLayout::e($category) . '</h2>'
                . '<a class="minimal-section-link" href="/blog/categories/'
                . FrontendLayout::e(MarkdownRenderer::slugify($category)) . '">'
                . FrontendLayout::e($viewAll) . '</a>'
                . '</div>'
                . '<div class="minimal-grid minimal-grid--3col">' . $cards . '</div>',
            );
        }

        return $sections;
    }

    /**
     * Blog index — category sections + latest grid + sidebar.
     *
     * @param list<Article>      $articles
     * @param array<string, int> $viewCounts slug → view count
     */
    public static function blogIndex(array $articles, array $viewCounts = []): string
    {
        return Support::layoutWithSidebar(
            self::categorySections($articles)
            . Support::section(
                Support::sectionHeader(Support::t('min.latest_title', 'Latest Articles'))
                . Cards::grid($articles),
            ),
            Widgets::sidebar($articles, Support::allTags($articles), '', $viewCounts),
        );
    }
}
