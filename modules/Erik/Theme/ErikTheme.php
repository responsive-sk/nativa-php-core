<?php

declare(strict_types = 1);

namespace Modules\Erik\Theme;

use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Themes\ThemeModule;

/**
 * ErikTheme — the dark-first public theme (er-* vocabulary), trimmed for the
 * kernel tree.
 *
 * The showcase Erik renders hero sections, article grids and locale switchers
 * from Blog/Locales/Settings services; that Erik cannot live here — its
 * imports name modules this tree does not have. This one renders from
 * `ShellContext` + `PageContext` alone, the same contract the kernel theme
 * keeps: the shell (brand, nav, locale switcher, footer), the home surface
 * and the blog family rendered plainly, so a blog module installed alongside
 * still has somewhere to go.
 *
 * It imports nothing from a feature module. Article shapes are read through
 * `get_object_vars` rather than `use`ing the entity: a theme that names the
 * module owning the article is the coupling the kernel exists to avoid.
 */
final class ErikTheme implements ThemeModule
{
    public function name(): string
    {
        return 'erik';
    }

    public function bundle(): string
    {
        return 'erik';
    }

    public function jsKey(): string
    {
        return 'erik';
    }

    /**
     * @return list<string> src/-relative, resolved by InlineCss
     */
    public function criticalCss(): array
    {
        return ['Modules/Erik/assets/critical/erik.css'];
    }

    public function themeInitScript(): string
    {
        return '';
    }

    public function prefix(): string
    {
        return 'er';
    }

    public function header(ShellContext $context): string
    {
        return $this->chrome('header', [
            '<a class="er-header__brand" href="/">' . self::e($context->siteName) . '</a>',
            $this->nav($context->headerNav),
            $this->localeSwitcher($context),
        ]);
    }

    public function footer(ShellContext $context): string
    {
        return $this->chrome('footer', [
            '<span class="er-footer__year">© ' . self::e($context->year) . ' ' . self::e($context->siteName) . '</span>',
            $this->nav($context->footerNav),
        ]);
    }

    public function pageWrap(string $content): string
    {
        return '<main class="er-main">' . "\n"
            . '    ' . $content . "\n"
            . '</main>' . "\n";
    }

    public function homepage(PageContext $context): string
    {
        return $this->pageWrap($this->section($context->copy['title'] ?? 'Home', ''));
    }

    public function blogIndex(PageContext $context): string
    {
        return $this->pageWrap($this->section('Blog', $this->links($context->articles)));
    }

    public function blogSearch(PageContext $context): string
    {
        return $this->pageWrap(
            $this->section('Search: ' . $context->query, $this->links($context->results)),
        );
    }

    public function blogArticle(PageContext $context): string
    {
        return $this->pageWrap(
            '<article class="er-article">'
            . '<h1 class="er-section__title">' . self::e($context->title) . '</h1>'
            . '<div class="er-article__body">' . $context->bodyHtml . '</div>'
            . '</article>',
        );
    }

    public function blogTags(PageContext $context): string
    {
        $title = null !== $context->tag ? 'Tag: ' . $context->tag : 'Tags';

        return $this->pageWrap($this->section($title, $this->links($context->articles)));
    }

    public function blogCategories(PageContext $context): string
    {
        $title = null !== $context->category ? $context->category : 'Categories';

        return $this->pageWrap($this->section($title, $this->links($context->articles)));
    }

    public function authorProfile(PageContext $context): string
    {
        return $this->pageWrap($this->section($this->authorName($context), $this->links($context->articles)));
    }

    public function errorContent(int $status, string $title, string $detail): string
    {
        return '<section class="er-section">'
            . '<h1 class="er-section__title">' . $status . ' ' . self::e($title) . '</h1>'
            . '<p>' . self::e($detail) . '</p>'
            . '<p><a href="/">Back to homepage</a></p>'
            . '</section>';
    }

    private function section(string $title, string $body): string
    {
        return '<section class="er-section">'
            . '<h1 class="er-section__title">' . self::e($title) . '</h1>'
            . $body
            . '</section>';
    }

    /**
     * @param array<array-key, mixed> $articles
     */
    private function links(array $articles): string
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
            return '<p class="er-section__empty">Nothing published yet.</p>';
        }

        $items = '';

        foreach ($pairs as $pair) {
            $items .= '<li><a href="/blog/' . self::e($pair['slug']) . '">'
                . self::e($pair['title']) . '</a></li>';
        }

        return '<ul class="er-list">' . $items . '</ul>';
    }

    private function authorName(PageContext $context): string
    {
        $author = $context->author ?? [];
        $name = $author['name'] ?? null;

        return is_string($name) && '' !== $name ? $name : 'Author';
    }

    /**
     * @param list<string> $parts
     */
    private function chrome(string $tag, array $parts): string
    {
        $html = '<' . $tag . ' class="er-' . $tag . '">' . "\n";

        foreach ($parts as $part) {
            if ('' !== $part) {
                $html .= '    ' . $part . "\n";
            }
        }

        return $html . '</' . $tag . '>' . "\n";
    }

    /**
     * @param list<ShellNavItem> $items
     */
    private function nav(array $items): string
    {
        if ([] === $items) {
            return '';
        }

        $links = '';

        foreach ($items as $item) {
            $links .= $this->link($item);
        }

        return '<nav class="er-nav">' . $links . '</nav>';
    }

    private function link(ShellNavItem $item): string
    {
        $link = '<a class="er-nav__link" href="' . self::e($item->href) . '"'
            . ($item->active ? ' aria-current="page"' : '')
            . ($item->external ? ' rel="noopener noreferrer"' : '')
            . '>' . self::e($item->label) . '</a>';

        foreach ($item->children as $child) {
            $link .= $this->link($child);
        }

        return $link;
    }

    private function localeSwitcher(ShellContext $context): string
    {
        if (count($context->availableLocales) < 2) {
            return '';
        }

        $links = '';

        foreach ($context->availableLocales as $language) {
            $href = $context->localeLinks[$language] ?? '/' . $language . $context->currentPath;

            $links .= '<a class="er-locale__link" href="' . self::e($href) . '"'
                . ($language === $context->activeLocale ? ' aria-current="true"' : '')
                . '>' . self::e($language) . '</a>';
        }

        return '<div class="er-locale">' . $links . '</div>';
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
