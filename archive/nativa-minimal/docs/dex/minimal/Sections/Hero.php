<?php

declare(strict_types = 1);

namespace Themes\minimal\Sections;

use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * Hero — the centered hero (eyebrow, title, description, optional CTA
 * actions), the primary/outline CTA buttons (the minimal-btn contract) and
 * the CTA strip.
 */
final class Hero
{
    /**
     * Centered hero — eyebrow, title, description, optional CTA actions.
     */
    public static function hero(string $title, string $desc, string $eyebrow = '', string $actions = ''): string
    {
        $eyebrowHtml = '' !== $eyebrow
            ? '<span class="minimal-hero__eyebrow">' . FrontendLayout::e($eyebrow) . '</span>'
            : '';
        $actionsHtml = '' !== $actions ? '<div class="minimal-hero__actions">' . $actions . '</div>' : '';

        return '<section class="minimal-hero">'
            . '<div class="minimal-container">'
            . '<div class="minimal-hero__content">'
            . $eyebrowHtml
            . '<h1 class="minimal-hero__title">' . FrontendLayout::e($title) . '</h1>'
            . '<p class="minimal-hero__desc">' . FrontendLayout::e($desc) . '</p>'
            . $actionsHtml
            . '</div>'
            . '</div>'
            . '</section>';
    }

    /**
     * Primary / outline CTA buttons (the minimal-btn contract).
     */
    public static function primaryButton(string $label, string $href): string
    {
        return '<a href="' . FrontendLayout::e($href) . '" class="minimal-btn minimal-btn--primary">' . FrontendLayout::e($label) . '</a>';
    }

    public static function outlineButton(string $label, string $href): string
    {
        return '<a href="' . FrontendLayout::e($href) . '" class="minimal-btn minimal-btn--outline">' . FrontendLayout::e($label) . '</a>';
    }

    /**
     * CTA strip — title, text, action buttons (per the source home page).
     */
    public static function cta(string $title, string $text, string $actions): string
    {
        return Support::section(
            '<div class="minimal-cta">'
            . '<h2 class="minimal-cta__title">' . FrontendLayout::e($title) . '</h2>'
            . '<p class="minimal-cta__text">' . FrontendLayout::e($text) . '</p>'
            . '<div class="minimal-cta__actions">' . $actions . '</div>'
            . '</div>',
        );
    }
}
