<?php

declare(strict_types = 1);

namespace Themes\magazine\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Blog\Domain\Entities\ArticleAuthor;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * ArticleView — the magazine article page: hero image, header (badge +
 * title + meta), rendered content, tag chips, author bio, prev/next,
 * related articles, TOC (desktop sidebar + mobile FAB) and the full-page
 * assembly with sidebar.
 */
final class ArticleView
{
    /**
     * Desktop "On this page" TOC sidebar — magazine styling, reusing the
     * headings extracted from the rendered article body.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    public static function tocSidebar(array $headings): string
    {
        return '<nav class="mag-toc" aria-label="On this page">'
            . '<p class="mag-toc__title">' . FrontendLayout::e(Support::t('mag.on_this_page', 'On this page')) . '</p>'
            . '<ul class="mag-toc__list">' . self::tocItems($headings) . '</ul>'
            . '</nav>';
    }

    /**
     * Mobile "On this page" floating toggle + panel (CSP-safe inline toggle,
     * same pattern as the starter st-toc-fab).
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    public static function tocMobile(array $headings): string
    {
        return '<div class="mag-toc-mobile" id="mag-toc-mobile">'
            . '<button type="button" class="mag-toc-toggle" id="mag-toc-toggle" aria-expanded="false"'
            . ' aria-controls="mag-toc-panel" aria-label="' . FrontendLayout::e(Support::t('mag.on_this_page', 'On this page')) . '">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/>'
            . '<line x1="3" y1="18" x2="9" y2="18"/>'
            . '</svg>'
            . '<span>' . FrontendLayout::e(Support::t('mag.on_this_page', 'On this page')) . '</span>'
            . '</button>'
            . '<div class="mag-toc-panel" id="mag-toc-panel">'
            . '<ul class="mag-toc__list">' . self::tocItems($headings) . '</ul>'
            . '</div>'
            . '</div>'
            . '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
            . '(function(){var fab=document.getElementById("mag-toc-mobile"),t=document.getElementById("mag-toc-toggle"),p=document.getElementById("mag-toc-panel");if(!fab||!t||!p)return;function open(){t.classList.add("open");p.classList.add("open");t.setAttribute("aria-expanded","true");}function close(){t.classList.remove("open");p.classList.remove("open");t.setAttribute("aria-expanded","false");}t.addEventListener("click",function(){t.classList.contains("open")?close():open();});p.addEventListener("click",function(e){if(e.target&&e.target.tagName==="A")close();});document.addEventListener("click",function(e){if(!fab.contains(e.target))close();});document.addEventListener("keydown",function(e){if(e.key==="Escape"){close();t.focus();}});})();'
            . '</script>';
    }

    /**
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function tocItems(array $headings): string
    {
        $items = '';

        foreach ($headings as $heading) {
            $sub = 3 === $heading['level'] ? ' mag-toc__item--sub' : '';
            $items .= '<li class="mag-toc__item' . $sub . '">'
                . '<a class="mag-toc__link" href="#' . FrontendLayout::e($heading['anchor'])
                . '" data-heading="' . FrontendLayout::e($heading['anchor']) . '">'
                . FrontendLayout::e($heading['text'])
                . '</a>'
                . '</li>';
        }

        return $items;
    }

    /**
     * Author bio box — real profile (avatar, name, link to /authors/{slug})
     * when the article resolves one, else the plain author string.
     */
    public static function authorBio(?ArticleAuthor $profile, string $authorName): string
    {
        $avatar = null !== $profile && is_string($profile->avatar) && '' !== $profile->avatar
            ? $profile->avatar
            : '';
        $name = FrontendLayout::e($authorName);

        if (null !== $profile) {
            $name = '<a href="/authors/' . FrontendLayout::e($profile->slug) . '">' . FrontendLayout::e($authorName) . '</a>';
        }

        $img = '' !== $avatar
            ? '<img class="mag-author-bio__avatar" src="' . FrontendLayout::e($avatar)
                . '" alt="' . FrontendLayout::e($authorName) . '" width="64" height="64" loading="lazy" decoding="async">'
            : '<div class="mag-author-bio__avatar" aria-hidden="true"></div>';

        $text = Support::t('mag.author_bio', 'Sharing insights on technology, development, and the modern web.');

        return '<div class="mag-author-bio">'
            . '<div class="mag-author-bio__avatar-wrapper">' . $img . '</div>'
            . '<div class="mag-author-bio__info">'
            . '<p class="mag-author-bio__name">' . $name . '</p>'
            . '<p class="mag-author-bio__text">' . FrontendLayout::e($text) . '</p>'
            . '</div>'
            . '</div>';
    }

