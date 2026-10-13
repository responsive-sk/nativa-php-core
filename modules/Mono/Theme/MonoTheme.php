<?php

declare(strict_types = 1);

namespace Modules\Mono\Theme;

use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\ThemeModule;

/**
 * MonoTheme — the evan70.github.io/mono vocabulary as a kernel theme module.
 *
 * The CSS is a vendored, prefixed snapshot (see assets/critical/mono.css):
 * navbar, hero, feature cards, footer. What the preview page never had — the
 * locale switcher, a data-driven nav, the blog views — is DESIGNED in its
 * idiom here (navbar links, card grids), not ported: those views exist in the
 * contract and the theme must answer them.
 *
 * It imports nothing from a feature module. Article shapes are read through
 * `get_object_vars` rather than `use`ing the entity, the established kernel
 * pattern for a theme that must not name the module owning the article.
 */
final class MonoTheme implements ThemeModule
{
    public function name(): string
    {
        return 'mono';
    }

    public function bundle(): string
    {
        return 'mono';
    }

    public function jsKey(): string
    {
        return 'mono';
    }

    /**
     * @return list<string> src/-relative, resolved by InlineCss
     */
    public function criticalCss(): array
    {
        return ['Modules/Mono/assets/critical/mono.css', 'Modules/Mono/assets/critical/mo-variants.css'];
    }

