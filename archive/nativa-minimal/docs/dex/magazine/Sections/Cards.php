<?php

declare(strict_types = 1);

namespace Themes\magazine\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * Cards — magazine card component, meta row, generic grid and the related
 * section (amber accent tokens, serif titles per the theme-magazine
 * contract).
 */
final class Cards
{
    public static function card(Article $article, string $variant = 'medium'): string
    {
        $href = Support::articleHref($article);
        $catHref = '/blog/categories/' . urlencode(strtolower($article->category));
        $badge = '' !== $article->category
            ? '<a class="mag-badge" href="' . FrontendLayout::e($catHref) . '">' . FrontendLayout::e($article->category) . '</a>'
            : '';

        $media = '<div class="mag-card__media">'
            . '<img class="mag-card__image" src="' . FrontendLayout::e($article->image)
            . '" alt="' . FrontendLayout::e($article->title) . '" width="720" height="360"'
            . ('hero' === $variant ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"')
            . ' decoding="async">'
            . ('hero' === $variant ? '<div class="mag-card__overlay"></div>' : '')
            . '</div>';

        $titleTag = 'hero' === $variant ? 'h2' : 'h3';
        $excerpt = 'small' === $variant
            ? ''
            : '<p class="mag-card__text">' . FrontendLayout::e($article->excerpt) . '</p>';

        return '<article class="mag-card mag-card--' . $variant . '">'
            . '<a class="mag-card__link" href="' . FrontendLayout::e($href) . '">'
            . $media
            . '<div class="mag-card__body">'
            . $badge
            . '<' . $titleTag . ' class="mag-card__title">' . FrontendLayout::e($article->title) . '</' . $titleTag . '>'
            . $excerpt
            . self::metaRow($article)
            . '</div>'
            . '</a>'
            . '</article>';
    }

    private static function metaRow(Article $article): string
    {
        $bits = [
            '' !== $article->date
                ? '<time datetime="' . FrontendLayout::e($article->date) . '">'
                    . FrontendLayout::e(Support::formatDate($article->date)) . '</time>'
                : '',
        ];

        if ([] !== $article->tags) {
            $tagLinks = [];
            foreach (array_slice($article->tags, 0, 2) as $tag) {
                $tagLinks[] = '<a href="/blog/tags/' . urlencode(strtolower($tag)) . '">' . FrontendLayout::e($tag) . '</a>';
            }
            $bits[] = '<span class="mag-card__meta-dot"></span>'
                . '<span>' . implode(', ', $tagLinks) . '</span>';
        }

        return '<div class="mag-card__meta">' . implode('', $bits) . '</div>';
    }

    /**
     * Article grid wrapper (no featured/category logic) — used by search
     * results, tags, categories and the author profile page.
     *
     * @param list<Article> $articles
     */
    public static function grid(array $articles): string
    {
        if ([] === $articles) {
            return '<div class="mag-placeholder"><p>' . FrontendLayout::e(Support::t('mag.no_articles', 'No articles published yet.')) . '</p></div>';
        }

        $cards = '';
        foreach ($articles as $article) {
            $cards .= self::card($article, 'medium');
        }

        return '<div class="mag-grid">' . $cards . '</div>';
    }

    /**
     * Related articles section (tag match) — small cards in a grid.
     *
     * @param list<Article> $articles
     */
    public static function relatedSection(array $articles): string
    {
        if ([] === $articles) {
            return '';
        }

        $cards = '';
        foreach ($articles as $article) {
            $cards .= self::card($article, 'small');
        }

        return '<section class="mag-related">'
            . '<div class="mag-section-header"><h2>' . FrontendLayout::e(Support::t('mag.related_title', 'Related Articles')) . '</h2></div>'
            . '<div class="mag-related-grid">' . $cards . '</div>'
            . '</section>';
    }
}
