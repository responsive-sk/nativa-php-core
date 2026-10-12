<?php

declare(strict_types = 1);

namespace Modules\Griffe\Theme;

use Modules\Core\Application\Services\View\InlineJs;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\ThemeModule;

/**
 * GriffeTheme — the webgriffe.com agency idiom as a kernel theme module.
 *
 * Sticky text-nav header, fullscreen home slider (hero slide + case slides),
 * line-mask scroll reveals, case-study grid, minimal legal footer. The CSS is
 * hand-written, framework-free (see assets/critical/griffe.css): the reference
 * runs on Xtend UI, but this port re-expresses only the used behaviours, so
 * Tailwind/JS-framework vending is deliberately absent. Colours are neutral
 * placeholders by decision — layout and motion first.
 *
 * It imports nothing from a feature module. Article shapes are read through
 * `get_object_vars` rather than `use`ing the entity, the established kernel
 * pattern for a theme that must not name the module owning the article.
 */
final class GriffeTheme implements ThemeModule
{
    public function name(): string
    {
        return 'griffe';
    }

    public function bundle(): string
    {
        return 'griffe';
    }

    public function jsKey(): string
    {
        return 'griffe';
    }

    /**
     * @return list<string> src/-relative, resolved by InlineCss
     */
    public function criticalCss(): array
    {
        return ['Modules/Griffe/assets/critical/griffe.css'];
    }

    /**
     * No override state to restore: the shared dark/light pre-paint bytes
     * FrontendLayout inlines for `''` are exactly right, and returning ''
     * keeps every other theme's golden master byte-identical.
     */
    public function themeInitScript(): string
    {
        return '';
    }

    public function prefix(): string
    {
        return 'gr';
    }

    /**
     * Sticky text-nav header in the reference's shape: brand left, text links
     * (Work, Services, Blog, About, Contact come from the menu), theme toggle
     * + locale + hamburger right. The hide-on-scroll half is CSS + gr-header.js
     * (pageWrap, ungated — the header always renders); the mobile menu is a
     * checkbox like Mono's meatball, so it opens with no JS at all.
     *
     * DOM order is the `~` contract: the input precedes `.gr-header__inner`,
     * which holds both the menu and the label that drives it.
     */
    public function header(ShellContext $context): string
    {
        $hasMenu = [] !== $context->headerNav;

        return '<header class="gr-header">' . "\n"
            . self::menuToggleInput($hasMenu)
            . '<div class="gr-container gr-header__inner">' . "\n"
            . '<a class="gr-header__brand" href="/">' . self::e($context->siteName) . '</a>' . "\n"
            . $this->menu($context->headerNav)
            . '<div class="gr-header__actions">' . "\n"
            . self::themeToggle()
            . $this->localeLinks($context)
            . self::menuToggleLabel($hasMenu)
            . '</div>' . "\n"
            . '</div>' . "\n"
            . '</header>' . "\n";
    }

    /**
     * Minimal legal footer: © line, footer nav (Privacy/Cookie/Legal come
     * from the menu), locale pair — the reference's `it`/`en` switcher shape.
     */
    public function footer(ShellContext $context): string
    {
        return '<footer class="gr-footer"><div class="gr-container gr-footer__inner">'
            . '<span>© ' . self::e($context->year) . ' ' . self::e($context->siteName) . '</span>'
            . $this->footerLinks($context->footerNav)
            . $this->localeLinks($context)
            . '</div></footer>' . "\n";
    }

    public function pageWrap(string $content): string
    {
        // Each script is gated on the markup it drives (Mono idiom): pages
        // without a slider ship no slider JS, pages without masked lines ship
        // no reveal JS, pages without fences ship no highlighter.
        $scripts = self::inlineScript('Modules/Griffe/assets/scripts/gr-header.js');

        if (str_contains($content, 'gr-hero-slider')) {
            $scripts .= self::inlineScript('Modules/Griffe/assets/scripts/gr-slider.js');
        }

        if (str_contains($content, 'gr-anim')) {
            $scripts .= self::inlineScript('Modules/Griffe/assets/scripts/gr-reveal.js');
        }

        if (str_contains($content, 'language-')) {
            $scripts .= self::inlineScript('Modules/Griffe/assets/scripts/gr-code-highlight.js');
        }

        return '<main class="gr-main">' . "\n"
            . '    ' . $content . "\n"
            . '</main>' . "\n"
            . $scripts;
    }

