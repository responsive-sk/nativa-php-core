<?php

declare(strict_types = 1);

namespace Modules\Site\Interfaces\HTTP;

use Shell\AsHomepageSurface;
use Shell\Homepage;
use Shell\HomepageSurface;
use Shell\PageContext;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Site\Application\SiteCopy;

/**
 * SiteHomepageSurface — the landing page this site owns, not the framework's.
 *
 * The kernel answers `/` with whatever the active theme composes, and every
 * bundled theme composes the Nativa pitch: measured 2026-11-19, the
 * responsive.sk artifact served "Keep PHP. Drop the framework." as its front
 * door, because that copy is spelled out inside `BosonTheme` and no translation
 * row could reach it.
 *
 * This surface replaces the CONTENT and the head it can name; the route, the
 * shell (header, footer, menu, language switcher), the canonical URL and the
 * `WebSite` JSON-LD stay the kernel's. It renders its own `site-` classes with a
 * small inline stylesheet, so it does not depend on a theme's vocabulary and a
 * theme swap does not silently unstyle the page.
 *
 * Words live in `SiteCopy` — one array per locale. Nothing here is translated
 * through `locales.db`: a landing page is a handful of sentences that belong to
 * the site, and a missing row would leave a hole rather than a sentence.
 */
#[AsHomepageSurface]
final class SiteHomepageSurface implements HomepageSurface
{
    public function homepage(PageContext $context): Homepage
    {
        $siteName = $context->copy['title'] ?? 'responsive.sk';
        $copy = SiteCopy::for($context->locale, $siteName);

        return new Homepage(
            content: self::stylesheet() . self::sections($copy, $context),
            title: is_string($copy['seo']['title'] ?? null) ? $copy['seo']['title'] : null,
            description: is_string($copy['seo']['description'] ?? null) ? $copy['seo']['description'] : null,
            lang: is_string($copy['lang'] ?? null) ? $copy['lang'] : null,
            // Absolute: a crawler does not resolve `og:image` against the page.
            ogImage: $context->request->absoluteUrl(SiteCopy::shareImage()),
            hreflang: self::hreflang($context),
        );
    }

    /**
     * @param array<string, mixed> $copy
     */
    private static function sections(array $copy, PageContext $context): string
    {
        return '<div class="site-home">'
            . self::hero($copy, $context)
            . self::cards(
                'sluzby',
                self::text($copy, 'services', 'label'),
                self::text($copy, 'services', 'title'),
                is_array($copy['services']['items'] ?? null) ? $copy['services']['items'] : [],
                numbered: false,
            )
            . self::cards(
                'postup',
                self::text($copy, 'process', 'label'),
                self::text($copy, 'process', 'title'),
                is_array($copy['process']['items'] ?? null) ? $copy['process']['items'] : [],
                numbered: true,
            )
            . self::cards(
                'pre-koho',
                self::text($copy, 'audience', 'label'),
                self::text($copy, 'audience', 'title'),
                is_array($copy['audience']['items'] ?? null) ? $copy['audience']['items'] : [],
                numbered: false,
                link: is_array($copy['audience']['link'] ?? null) ? $copy['audience']['link'] : null,
            )
            . self::stack($copy, $context)
            . self::contact($copy)
            . '</div>';
    }

    /**
     * @param array<string, mixed> $copy
     */
    private static function hero(array $copy, PageContext $context): string
    {
        $blogHref = self::blogHref($context);

        return '<section class="site-hero">'
            . '<p class="site-hero__eyebrow">' . self::e(self::text($copy, 'hero', 'eyebrow')) . '</p>'
            . '<h1 class="site-hero__title">'
            . '<span class="site-hero__title-line">' . self::e(self::text($copy, 'hero', 'title')) . '</span> '
            . '<span class="site-hero__title-accent">' . self::e(self::text($copy, 'hero', 'accent')) . '</span>'
            . '</h1>'
            . '<p class="site-hero__text">' . self::e(self::text($copy, 'hero', 'text')) . '</p>'
            . '<p class="site-hero__actions">'
            . '<a class="site-button site-button--primary" href="#kontakt">'
            . self::e(self::text($copy, 'hero', 'primary')) . '</a>'
            . '<a class="site-button" href="' . self::e($blogHref) . '">'
            . self::e(self::text($copy, 'hero', 'secondary')) . '</a>'
            . '</p>'
            . '</section>';
    }

