<?php

declare(strict_types = 1);

namespace Themes\minimal\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * ArticleView — the minimal article page: breadcrumb, header (category/
 * title/excerpt/meta), hero image, rendered content, tag links and the
 * related-articles grid.
 */
final class ArticleView
{
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
        $back = Support::t('min.back_blog', 'Back to Blog');

        $breadcrumb = '<nav class="minimal-breadcrumb">'
            . '<a href="' . FrontendLayout::e($blogHref) . '" class="minimal-breadcrumb__link">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>'
            . '</svg>'
            . FrontendLayout::e($back)
            . '</a>'
            . '</nav>';

        $categoryHtml = '' !== $category
            ? '<span class="minimal-article__category">' . FrontendLayout::e($category) . '</span>'
            : '';
        $excerptHtml = '' !== $excerpt
            ? '<p class="minimal-article__excerpt">' . FrontendLayout::e($excerpt) . '</p>'
            : '';

        $metaBits = array_values(array_filter([
            '' !== $authorHtml ? $authorHtml : '',
            '' !== $date ? '<time datetime="' . FrontendLayout::e($date) . '">'
                . FrontendLayout::e(Support::formatDate($date)) . '</time>' : '',
            FrontendLayout::e($readingTime . ' min read'),
            $views > 0 ? FrontendLayout::e(number_format($views) . ' view' . (1 === $views ? '' : 's')) : '',
        ], static fn (string $bit): bool => '' !== $bit));

        $metaHtml = '<div class="minimal-article__meta">'
            . implode('<span class="minimal-article__dot">·</span>', $metaBits)
            . '</div>';

        $imageHtml = '' !== $coverUrl
            ? '<div class="minimal-article__image">'
                . '<img src="' . FrontendLayout::e($coverUrl) . '" alt="' . FrontendLayout::e($title) . '"'
                . ' width="1200" height="675" loading="eager" fetchpriority="high" decoding="async">'
                . '</div>'
            : '';

        $header = '<header class="minimal-article__header">'
            . $categoryHtml
            . '<h1 class="minimal-article__title">' . FrontendLayout::e($title) . '</h1>'
            . $excerptHtml
            . $metaHtml
            . '</header>';

        $article = '<article class="minimal-article">'
            . '<div class="minimal-container">'
            . $breadcrumb
            . $header
            . $imageHtml
            . '<div class="minimal-article__content">' . $bodyHtml . '</div>'
            . self::tagsHtml($tags)
            . '</div>'
            . '</article>';

        return $article . Cards::relatedGrid($related);
    }

    /**
     * @param list<string> $tags
     */
    private static function tagsHtml(array $tags): string
    {
        if ([] === $tags) {
            return '';
        }

        $label = Support::t('min.tags_label', 'Tags');
        $links = '';
        foreach ($tags as $tag) {
            $links .= '<a href="/blog/tags/' . FrontendLayout::e(MarkdownRenderer::slugify($tag)) . '" class="minimal-article__tag">#'
                . FrontendLayout::e($tag) . '</a>';
        }

        return '<div class="minimal-article__tags">'
            . '<span class="minimal-article__tags-label">' . FrontendLayout::e($label) . ':</span>'
            . $links
            . '</div>';
    }
}