    /**
     * Fullscreen home slider: slide 1 is the editorial hero (H1 + lede + CTA
     * from `$copy`), slides 2+ are case slides from `$latestArticles` (falling
     * back to `$articles`). The article shape carries slug + title only, so the
     * title headlines the case and the service line falls back to copy — the
     * same mapping the grid below reuses. Slide 1 is active server-side, so a
     * static render (no JS, reduced motion) still opens on the hero.
     */
    public function homepage(PageContext $context): string
    {
        $articles = [] !== $context->latestArticles ? $context->latestArticles : $context->articles;
        $slides = $this->heroSlide($context) . $this->caseSlides($context, $articles);
        $count = substr_count($slides, '<li class="gr-slide');

        $slider = '<section class="gr-hero-slider" data-gr-autoplay="6000" aria-roledescription="carousel">'
            . '<ul class="gr-slides">' . $slides . '</ul>'
            . $this->sliderPag($count)
            . $this->sliderArrows($count)
            . '</section>';

        return $this->pageWrap($slider . $this->caseGrid($context, $articles));
    }

    /**
     * @param array<array-key, mixed> $articles
     */
    private function caseSlides(PageContext $context, array $articles): string
    {
        $service = $context->copy['case_service'] ?? 'Case study';
        $html = '';

        foreach (self::pairs($articles) as $pair) {
            $html .= '<li class="gr-slide">'
                . '<div class="gr-container">'
                . '<p class="gr-slide__kicker">' . self::e($service) . '</p>'
                . '<h2 class="gr-slide__title">' . self::revealLines($pair['title']) . '</h2>'
                . '<a class="gr-btn gr-btn--link" href="/blog/' . self::e($pair['slug']) . '">Read More →</a>'
                . '</div>'
                . '</li>';
        }

        return $html;
    }

    private function heroSlide(PageContext $context): string
    {
        $title = $context->copy['title'] ?? 'Home';
        $lede = $context->copy['lede'] ?? '';
        $cta = $context->copy['cta'] ?? 'Discover more';
        $href = $context->blogHref ?? '/blog';

        $html = '<li class="gr-slide gr-slide--active">'
            . '<div class="gr-container">'
            . '<h1 class="gr-slide__title">' . self::revealLines($title) . '</h1>';

        if ('' !== $lede) {
            $html .= '<p class="gr-slide__lede">' . self::e($lede) . '</p>';
        }

        return $html
            . '<a class="gr-btn" href="' . self::e($href) . '">' . self::e($cta) . '</a>'
            . '</div>'
            . '</li>';
    }

    private function sliderPag(int $count): string
    {
        if ($count < 2) {
            return '';
        }

        $buttons = '';

        for ($i = 0; $i < $count; $i++) {
            $active = 0 === $i ? ' gr-pag--active' : '';
            $current = 0 === $i ? ' aria-current="true"' : '';
            $buttons .= '<button type="button" class="gr-pag' . $active . '"'
                . ' aria-label="Go to slide ' . ($i + 1) . '"' . $current . '>'
                . '<span class="gr-pag__fill"></span></button>';
        }

        return '<div class="gr-slider__pag">' . $buttons . '</div>';
    }

    private function sliderArrows(int $count): string
    {
        if ($count < 2) {
            return '';
        }

        return '<button type="button" class="gr-icon-btn gr-slider__arrow gr-slider__arrow--prev"'
            . ' aria-label="Previous slide">←</button>'
            . '<button type="button" class="gr-icon-btn gr-slider__arrow gr-slider__arrow--next"'
            . ' aria-label="Next slide">→</button>';
    }