    /**
     * Prev / next article navigation.
     */
    public static function prevNext(?Article $prev, ?Article $next): string
    {
        $prevLabel = Support::t('mag.prev', 'Previous');
        $nextLabel = Support::t('mag.next', 'Next');

        $prevHtml = null !== $prev
            ? '<a class="mag-article-nav__link" href="/blog/' . FrontendLayout::e($prev->slug) . '">'
                . '<span class="mag-article-nav__label">← ' . FrontendLayout::e($prevLabel) . '</span>'
                . '<span class="mag-article-nav__title">' . FrontendLayout::e($prev->title) . '</span></a>'
            : '<div></div>';

        $nextHtml = null !== $next
            ? '<a class="mag-article-nav__link mag-article-nav__link--next" href="/blog/' . FrontendLayout::e($next->slug) . '">'
                . '<span class="mag-article-nav__label">' . FrontendLayout::e($nextLabel) . ' →</span>'
                . '<span class="mag-article-nav__title">' . FrontendLayout::e($next->title) . '</span></a>'
            : '<div></div>';

        return '<nav class="mag-article-nav" aria-label="Post navigation">' . $prevHtml . $nextHtml . '</nav>';
    }

    /**
     * Magazine article hero image (the LCP candidate — eager + high).
     */
    public static function hero(string $coverUrl, string $title): string
    {
        return '' !== $coverUrl
            ? '<img class="mag-article-hero" src="' . FrontendLayout::e($coverUrl) . '" alt="'
                . FrontendLayout::e($title) . '" width="1200" height="500" loading="eager" fetchpriority="high" decoding="async">'
            : '';
    }

    /**
     * Magazine article header — category badge, title, meta (date · reading
     * time) and tag links.
     *
     * @param list<string> $tags
     */
    public static function articleHeader(string $title, array $tags, int $readingTime, int $views = 0): string
    {
        $metaBits = [];

        if ($views > 0) {
            $metaBits[] = FrontendLayout::e(number_format($views) . ' view' . (1 === $views ? '' : 's'));
        }

        $metaBits[] = FrontendLayout::e($readingTime . ' min read');

        $tagLinks = '';
        foreach ($tags as $tag) {
            $tagLinks .= '<a class="mag-card__tag" href="/blog/tags/'
                . FrontendLayout::e(MarkdownRenderer::slugify($tag)) . '">#'
                . FrontendLayout::e($tag) . '</a>';
        }

        return '<header class="mag-article-header">'
            . '<h1>' . FrontendLayout::e($title) . '</h1>'
            . '<div class="mag-article-meta">'
            . implode('<span class="mag-card__meta-dot"></span>', $metaBits)
            . $tagLinks
            . '</div>'
            . '</header>';
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
        $article = '<article class="mag-article">'
            . self::articleHeader($title, $tags, $readingTime, $views)
            . '<div class="mag-article-content">' . $bodyHtml . '</div>'
            . $tagChips
            . self::authorBio($profile, $authorName)
            . self::prevNext($prev, $next)
            . Cards::relatedSection($related)
            . '</article>';

        $sidebar = '<aside class="mag-sidebar" aria-label="Sidebar">'
            . ([] === $headings ? '' : self::tocSidebar($headings))
            . Widgets::sidebar($all, Support::allTags($all))
            . '</aside>';

        return '<div class="mag-article-page">'
            . '<div class="mag-container">'
            . self::hero($coverUrl, $title)
            . '<div class="mag-layout-with-sidebar">'
            . $article
            . $sidebar
            . '</div>'
            . '</div>'
            . '</div>'
            . ([] === $headings ? '' : self::tocMobile($headings));
    }
}