    /**
     * One labelled band of cards — the shape every content section shares.
     *
     * @param list<array{title: string, text: string}> $items
     */
    private static function cards(string $id, string $label, string $title, array $items, bool $numbered, ?array $link = null): string
    {
        $html = '<section class="site-section" id="' . self::e($id) . '">'
            . '<p class="site-section__label">' . self::e($label) . '</p>'
            . '<h2 class="site-section__title">' . self::e($title) . '</h2>'
            . '<div class="site-grid">';

        foreach ($items as $index => $item) {
            $mark = $numbered
                ? '<span class="site-card__number">' . self::e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) . '</span>'
                : '';

            $html .= '<article class="site-card">'
                . $mark
                . '<h3 class="site-card__title">' . self::e((string) ($item['title'] ?? '')) . '</h3>'
                . '<p class="site-card__text">' . self::e((string) ($item['text'] ?? '')) . '</p>'
                . '</article>';
        }

        $html .= '</div>';

        // The section's own link, when the copy names one: a claim that has a
        // page behind it should point at the page instead of restating it.
        $href = is_string($link['href'] ?? null) ? $link['href'] : '';
        $label = is_string($link['label'] ?? null) ? $link['label'] : '';

        if ('' !== $href && '' !== $label) {
            $html .= '<p class="site-section__more"><a href="' . self::e($href) . '">'
                . self::e($label) . '</a></p>';
        }

