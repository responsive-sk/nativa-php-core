<?php

declare(strict_types = 1);

namespace Themes\minimal\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * Widgets — sidebar widgets: popular posts (newest 4) + tags cloud.
 */
final class Widgets
{
    /**
     * Sidebar widgets — popular posts (newest 4) + tags cloud.
     *
     * @param list<Article>      $articles
     * @param list<string>       $allTags
     * @param array<string, int> $viewCounts slug → view count
     */
    public static function sidebar(array $articles, array $allTags, string $activeTag = '', array $viewCounts = []): string
    {
        $widgets = '';

        if ([] !== $articles) {
            // Sort by view count descending (most popular first)
            $sorted = $articles;
            if ([] !== $viewCounts) {
                usort($sorted, static fn (Article $a, Article $b): int => ($viewCounts[$b->slug] ?? 0) <=> ($viewCounts[$a->slug] ?? 0));
            }

            $popular = '';
            foreach (array_slice($sorted, 0, 4) as $article) {
                $views = $viewCounts[$article->slug] ?? 0;
                $viewsHtml = $views > 0 ? '<span class="minimal-sidebar-popular__views">' . FrontendLayout::e(number_format($views) . ' view' . (1 === $views ? '' : 's')) . '</span>' : '';
                $popular .= '<li class="minimal-sidebar-popular__item">'
                    . '<a class="minimal-sidebar-popular__link" href="' . FrontendLayout::e(Support::articleHref($article)) . '">'
                    . '<img class="minimal-sidebar-popular__image" src="' . FrontendLayout::e($article->image)
                    . '" alt="' . FrontendLayout::e($article->title) . '" width="60" height="60" loading="lazy" decoding="async">'
                    . '<span class="minimal-sidebar-popular__info">'
                    . '<span class="minimal-sidebar-popular__title">' . FrontendLayout::e($article->title) . '</span>'
                    . '<span class="minimal-sidebar-popular__date"><time datetime="' . FrontendLayout::e($article->date)
                    . '">' . FrontendLayout::e(Support::formatDate($article->date)) . '</time>' . $viewsHtml . '</span>'
                    . '</span>'
                    . '</a>'
                    . '</li>';
            }

            $widgets .= '<div class="minimal-sidebar-widget">'
                . '<h3 class="minimal-sidebar-widget__title">' . FrontendLayout::e(Support::t('min.popular_title', 'Popular Posts')) . '</h3>'
                . '<ul class="minimal-sidebar-popular">' . $popular . '</ul>'
                . '</div>';
        }

        if ([] !== $allTags) {
            $tags = '';
            foreach ($allTags as $tag) {
                $active = $tag === $activeTag ? ' minimal-sidebar-tag--active' : '';
                $tags .= '<a class="minimal-sidebar-tag' . $active . '" href="/blog/tags/'
                    . FrontendLayout::e(MarkdownRenderer::slugify($tag)) . '">'
                    . FrontendLayout::e($tag) . '</a>';
            }

            $widgets .= '<div class="minimal-sidebar-widget">'
                . '<h3 class="minimal-sidebar-widget__title">' . FrontendLayout::e(Support::t('min.tags_title', 'Tags')) . '</h3>'
                . '<div class="minimal-sidebar-tags">' . $tags . '</div>'
                . '</div>';
        }

        return '<aside class="minimal-sidebar" aria-label="Sidebar">' . $widgets . '</aside>';
    }
}
