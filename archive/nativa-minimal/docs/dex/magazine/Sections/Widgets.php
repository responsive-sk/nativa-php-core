<?php

declare(strict_types = 1);

namespace Themes\magazine\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * Widgets — sidebar widgets: popular posts (newest 4 — the source template
 * slices the newest, there is no view tracking) + tags cloud.
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
                $viewsHtml = $views > 0 ? '<span class="mag-sidebar-popular__views">' . FrontendLayout::e(number_format($views) . ' view' . (1 === $views ? '' : 's')) . '</span>' : '';
                $popular .= '<li class="mag-sidebar-popular__item">'
                    . '<a href="' . FrontendLayout::e(Support::articleHref($article)) . '">'
                    . '<img class="mag-sidebar-popular__image" src="' . FrontendLayout::e($article->image)
                    . '" alt="' . FrontendLayout::e($article->title) . '" width="60" height="60" loading="lazy" decoding="async">'
                    . '</a>'
                    . '<div class="mag-sidebar-popular__info">'
                    . '<a class="mag-sidebar-popular__title" href="' . FrontendLayout::e(Support::articleHref($article)) . '">'
                    . FrontendLayout::e($article->title) . '</a>'
                    . '<div class="mag-sidebar-popular__date"><time datetime="' . FrontendLayout::e($article->date)
                    . '">' . FrontendLayout::e(Support::formatDate($article->date)) . '</time>' . $viewsHtml . '</div>'
                    . '</div>'
                    . '</li>';
            }

            $widgets .= '<div class="mag-sidebar-widget">'
                . '<h3 class="mag-sidebar-widget__title">' . FrontendLayout::e(Support::t('mag.popular_title', 'Popular Posts')) . '</h3>'
                . '<ul class="mag-sidebar-popular">' . $popular . '</ul>'
                . '</div>';
        }

        if ([] !== $allTags) {
            $tags = '';
            foreach ($allTags as $tag) {
                $active = $tag === $activeTag ? ' mag-sidebar-tag--active' : '';
                $tags .= '<a class="mag-sidebar-tag' . $active . '" href="/blog/tags/'
                    . FrontendLayout::e(MarkdownRenderer::slugify($tag)) . '">'
                    . FrontendLayout::e($tag) . '</a>';
            }

            $widgets .= '<div class="mag-sidebar-widget">'
                . '<h3 class="mag-sidebar-widget__title">' . FrontendLayout::e(Support::t('mag.tags_title', 'Tags')) . '</h3>'
                . '<div class="mag-sidebar-tags">' . $tags . '</div>'
                . '</div>';
        }

        return '<aside class="mag-sidebar" aria-label="Sidebar">' . $widgets . '</aside>';
    }
}