    /**
     * Pre-paint colour-mode resolution: the shared dark/light restore,
     * verbatim, plus the persisted dev variant.
     *
     * Returning '' would keep the shared bytes exactly — but the variant
     * must also apply before first paint, or the page flashes the default
     * palette on every load. The override therefore reproduces the shared
     * block and appends the variant restore; only Mono pages move.
     */
    public function themeInitScript(): string
    {
        return <<<'HTML'
            <!-- Sync theme init — FOUC prevention: restore the persisted theme
                 (or the OS preference) before first paint. Falls back to the
                 dark default when neither is available (Chrome 70 baseline). -->
            <script nonce="__CSP_NONCE_PLACEHOLDER__">
                (function() {
                    try {
                        var theme = localStorage.getItem('nativa-theme');
                        if (!theme) {
                            theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                        }
                        document.documentElement.setAttribute('data-theme', theme);
                    } catch (e) {}
                })();
            </script>
            <!-- Sync dev variant — same FOUC contract as the theme above:
                 unknown values are ignored, so a stale stored variant can
                 never leave the page unstyled. -->
            <script nonce="__CSP_NONCE_PLACEHOLDER__">
                (function() {
                    try {
                        var variant = localStorage.getItem('nativa-variant');
                        if ('ember' === variant || 'glacier' === variant) {
                            document.documentElement.setAttribute('data-variant', variant);
                        }
                    } catch (e) {}
                })();
            </script>
            <!-- Sync slider staging signal — same pre-paint contract as above:
                 the stylesheet stages a multi-slide hero the moment `mo-js`
                 is present, so the deferred bundle never reflows a stacked
                 opening frame. Skipped under reduced motion (the calm
                 fallback stays stacked) — `classList` and `matchMedia` are
                 both older than the Chrome 70 baseline. -->
            <script nonce="__CSP_NONCE_PLACEHOLDER__">
                (function() {
                    try {
                        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                            return;
                        }
                        document.documentElement.classList.add('mo-js');
                    } catch (e) {}
                })();
            </script>
            HTML;
    }

    public function prefix(): string
    {
        return 'mo';
    }

    public function header(ShellContext $context): string
    {
        $hasMenu = [] !== $context->headerNav;

        return '<nav class="mo-navbar">'
            . '<div class="mo-navbar__container mo-container">'
            . $this->menuToggleInput($hasMenu)
            . '<a class="mo-navbar__brand" href="/">' . self::e($context->siteName) . '</a>'
            . $this->menu($context->headerNav)
            . '<div class="mo-navbar__actions">'
            . self::themeToggle()
            . self::variantToggle('ember', 'dev-theme-switcher', 'Toggle Ember variant', self::flameIcon())
            . self::variantToggle('glacier', 'dev-variant-switcher-2', 'Toggle Glacier variant', self::snowflakeIcon())
            . $this->menuToggleLabel($hasMenu)
            . $this->localeLinks($context)
            . '</div>'
            . '</div>'
            . '</nav>' . "\n";
    }

    public function footer(ShellContext $context): string
    {
        return '<footer class="mo-footer">'
            . '<div class="mo-footer__container">'
            . '<div class="mo-footer__bottom">'
            . '<p class="mo-footer__copyright">© ' . self::e($context->year) . ' ' . self::e($context->siteName) . '</p>'
            . $this->footerLinks($context->footerNav)
            . '</div>'
            . '</div>'
            . '</footer>' . "\n";
    }

    public function pageWrap(string $content): string
    {
        // No inline scripts here: the three behaviours this wrap used to
        // gate per surface (highlight on fences, scrollspy on TOC asides,
        // the ungated variant switcher) ship in the frozen `mono` bundle
        // FrontendLayout defers from the manifest. Each init self-gates on
        // its markup, so a page without fences or a TOC runs nothing. The
        // switcher syncs its buttons on DOMContentLoaded rather than during
        // parsing now — the pre-paint variant restore in themeInitScript()
        // still applies before first paint, so only the button pressed
        // state arrives a tick later, never the palette.
        return '<main>' . "\n" . $content . '</main>' . "\n";
    }

    /**
     * Fullscreen home slider, the griffe shape in the mono idiom: slide 1 is
     * the editorial hero (H1 + lede + CTA from `$copy`), slides 2+ are case
     * slides from `$latestArticles` (falling back to `$articles`, then to the
     * Blog latest-cards port when the action passed none — the kernel home
     * carries no Blog import by contract, so without the fallback the slider
     * would stay a lone hero on every tree that actually publishes). Slide 1
     * is active server-side, so a static render (no JS, reduced motion) still
     * opens on the hero.
     *
     * Every slide's text ships `mo-is-visible`: the slider cross-fades the
     * slide containers, never the titles — a stacked no-JS render stays fully
     * readable, and no bundle timing can leave an empty hero behind.
     */
    public function homepage(PageContext $context): string
    {
        $articles = [] !== $context->latestArticles ? $context->latestArticles : $context->articles;

        if ([] === $articles) {
            $articles = self::latestFallback($context->locale);
        }
        $slides = $this->heroSlide($context) . $this->caseSlides($context, $articles);
        $count = substr_count($slides, '<li class="mo-slide');

        // `mo-hero-slider--multi` lets the stylesheet stage the hero before
        // first paint (see themeInitScript): a lone hero needs no staging —
        // it reads the same stacked or staged — so only 2+ slides opt in.
        $multi = $count >= 2 ? ' mo-hero-slider--multi' : '';

        return '<section class="mo-hero-slider' . $multi . '" data-mo-autoplay="6000" aria-roledescription="carousel">'
            . '<ul class="mo-slides">' . $slides . '</ul>'
            . $this->sliderPag($count)
            . $this->sliderArrows($count)
            . '</section>';
    }

    /**
     * Quiet per-slide tints, cycled — the dark default carries the slide,
     * the light override reverts to warm paper. Colours stay deprioritised,
     * photography stays optional.
     *
     * @var list<array{light: string, dark: string}>
     */
    private const array SLIDE_TINTS = [
        ['light' => '#faf7f0', 'dark' => '#0a0a0a'],
        ['light' => '#f4efe4', 'dark' => '#14110a'],
        ['light' => '#eef2f0', 'dark' => '#0b1210'],
        ['light' => '#f1ecec', 'dark' => '#120d0d'],
    ];

    /**
     * @param array<array-key, mixed> $articles
     */
    private function caseSlides(PageContext $context, array $articles): string
    {
        $service = $context->copy['case_service'] ?? 'Case study';
        $html = '';
        $i = 1;

        foreach (self::pairs($articles) as $pair) {
            $html .= '<li class="mo-slide"' . self::tint($i++) . '>'
                . self::slideBg($pair['image'], $pair['title'])
                . '<div class="mo-container">'
                . '<p class="mo-slide__kicker">' . self::e($service) . '</p>'
                . '<h2 class="mo-slide__title mo-is-visible">' . self::e($pair['title']) . '</h2>'
                . '<a class="mo-btn mo-btn--outline" href="/blog/' . self::e($pair['slug']) . '">Read More →</a>'
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

        // `mo-slide--hero` stages the editorial glow: slide 1 carries no
        // photo (the kernel home passes no heroSrc), so without it the
        // opening stage is a flat tint next to photographic case slides.
        $html = '<li class="mo-slide mo-slide--active mo-slide--hero"' . self::tint(0) . '>'
            . self::slideBg($context->heroSrc, $title, $context->heroSrcset)
            . '<div class="mo-container">'
            . '<h1 class="mo-slide__title mo-is-visible">' . self::e($title) . '</h1>';

        if ('' !== $lede) {
            $html .= '<p class="mo-slide__lede mo-is-visible">' . self::e($lede) . '</p>';
        }

        return $html
            . '<a class="mo-btn" href="' . self::e($href) . '">' . self::e($cta) . '</a>'
            . '</div>'
            . '</li>';
    }

    private static function tint(int $index): string
    {
        $tint = self::SLIDE_TINTS[$index % count(self::SLIDE_TINTS)];

        return sprintf(
            ' style="--mo-slide-bg: %s; --mo-slide-bg-dark: %s;"',
            $tint['light'],
            $tint['dark'],
        );
    }

    /**
     * Full-bleed slide image + legibility shade, '' when the action provides
     * no source. `alt` carries the slide headline — a background with no
     * `<img>` alternative is invisible to assistive tech.
     */
    private static function slideBg(?string $src, string $alt, ?string $srcset = null): string
    {
        if (null === $src || '' === $src) {
            return '';
        }

        $set = null !== $srcset && '' !== $srcset
            ? ' srcset="' . self::e($srcset) . '"'
            : '';

        return '<img class="mo-slide__bg" src="' . self::e($src) . '"' . $set
            . ' alt="' . self::e($alt) . '">' . "\n"
            . '<div class="mo-slide__shade" aria-hidden="true"></div>';
    }

    private function sliderPag(int $count): string
    {
        if ($count < 2) {
            return '';
        }

        $buttons = '';

        for ($i = 0; $i < $count; $i++) {
            $active = 0 === $i ? ' mo-pag--active' : '';
            $current = 0 === $i ? ' aria-current="true"' : '';
            $buttons .= '<button type="button" class="mo-pag' . $active . '"'
                . ' aria-label="Go to slide ' . ($i + 1) . '"' . $current . '>'
                . '<span class="mo-pag__fill"></span></button>';
        }

        return '<div class="mo-slider__pag">' . $buttons . '</div>';
    }

    private function sliderArrows(int $count): string
    {
        if ($count < 2) {
            return '';
        }

        return '<button type="button" class="mo-icon-btn mo-slider__arrow mo-slider__arrow--prev"'
            . ' aria-label="Previous slide">←</button>'
            . '<button type="button" class="mo-icon-btn mo-slider__arrow mo-slider__arrow--next"'
            . ' aria-label="Next slide">→</button>';
    }

    /**
     * Latest published articles when the action passed none. The kernel
     * HomepageAction carries no Blog import by explicit contract, so it never
     * passes any — without this fallback the slider would stay a lone hero on
     * every tree that actually publishes. Guarded by class_exists: a tree
     * without Blog renders the hero alone, exactly as before. A failure here
     * degrades to empty, never to a white homepage — and it is logged, so the
     * silence stays visible.
     *
     * @return list<object>
     */
    private static function latestFallback(string $locale): array
    {
        $facade = 'Modules\\Blog\\Application\\BlogServices';

        if (!class_exists($facade)) {
            return [];
        }

        try {
            $cards = $facade::articles()->latestCards(6, $locale);
        } catch (\Throwable $e) {
            error_log('[MonoTheme] latest fallback failed: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn (array $card): object => (object) $card, $cards);
    }

    public function blogIndex(PageContext $context): string
    {
        return $this->cardSection('Blog', '', $context->articles);
    }

    public function blogSearch(PageContext $context): string
    {
        return $this->cardSection('Search: ' . $context->query, '', $context->results);
    }

    public function blogArticle(PageContext $context): string
    {
        $article = '<article class="mo-card"><div class="mo-card__header">'
            . '<h1 class="mo-card__title">' . self::e($context->title) . '</h1>'
            . '</div><div class="mo-card__body">' . $context->bodyHtml . '</div></article>';
        $toc = self::articleToc($context->headings);

        if ('' === $toc) {
            return '<section class="mo-features-section"><div class="mo-container">'
                . $article
                . '</div></section>';
        }

        return '<section class="mo-features-section"><div class="mo-container">'
            . '<div class="mo-article-layout">' . $article . $toc . '</div>'
            . '</div></section>';
    }

    /**
     * The "On this page" aside, rendered from the headings
     * MarkdownRenderer::extractHeadings() collected — the same HTML the
     * browser sees, so every anchor matches a heading id actually present.
     * Server-side links only: no JS needed to jump, and Mono ships none.
     * The data-toc-link / data-heading opt-ins mirror the shared scrollspy
     * contract, so a future Mono scrollspy can hook in without new markup.
     * '' when the article has no h2/h3 — without headings there is nothing
     * to navigate, and an empty aside would ship a labelled nav to nowhere.
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

            $itemClass = (3 === ($heading['level'] ?? 0)) ? ' mo-toc__item--sub' : '';
            $items .= '<li class="mo-toc__item' . $itemClass . '">'
                . '<a class="mo-toc__link" data-toc-link data-heading="' . self::e($anchor) . '"'
                . ' href="#' . self::e($anchor) . '">' . self::e($text) . '</a></li>';
        }

        if ('' === $items) {
            return '';
        }

        return '<aside class="mo-toc"><nav class="mo-toc__nav" aria-label="On this page">'
            . '<p class="mo-toc__title">On this page</p>'
            . '<ul class="mo-toc__list">' . $items . '</ul>'
            . '</nav></aside>';
    }

    public function blogTags(PageContext $context): string
    {
        $title = null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags';

        return $this->cardSection($title, '', $context->articles);
    }

    public function blogCategories(PageContext $context): string
    {
        $title = null !== $context->category ? $context->category : 'Categories';

        return $this->cardSection($title, '', $context->articles);
    }

    public function authorProfile(PageContext $context): string
    {
        return $this->cardSection($this->authorName($context), '', $context->articles);
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="mo-hero-section">'
            . '<div class="mo-hero-section__content">'
            . '<p class="mo-hero-section__title mo-is-visible">' . $status . '</p>'
            . '<p class="mo-hero-section__description mo-is-visible">' . self::e($title) . ' — ' . self::e($detail) . '</p>'
            . '<div class="mo-hero-section__buttons">'
            . '<a class="mo-btn" href="/">Back to homepage</a>'
            . '</div></div></section>';
    }

    /**
     * @param array<array-key, mixed> $articles article DTOs (slug + title read via get_object_vars, like cards())
     */
    private function cardSection(string $title, string $subtitle, array $articles): string
    {
        $html = '<section class="mo-features-section"><div class="mo-container">'
            . '<header class="mo-features-section__header">'
            . '<h1 class="mo-features-section__title">' . self::e($title) . '</h1>';

        if ('' !== $subtitle) {
            $html .= '<p class="mo-features-section__subtitle">' . self::e($subtitle) . '</p>';
        }

        return $html . '</header>' . $this->cards($articles) . '</div></section>';
    }

    /**
     * Slug + title are required; an image is opportunistic (`image`, `cover`
     * or `coverImage` when the producer carries one) — still no import, still
     * no coupling, just one more optional field off the same shape.
     *
     * @param array<array-key, mixed> $articles
     * @return list<array{slug: string, title: string, image: ?string}>
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
                $image = $fields['image'] ?? $fields['cover'] ?? $fields['coverImage'] ?? null;
                $pairs[] = [
                    'slug' => $slug,
                    'title' => $title,
                    'image' => is_string($image) && '' !== $image ? $image : null,
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param array<array-key, mixed> $articles
     */
    private function cards(array $articles): string
    {
        $pairs = self::pairs($articles);

        if ([] === $pairs) {
            return '<p>Nothing published yet.</p>';
        }

        $items = '';

        foreach ($pairs as $pair) {
            $items .= '<article class="mo-card mo-card--interactive">'
                . '<div class="mo-card__header">'
                . '<h2 class="mo-card__title"><a class="mo-card__link" href="/blog/'
                . self::e($pair['slug']) . '">' . self::e($pair['title']) . '</a></h2>'
                . '</div></article>';
        }

        return '<div class="mo-card-grid mo-card-grid--cols-3">' . $items . '</div>';
    }

    private function authorName(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;

        return is_string($name) && '' !== $name ? $name : 'Author';
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

        return '<div class="mo-navbar__menu">' . $links . '</div>';
    }

    /**
     * The checkbox the meatball label toggles on a phone.
     *
     * The vendored snapshot expects the live site's JS to add
     * `mo-navbar--menu-open` to the navbar; this tree ships no JS, so the open
     * state is a form control and the styling keys off `:checked` instead. The
     * input is first in the container on purpose: the menu and the label both
     * come after it, which is what lets one `~` selector reach them without
     * `:has()`. Rendered only when there is a menu to open — a toggle that
     * opens nothing is worse than no toggle.
     */
    private function menuToggleInput(bool $hasMenu): string
    {
        if (!$hasMenu) {
            return '';
        }

        return '<input type="checkbox" id="mo-nav-toggle" class="mo-navbar__toggle-input" aria-label="Open navigation">';
    }

    /**
     * The dark/light switcher, straight from the upstream navbar__actions
     * (evan70.github.io/mono) with the mo- prefix: sun shows in dark mode,
     * moon in light mode, both in the DOM and CSS showing one at a time.
     *
     * No Mono JS needed — the click is owned by the shared theme-toggle
     * handler FrontendLayout inlines for every theme, which wires every
     * [data-theme-toggle]. The pre-paint restore comes from the same place.
     */
    private static function themeToggle(): string
    {
        return '<button type="button" class="mo-icon-btn mo-theme-toggle" data-theme-toggle aria-label="Toggle theme">'
            . '<svg class="mo-theme-toggle__sun" width="20" height="20" viewBox="0 0 24 24" fill="none"'
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
            . '<svg class="mo-theme-toggle__moon" width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>'
            . '</svg>'
            . '</button>';
    }

    /**
     * One dev variant button: the upstream dev-theme-switcher idiom
     * (evan70.github.io/mono) with a custom Mono palette behind it.
     *
     * The first button keeps the upstream `id="dev-theme-switcher"`; both
     * key off `data-variant-toggle`, which is what the vendored tracker
     * reads. `aria-pressed` ships false and is synced from storage by the
     * tracker on load — static markup cannot know the persisted choice.
     */
    private static function variantToggle(string $variant, string $id, string $label, string $icon): string
    {
        return '<button type="button" class="mo-icon-btn mo-navbar__dev-toggle"'
            . ' id="' . $id . '" data-variant-toggle="' . $variant . '"'
            . ' aria-pressed="false" aria-label="' . $label . '" title="' . $label . '">'
            . $icon . '</button>';
    }

    private static function flameIcon(): string
    {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.292 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path>'
            . '</svg>';
    }

    private static function snowflakeIcon(): string
    {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<line x1="12" y1="2" x2="12" y2="22"></line>'
            . '<line x1="3.34" y1="7" x2="20.66" y2="17"></line>'
            . '<line x1="20.66" y1="7" x2="3.34" y2="17"></line>'
            . '</svg>';
    }

    /**
     * The meatball itself: the visible control the checkbox's label activates.
     *
     * Both icons ship in the DOM and CSS shows one at a time (the vendored
     * rules already hide `--close` in the closed state), so opening the menu
     * costs no JS and no second request.
     */
    private function menuToggleLabel(bool $hasMenu): string
    {
        if (!$hasMenu) {
            return '';
        }

        return '<label class="mo-navbar__toggle mo-icon-btn" for="mo-nav-toggle" title="Menu">'
            . '<span class="mo-navbar__toggle-icon mo-navbar__toggle-icon--meatball" aria-hidden="true">'
            . self::meatballIcon() . '</span>'
            . '<span class="mo-navbar__toggle-icon mo-navbar__toggle-icon--close" aria-hidden="true">'
            . self::closeIcon() . '</span>'
            . '</label>';
    }

    private static function meatballIcon(): string
    {
        return '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">'
            . '<circle cx="12" cy="5" r="2"></circle>'
            . '<circle cx="12" cy="12" r="2"></circle>'
            . '<circle cx="12" cy="19" r="2"></circle>'
            . '</svg>';
    }

    private static function closeIcon(): string
    {
        return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">'
            . '<path d="M6 6l12 12"></path>'
            . '<path d="M18 6L6 18"></path>'
            . '</svg>';
    }

    private function menuLink(ShellNavItem $item): string
    {
        $link = '<a class="mo-navbar__link' . ($item->active ? ' mo-navbar__link--active' : '') . '"'
            . ' href="' . self::e($item->href) . '"'
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        foreach ($item->children as $child) {
            $link .= $this->menuLink($child);
        }

        return $link;
    }

    /**
     * Locale links in the navbar idiom — designed, not ported: the preview
     * page has no switcher, so its languages render as plain navbar links.
     */
    private function localeLinks(ShellContext $context): string
    {
        if (count($context->availableLocales) < 2) {
            return '';
        }

        $links = '';

        foreach ($context->availableLocales as $language) {
            $href = $context->localeLinks[$language] ?? '/' . $language . $context->currentPath;

            $links .= '<a class="mo-navbar__link mo-btn--sm" href="' . self::e($href) . '">'
                . self::e($language) . '</a>';
        }

        return $links;
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
            $links .= '<a class="mo-footer__link" href="' . self::e($item->href) . '">'
                . self::e($item->label) . '</a>';
        }

        return '<div>' . $links . '</div>';
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
