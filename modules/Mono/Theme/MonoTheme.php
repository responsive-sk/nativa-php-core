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
        return ['Modules/Mono/assets/critical/mono.css'];
    }

    public function themeInitScript(): string
    {
        return '';
    }

    public function prefix(): string
    {
        return 'mo';
    }

    public function header(ShellContext $context): string
    {
        return '<nav class="mo-navbar">'
            . '<div class="mo-navbar__container mo-container">'
            . '<a class="mo-navbar__brand" href="/">' . self::e($context->siteName) . '</a>'
            . $this->menu($context->headerNav)
            . '<div class="mo-navbar__actions">' . $this->localeLinks($context) . '</div>'
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
        return '<main>' . "\n" . $content . '</main>' . "\n";
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
        return '<section class="mo-features-section"><div class="mo-container">'
            . '<article class="mo-card"><div class="mo-card__header">'
            . '<h1 class="mo-card__title">' . self::e($context->title) . '</h1>'
            . '</div><div class="mo-card__body">' . $context->bodyHtml . '</div></article>'
            . '</div></section>';
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
