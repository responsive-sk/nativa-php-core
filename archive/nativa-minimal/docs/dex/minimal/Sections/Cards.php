<?php

declare(strict_types = 1);

namespace Themes\minimal\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * Cards — minimal card component, meta row, count-aware grid and the
 * related grid (amber accent tokens per the theme-minimal contract).
 */
final class Cards
{
    public static function card(Article $article, string $variant = 'medium'): string
    {
        $href = Support::articleHref($article);
        $catHref = '/blog/categories/' . urlencode(strtolower($article->category));
        $badge = '' !== $article->category
            ? '<a class="minimal-card__badge" href="' . FrontendLayout::e($catHref) . '">' . FrontendLayout::e($article->category) . '</a>'
            : '';

        $image = '' !== $article->image
            ? '<img class="minimal-card__img" src="' . FrontendLayout::e($article->image)
                . '" alt="' . FrontendLayout::e($article->title) . '" width="400" height="225"'
                . ('hero' === $variant ? ' loading="eager" fetchpriority="high" decoding="sync"' : ' loading="lazy" decoding="async"')
                . '>'
            : '<div class="minimal-card__img minimal-card__img--placeholder" aria-hidden="true"></div>';

        $excerpt = 'small' === $variant
            ? ''
            : '<p class="minimal-card__text">' . FrontendLayout::e($article->excerpt) . '</p>';

        return '<article class="minimal-card minimal-card--interactive minimal-card--zoom minimal-card--animated">'
            . $image
            . $badge
            . '<h3 class="minimal-card__title"><a class="minimal-card__title-link" href="' . FrontendLayout::e($href) . '">'
            . FrontendLayout::e($article->title) . '</a></h3>'
            . $excerpt
            . self::metaRow($article)
            . '</article>';
    }

    private static function metaRow(Article $article): string
    {
        $meta = '<div class="minimal-card__meta">';

        if ('' !== $article->date) {
            $meta .= '<time datetime="' . FrontendLayout::e($article->date) . '">'
                . FrontendLayout::e(Support::formatDate($article->date)) . '</time>';
        }

        if ([] !== $article->tags) {
            $tagLinks = [];
            foreach (array_slice($article->tags, 0, 2) as $tag) {
                $tagLinks[] = '<a href="/blog/tags/' . urlencode(strtolower($tag)) . '">' . FrontendLayout::e($tag) . '</a>';
            }
            $meta .= '<span class="minimal-card__meta-sep">·</span>'
                . '<span>' . implode(', ', $tagLinks) . '</span>';
        }

        return $meta . '</div>';
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
        if ([] === $articles) {
            return '<div class="minimal-empty"><p>' . FrontendLayout::e(Support::t('min.no_articles', 'No articles published yet.')) . '</p></div>';
        }

        $cards = '';
        foreach ($articles as $i => $article) {
            $cards .= self::card($article, 0 === $i ? 'hero' : 'medium');
        }

        $columns = 1 === count($articles) ? '1col' : (2 === count($articles) ? '2col' : '3col');

        return '<div class="minimal-grid minimal-grid--' . $columns . '">' . $cards . '</div>';
    }

    /**
     * Related articles grid (2-col, per the source minimal article page).
     *
     * @param list<Article> $articles
     */
    public static function relatedGrid(array $articles): string
    {
        if ([] === $articles) {
            return '';
        }

        $cards = '';
        foreach ($articles as $article) {
            $cards .= self::card($article, 'small');
        }

        return '<section class="minimal-section">'
            . '<div class="minimal-container">'
            . '<div class="minimal-section-header">'
            . '<h2 class="minimal-section-header__title">' . FrontendLayout::e(Support::t('min.related_title', 'Related Articles')) . '</h2>'
            . '</div>'
            . '<div class="minimal-grid minimal-grid--2col">' . $cards . '</div>'
            . '</div>'
            . '</section>';
    }
}