        return $html . '</section>';
    }

    /**
     * The technology tree: backend in PHP with Nativa or Laravel, frontend in
     * TypeScript with React, Svelte or Vue.
     *
     * A flat row of chips listed every word once — `PHP 8.5`, `SQLite`,
     * `JavaScript a TypeScript` — and a visitor could not tell which tool went
     * with which layer. The nesting is the answer to "what do you build this in",
     * so it is the markup, not a sentence explaining the markup.
     *
     * @param array<string, mixed> $copy
     */
    private static function stack(array $copy, PageContext $context): string
    {
        // Where the blog lives for this visitor. Same source as the hero's CTA,
        // so the two links cannot disagree about the blog's address.
        $blog = self::blogHref($context);

        $html = '<section class="site-section" id="technologie">'
            . '<p class="site-section__label">' . self::e(self::text($copy, 'stack', 'label')) . '</p>'
            . '<h2 class="site-section__title">' . self::e(self::text($copy, 'stack', 'title')) . '</h2>'
            . '<p class="site-section__text">' . self::e(self::text($copy, 'stack', 'text')) . '</p>'
            . '<div class="site-stack">';

        $groups = is_array($copy['stack']['groups'] ?? null) ? $copy['stack']['groups'] : [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $html .= '<section class="site-stack__group">'
                . '<h3 class="site-stack__group-title">' . self::e((string) ($group['title'] ?? '')) . '</h3>'
                . '<ul class="site-stack__lines">';

            foreach (is_array($group['lines'] ?? null) ? $group['lines'] : [] as $line) {
                $html .= is_array($line) ? self::stackLine($line, $blog) : '';
            }

            $html .= '</ul></section>';
        }

        return $html . '</div></section>';
    }

    /**
     * One line of a group: a language with the tools under it, or a tool that is
     * not a family — `SQLite` has no children and renders as itself.
     *
     * Every line and every item carries the moment we reach for it, because a
     * name alone tells a visitor what we have used and not what it is for.
     *
     * @param array<string, mixed> $line
     */
    private static function stackLine(array $line, string $blog): string
    {
        $html = '<li class="site-stack__line">'
            . self::stackName('site-stack__line-title', (string) ($line['title'] ?? ''), self::tagHref($blog, $line['tag'] ?? null))
            . self::stackNote('site-stack__line-note', $line['note'] ?? null);

        $items = is_array($line['items'] ?? null) ? $line['items'] : [];

        if ([] === $items) {
            return $html . '</li>';
        }

        $html .= '<ul class="site-stack__items">';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $html .= '<li class="site-stack__item">'
                . self::stackName('site-stack__item-title', (string) ($item['title'] ?? ''), self::tagHref($blog, $item['tag'] ?? null))
                . self::stackNote('site-stack__item-note', $item['note'] ?? null)
                . '</li>';
        }

        return $html . '</ul></li>';
    }

    private static function stackName(string $class, string $text, string $href = ''): string
    {
        return '' === $href
            ? '<span class="' . $class . '">' . self::e($text) . '</span>'
            : '<a class="' . $class . ' site-stack__link" href="' . self::e($href) . '">' . self::e($text) . '</a>';
    }

    /**
     * The tag page for a name, or nothing when the copy names no tag: a link to
     * `/blog/tags/<tag>` that no post carries is a 404, so the absence of a tag
     * has to mean an unlinked name rather than a broken one.
     */
    private static function tagHref(string $blog, mixed $tag): string
    {
        return is_string($tag) && '' !== trim($tag)
            ? $blog . '/tags/' . rawurlencode($tag)
            : '';
    }

    /**
     * The note is optional: copy that has no sentence for a tool still renders a
     * tool, so a half-written section is thin rather than broken.
     */
    private static function stackNote(string $class, mixed $note): string
    {
        return is_string($note) && '' !== trim($note)
            ? '<span class="' . $class . '">' . self::e($note) . '</span>'
            : '';
    }

    /**
     * @param array<string, mixed> $copy
     */
    private static function contact(array $copy): string
    {
        $contacts = SiteCopy::contacts();
        $email = $contacts['email'];
        $phone = $contacts['phone'];
        $rows = '';

        if ('' !== $email) {
            $rows .= '<li><span>' . self::e(self::text($copy, 'contact', 'email_label')) . '</span>'
                . '<a href="mailto:' . self::e($email) . '">' . self::e($email) . '</a></li>';
        }

        if ('' !== $phone) {
            $rows .= '<li><span>' . self::e(self::text($copy, 'contact', 'phone_label')) . '</span>'
                . '<a href="tel:' . self::e(preg_replace('/\s+/', '', $phone) ?? $phone) . '">' . self::e($phone) . '</a></li>';
        }

        return '<section class="site-contact" id="kontakt">'
            . '<p class="site-section__label">' . self::e(self::text($copy, 'contact', 'label')) . '</p>'
            . '<h2 class="site-contact__title">' . self::e(self::text($copy, 'contact', 'title')) . '</h2>'
            . '<p class="site-contact__text">' . self::e(self::text($copy, 'contact', 'text')) . '</p>'
            . ('' === $rows ? '' : '<ul class="site-contact__rows">' . $rows . '</ul>')
            . '</section>';
    }

    /**
     * The blog in the visitor's language: `/blog` for the default locale,
     * `/en/blog` for English.
     *
     * `PageContext::$blogHref` is the port meant to carry this, but the homepage
     * context is built without one, so it answers `/blog` for every language and
     * `/en` sent its readers to the Slovak posts. The prefixes are the locator's
     * knowledge (`''` for the default, `/en` for the rest), so they come from the
     * ContentLocale mapper; no mapper means no prefix to claim.
     */
    private static function blogHref(PageContext $context): string
    {
        if (!class_exists(ContentLocaleServices::class)) {
            return rtrim($context->blogHref ?? '/blog', '/');
        }

        $prefix = ContentLocaleServices::mapper()->urlPrefix($context->locale);

        return rtrim($prefix . '/blog', '/');
    }

    /**
     * The page's language alternates: one absolute URL per published locale,
     * plus `x-default` pointing at the default locale's version.
     *
     * The prefixes are the locator's knowledge, not this module's, so they come
     * from the ContentLocale mapper (`''` for the default, `/en` for the rest).
     * Measured before this existed (2026-11-19): `/`, `/sk` and `/en` all served
     * the landing with one canonical and no alternates, so a crawler could not
     * tell the two languages apart at the front door, while `/blog` pages had
     * alternates from the Blog module's own pair logic.
     *
     * No mapper, no alternates: a tree without the module renders the page and
     * says nothing it cannot back up.
     *
     * @return array<string, string>
     */
    private static function hreflang(PageContext $context): array
    {
        if (!class_exists(ContentLocaleServices::class)) {
            return [];
        }

        $mapper = ContentLocaleServices::mapper();
        $base = $context->request->absoluteUrl('');
        $links = [];

        foreach ($mapper->locales() as $code) {
            $links[$mapper->languageCode($code)] = $base . $mapper->urlPrefix($code) . '/';
        }

        $default = $mapper->defaultLocale();
        $links['x-default'] = $base . $mapper->urlPrefix($default) . '/';

        return $links;
    }

    /**
     * @param array<string, mixed> $copy
     */
    private static function text(array $copy, string $section, string $key): string
    {
        $value = $copy[$section][$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The page's own stylesheet, inlined with the CSP nonce placeholder the
     * renderer replaces (the same technique `AuthTemplate` and the themes use).
     *
     * Deliberately self-contained: it styles the `site-` classes this file
     * emits and nothing else, so it neither depends on nor disturbs whichever
     * theme's shell wraps it.
     */
    private static function stylesheet(): string
    {
        return <<<'HTML'
        <style nonce="__CSP_NONCE_PLACEHOLDER__">
        .site-home{--site-ink:currentColor;--site-muted:color-mix(in srgb,currentColor 62%,transparent);--site-line:color-mix(in srgb,currentColor 16%,transparent);--site-accent:#2563eb;--site-surface:color-mix(in srgb,currentColor 4%,transparent);max-width:72rem;margin:0 auto;padding:4rem 1.5rem 5rem;line-height:1.6}
        .site-hero{max-width:48rem;margin-bottom:5rem}
        .site-hero__eyebrow{margin:0 0 1rem;font-size:.8rem;letter-spacing:.14em;text-transform:uppercase;color:var(--site-muted)}
        .site-hero__title{margin:0 0 1.5rem;font-size:clamp(2.1rem,5vw,3.5rem);line-height:1.1;letter-spacing:-.02em;text-wrap:balance}
        .site-hero__title-line{display:block}
        .site-hero__title-accent{display:block;color:var(--site-accent)}
        .site-hero__text{margin:0 0 2rem;font-size:1.125rem;color:var(--site-muted);max-width:38rem}
        .site-hero__actions{display:flex;flex-wrap:wrap;gap:.75rem;margin:0}
        .site-button{display:inline-flex;align-items:center;justify-content:center;padding:.75rem 1.4rem;border:1px solid var(--site-line);border-radius:.4rem;color:inherit;text-decoration:none;font-weight:600}
        .site-button--primary{background:var(--site-accent);border-color:var(--site-accent);color:#fff}
        .site-button:hover{border-color:var(--site-accent)}
        .site-section{margin:0 0 4.5rem}
        .site-section__label{margin:0 0 .5rem;font-size:.78rem;letter-spacing:.14em;text-transform:uppercase;color:var(--site-muted)}
        .site-section__title{margin:0 0 2rem;font-size:clamp(1.5rem,3vw,2.1rem);line-height:1.2;letter-spacing:-.01em}
        .site-section__text{margin:0;color:var(--site-muted)}
        .site-section__more{margin:2rem 0 0;font-weight:600}
        .site-section__more a{color:var(--site-accent);text-decoration:none;border-bottom:1px solid color-mix(in srgb,var(--site-accent) 40%,transparent)}
        .site-section--split{display:grid;gap:2rem;grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:start}
        .site-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr))}
        .site-card{border:1px solid var(--site-line);border-radius:.6rem;padding:1.4rem;background:var(--site-surface)}
        .site-card__number{display:block;margin-bottom:.75rem;font-size:.8rem;letter-spacing:.12em;color:var(--site-accent)}
        .site-card__title{margin:0 0 .5rem;font-size:1.05rem}
        .site-card__text{margin:0;font-size:.95rem;color:var(--site-muted)}
        .site-stack{display:grid;gap:.75rem;margin:1.5rem 0 0;grid-template-columns:repeat(auto-fit,minmax(17rem,1fr))}
        .site-stack__group{border:1px solid var(--site-line);border-radius:.6rem;padding:1.1rem 1.2rem;background:var(--site-surface)}
        .site-stack__group-title{margin:0 0 .9rem;font-size:.78rem;letter-spacing:.14em;text-transform:uppercase;color:var(--site-accent)}
        .site-stack__lines{display:grid;gap:1.1rem;margin:0;padding:0;list-style:none}
        .site-stack__line{margin:0}
        .site-stack__line-title{display:block;font-size:.95rem;font-weight:600}
        .site-stack__line-note,.site-stack__item-note{display:block;margin-top:.15rem;font-size:.82rem;line-height:1.45;color:var(--site-muted)}
        .site-stack__items{display:grid;gap:.6rem;margin:.7rem 0 0;padding:0;list-style:none}
        .site-stack__item{border-left:2px solid var(--site-line);padding:.05rem 0 .05rem .7rem}
        .site-stack__item-title{display:block;font-size:.88rem;font-weight:600}
        .site-stack__link{color:var(--site-accent);text-decoration:none;border-bottom:1px solid transparent}
        .site-stack__link:hover{border-bottom-color:currentColor}
        .site-contact{border-top:1px solid var(--site-line);padding-top:3rem}
        .site-contact__title{margin:0 0 .75rem;font-size:clamp(1.5rem,3vw,2rem);line-height:1.2}
        .site-contact__text{margin:0;color:var(--site-muted);max-width:36rem}
        .site-contact__rows{margin:1.5rem 0 0;padding:0;list-style:none;display:grid;gap:.5rem}
        .site-contact__rows li{display:flex;gap:.75rem;flex-wrap:wrap}
        .site-contact__rows span{color:var(--site-muted);min-width:5rem}
        .site-contact__rows a{color:inherit}
        @media (max-width:48rem){.site-section--split{grid-template-columns:1fr}.site-home{padding-top:2.5rem}}
        </style>
        HTML;
    }
}