    /**
     * Line-mask reveal spans: server-side cannot know the rendered line
     * breaks, so copy is chunked into short masked units that reveal one
     * after another — the reference's line-by-line shape without measuring
     * text. Five words per unit keeps display headings near two units.
     */
    private static function revealLines(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text));

        if (false === $words || [] === $words || '' === $words[0]) {
            return '';
        }

        $units = array_chunk($words, 5);
        $html = '';

        foreach ($units as $unit) {
            $html .= '<span class="gr-anim"><span class="gr-anim__line">'
                . self::e(implode(' ', $unit))
                . '</span></span>';
        }

        return $html;
    }

    /**
     * @param array<array-key, mixed> $articles
     * @return list<array{slug: string, title: string}>
     */
    private static function pairs(array $articles): array
    {
        $pairs = [];

        foreach ($articles as $article) {
            if (!is_object($article)) {
                continue;
            }

            $fields = get_object_vars($article);
            $slug = $fields['slug'] ?? null;
            $title = $fields['title'] ?? null;

            if (is_string($slug) && is_string($title)) {
                $pairs[] = ['slug' => $slug, 'title' => $title];
            }
        }

        return $pairs;
    }

    /**
     * The case-study grid below the slider, reusing the slide mapping.
     *
     * @param array<array-key, mixed> $articles
     */
    private function caseGrid(PageContext $context, array $articles): string
    {
        $pairs = self::pairs($articles);
        $service = $context->copy['case_service'] ?? 'Case study';

        $html = '<section class="gr-cases"><div class="gr-container">'
            . '<h2 class="gr-section__title">' . self::e($context->copy['cases_title'] ?? 'Selected work') . '</h2>'
            . '<p class="gr-section__sub">' . self::e($context->copy['cases_sub'] ?? '') . '</p>';

        if ([] === $pairs) {
            return $html . '<p class="gr-empty">Nothing published yet.</p></div></section>';
        }

        $cards = '';

        foreach ($pairs as $pair) {
            $cards .= '<article class="gr-case">'
                . '<h3 class="gr-case__client">' . self::e($pair['title']) . '</h3>'
                . '<p class="gr-case__service">' . self::e($service) . '</p>'
                . '<a class="gr-case__more" href="/blog/' . self::e($pair['slug']) . '">Read More →</a>'
                . '</article>';
        }

        return $html . '<div class="gr-case-grid">' . $cards . '</div></div></section>';
    }

    public function blogIndex(PageContext $context): string
    {
        return $this->pageWrap(
            self::section('Blog', $this->caseCards($context->articles) . self::pager($context)),
        );
    }

    public function blogSearch(PageContext $context): string
    {
        // Task 4 owns the blog family.
        return $this->pageWrap(
            self::section('Search: ' . $context->query, self::links($context->results)),
        );
    }

    /**
     * Article anatomy (Mono's shape, renamed): H1 + meta line + cover +
     * body + tag chips + TOC aside + views + comments. Raw HTML fields
     * (`bodyHtml`, `tagChips`, `commentsHtml`) pass through by contract;
     * everything else escapes through `e()`.
     */
    public function blogArticle(PageContext $context): string
    {
        $article = '<article class="gr-article">'
            . '<p class="gr-slide__kicker">Article</p>'
            . '<h1 class="gr-section__title">' . self::revealLines($context->title) . '</h1>';

        if ('' !== $context->metaLine) {
            $article .= '<p class="gr-article__meta">' . self::e($context->metaLine) . '</p>';
        }

        $cover = '' !== $context->coverUrl ? $context->coverUrl : $context->coverImage;

        if ('' !== $cover) {
            $article .= '<p><img src="' . self::e($cover) . '" alt=""></p>';
        }

        $article .= '<div class="gr-article__body">' . $context->bodyHtml . '</div>';

        if ('' !== $context->tagChips) {
            $article .= '<div class="gr-cloud">' . $context->tagChips . '</div>';
        }

        if (0 < $context->views) {
            $article .= '<p class="gr-article__meta">' . $context->views . ' views</p>';
        }

        $article .= $context->commentsHtml . '</article>';
        $toc = self::articleToc($context->headings);

        if ('' !== $toc) {
            $article .= $toc;
        }

        return $this->pageWrap(
            '<section class="gr-cases"><div class="gr-container">' . $article . '</div></section>',
        );
    }

    /**
     * The "On this page" aside from `MarkdownRenderer::extractHeadings()` —
     * server-side anchors only, '' when there is nothing to navigate. The
     * `data-toc-link` / `data-heading` opt-ins mirror the shared contract.
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function articleToc(array $headings): string
    {
        $items = '';

        foreach ($headings as $heading) {
            if (!is_array($heading)) {
                continue;
            }

            $anchor = $heading['anchor'] ?? '';
            $text = $heading['text'] ?? '';

            if (!is_string($anchor) || '' === $anchor || !is_string($text) || '' === trim($text)) {
                continue;
            }

            $itemClass = (3 === ($heading['level'] ?? 0)) ? ' gr-toc__item--sub' : '';
            $items .= '<li class="gr-toc__item' . $itemClass . '">'
                . '<a class="gr-toc__link" data-toc-link data-heading="' . self::e($anchor) . '"'
                . ' href="#' . self::e($anchor) . '">' . self::e($text) . '</a></li>';
        }

        if ('' === $items) {
            return '';
        }

        return '<aside class="gr-toc"><nav aria-label="On this page">'
            . '<p class="gr-toc__title">On this page</p>'
            . '<ul class="gr-toc__list">' . $items . '</ul>'
            . '</nav></aside>';
    }

    public function blogTags(PageContext $context): string
    {
        $title = null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags';

        return $this->pageWrap(
            self::section($title, self::cloud($context->tags, $context->counts, 'tag', $context->tag)
                . self::links($context->articles)),
        );
    }

    public function blogCategories(PageContext $context): string
    {
        $title = null !== $context->category ? $context->category : 'Categories';

        return $this->pageWrap(
            self::section($title, self::cloud($context->categories, $context->counts, 'category', $context->category)
                . self::links($context->articles)),
        );
    }

    /**
     * @param list<string>       $terms
     * @param array<string, int> $counts
     */
    private static function cloud(array $terms, array $counts, string $kind, ?string $active): string
    {
        if ([] === $terms) {
            return '';
        }

        $chips = '';

        foreach ($terms as $term) {
            $label = $term;
            $count = $counts[$term] ?? null;

            if (null !== $count) {
                $label .= ' (' . $count . ')';
            }

            $chips .= '<a class="gr-chip' . ($term === $active ? ' gr-chip--active' : '') . '"'
                . ' href="/blog/' . $kind . '/' . self::e($term) . '">'
                . self::e($label) . '</a>';
        }

        return '<div class="gr-cloud">' . $chips . '</div>';
    }

    public function authorProfile(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;
        $name = is_string($name) && '' !== $name ? $name : 'Author';
        $avatar = $author['avatar'] ?? null;

        $head = '<h1 class="gr-section__title">' . self::e($name) . '</h1>';

        if (is_string($avatar) && '' !== $avatar) {
            $head .= '<p><img src="' . self::e($avatar) . '" alt=""></p>';
        }

        if ('' !== $context->memberSince) {
            $head .= '<p class="gr-article__meta">Member since ' . self::e($context->memberSince) . '</p>';
        }

        return $this->pageWrap(
            '<section class="gr-cases"><div class="gr-container">' . $head
            . $this->caseCards($context->articles) . '</div></section>',
        );
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="gr-cases"><div class="gr-container">'
            . '<p class="gr-slide__kicker">Error ' . $status . '</p>'
            . '<h1 class="gr-section__title">' . self::e($title) . '</h1>'
            . '<p>' . self::e($detail) . '</p>'
            . '<p><a class="gr-btn" href="/">Back to homepage</a></p>'
            . '</div></section>';
    }

    /**
     * Article cards in the case idiom (client → title, Read More → slug).
     *
     * @param array<array-key, mixed> $articles
     */
    private function caseCards(array $articles): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p class="gr-empty">Nothing published yet.</p>';
        }

        $cards = '';

        foreach ($pairs as $pair) {
            $cards .= '<article class="gr-case">'
                . '<h2 class="gr-case__client">' . self::e($pair['title']) . '</h2>'
                . '<a class="gr-case__more" href="/blog/' . self::e($pair['slug']) . '">Read More →</a>'
                . '</article>';
        }

        return '<div class="gr-case-grid">' . $cards . '</div>';
    }

    private static function pager(PageContext $context): string
    {
        if ($context->totalPages < 2) {
            return '';
        }

        $html = '<nav class="gr-cloud" aria-label="Pages">';

        for ($i = 1; $i <= $context->totalPages; $i++) {
            $html .= '<a class="gr-chip' . ($i === $context->page ? ' gr-chip--active' : '') . '"'
                . ' href="?page=' . $i . '">' . $i . '</a>';
        }

        return $html . '</nav>';
    }

    private static function section(string $title, string $body): string
    {
        return '<section class="gr-cases"><div class="gr-container">'
            . '<h1 class="gr-section__title">' . self::e($title) . '</h1>'
            . $body
            . '</div></section>';
    }

    /**
     * @param array<array-key, mixed> $articles
     */
    private static function links(array $articles): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p class="gr-empty">Nothing published yet.</p>';
        }

        $items = '';

        foreach ($pairs as $pair) {
            $items .= '<li><a href="/blog/' . self::e($pair['slug']) . '">'
                . self::e($pair['title']) . '</a></li>';
        }

        return '<ul class="gr-list">' . $items . '</ul>';
    }

    /**
     * @param list<ShellNavItem> $items
     */
    private function menu(array $items): string
    {
        if ([] === $items) {
            return '';
        }

        $links = '';

        foreach ($items as $item) {
            $links .= $this->menuLink($item);
        }

        return '<nav class="gr-nav" aria-label="Primary">' . $links . '</nav>' . "\n";
    }

    private function menuLink(ShellNavItem $item): string
    {
        $link = '<a class="gr-nav__link" href="' . self::e($item->href) . '"'
            . ($item->active ? ' aria-current="page"' : '')
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        foreach ($item->children as $child) {
            $link .= $this->menuLink($child);
        }

        return $link;
    }

    /**
     * The checkbox the hamburger label toggles on a phone (Mono meatball
     * idiom): the input is first so one `~` selector reaches the menu and
     * the label alike. Rendered only when there is a menu to open.
     */
    private static function menuToggleInput(bool $hasMenu): string
    {
        if (!$hasMenu) {
            return '';
        }

        return '<input type="checkbox" id="gr-nav-toggle" class="gr-nav__toggle-input" aria-label="Open navigation">' . "\n";
    }

    /**
     * The hamburger label: meatball and close icons both ship, CSS shows one
     * at a time. Rendered only beside a menu.
     */
    private static function menuToggleLabel(bool $hasMenu): string
    {
        if (!$hasMenu) {
            return '';
        }

        return '<label class="gr-nav__toggle gr-icon-btn" for="gr-nav-toggle" title="Menu">'
            . '<span class="gr-nav__toggle-icon--meatball" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">'
            . '<circle cx="12" cy="5" r="2"></circle>'
            . '<circle cx="12" cy="12" r="2"></circle>'
            . '<circle cx="12" cy="19" r="2"></circle>'
            . '</svg></span>'
            . '<span class="gr-nav__toggle-icon--close" aria-hidden="true">'
            . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round">'
            . '<path d="M6 6l12 12"></path><path d="M18 6L6 18"></path>'
            . '</svg></span>'
            . '</label>' . "\n";
    }

    /**
     * The shared dark/light switcher (sun in dark mode, moon in light mode).
     * No Griffe JS needed — the click is owned by the theme-toggle handler
     * FrontendLayout inlines for every theme, which wires every
     * `[data-theme-toggle]`; the pre-paint restore comes from the same place.
     */
    private static function themeToggle(): string
    {
        return '<button type="button" class="gr-icon-btn gr-theme-toggle" data-theme-toggle aria-label="Toggle theme">'
            . '<svg class="gr-theme-toggle__sun" width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="5"></circle>'
            . '<line x1="12" y1="1" x2="12" y2="3"></line>'
            . '<line x1="12" y1="21" x2="12" y2="23"></line>'
            . '<line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>'
            . '<line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>'
            . '<line x1="1" y1="12" x2="3" y2="12"></line>'
            . '<line x1="21" y1="12" x2="23" y2="12"></line>'
            . '<line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>'
            . '<line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>'
            . '</svg>'
            . '<svg class="gr-theme-toggle__moon" width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>'
            . '</svg>'
            . '</button>' . "\n";
    }

    private function localeLinks(ShellContext $context): string
    {
        if (count($context->availableLocales) < 2) {
            return '';
        }

        $links = '';

        foreach ($context->availableLocales as $language) {
            $href = $context->localeLinks[$language] ?? '/' . $language . $context->currentPath;

            $links .= '<a class="gr-locale__link" href="' . self::e($href) . '"'
                . ($language === $context->activeLocale ? ' aria-current="true"' : '')
                . '>' . self::e($language) . '</a>';
        }

        return '<div class="gr-locale">' . $links . '</div>' . "\n";
    }

    /**
     * @param list<ShellNavItem> $items
     */
    private function footerLinks(array $items): string
    {
        if ([] === $items) {
            return '';
        }

        $links = '';

        foreach ($items as $item) {
            $links .= '<a href="' . self::e($item->href) . '">'
                . self::e($item->label) . '</a>';
        }

        return '<div class="gr-footer__links">' . $links . '</div>';
    }

    /**
     * Inline a vendored script with the CSP nonce placeholder, '' when the
     * file is absent (a theme must never fatal on a missing asset).
     */
    private static function inlineScript(string $srcRelative): string
    {
        $js = InlineJs::file($srcRelative);

        if ('' === $js) {
            return '';
        }

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . $js . '</script>' . "\n";
    }

    private static function e(mixed $value): string
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
