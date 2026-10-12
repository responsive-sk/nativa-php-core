<?php

declare(strict_types = 1);

namespace Modules\Mono\Theme;

use Modules\Core\Application\Services\View\InlineJs;
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
        return '<main>' . "\n" . $content . '</main>' . "\n" . self::codeHighlight($content) . self::tocScrollspy($content) . self::variantSwitcher();
    }

    /**
     * The syntax highlighter, inline and only where fenced blocks exist.
     *
     * Mono has no Frontend package and no build, so it cannot bundle the
     * shared code-highlight.ts — mo-code-highlight.js is its 1:1 no-build
     * twin, loaded here through InlineJs::file like the admin shell scripts.
     * The nonce placeholder is substituted per response by the security
     * headers middleware, exactly like every other inline script. Pages
     * without fences ship no JS at all (the no-JS fallback is the plain
     * mono code block, still readable, just one colour).
     */
    private static function codeHighlight(string $content): string
    {
        if (!str_contains($content, 'language-')) {
            return '';
        }

        $js = InlineJs::file('Modules/Mono/assets/scripts/mo-code-highlight.js');

        if ('' === $js) {
            return '';
        }

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . $js . '</script>' . "\n";
    }

    /**
     * The dev variant switcher, inline on every page.
     *
     * Unlike the highlighter and the scrollspy this one cannot gate on
     * content: its buttons live in the navbar, which precedes every page.
     * ~1 kB of ES2015-safe JS, idle after wiring the two buttons — the
     * price of the dev preview on every Mono response.
     */
    private static function variantSwitcher(): string
    {
        $js = InlineJs::file('Modules/Mono/assets/scripts/mo-variant-switcher.js');

        if ('' === $js) {
            return '';
        }

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . $js . '</script>' . "\n";
    }

    /**
     * The TOC scrollspy, inline and only where a TOC aside exists.
     *
     * Same deal as the highlighter above: mo-toc-scrollspy.js is the 1:1
     * no-build twin of the shared toc-scrollspy.ts, gated here on the
     * data-toc-link opt-in the tracker itself keys off — a page whose TOC
     * links lost the attribute gets no script, and the tracker would idle
     * anyway. Without JS the aside stays a plain list of working anchors.
     */
    private static function tocScrollspy(string $content): string
    {
        if (!str_contains($content, 'data-toc-link')) {
            return '';
        }

        $js = InlineJs::file('Modules/Mono/assets/scripts/mo-toc-scrollspy.js');

        if ('' === $js) {
            return '';
        }

        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">' . $js . '</script>' . "\n";
    }

    public function homepage(PageContext $context): string
    {
        $title = $context->copy['title'] ?? 'Home';

        // mo-is-visible is rendered server-side: on the live site it is added
        // by scroll JS, but an above-fold hero is visible on load — and this
        // tree ships no JS that would add it later. Without the class the
        // title keeps opacity:0 and the homepage renders its own H1 invisible.
        return '<section class="mo-hero-section mo-hero-section--fw">'
            . '<div class="mo-hero-section__content">'
            . '<h1 class="mo-hero-section__title mo-is-visible">' . self::e($title) . '</h1>'
            . '</div>'
            . '</section>';
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
     * @param array<array-key, mixed> $articles
     */
    private function cards(array $articles): string
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
