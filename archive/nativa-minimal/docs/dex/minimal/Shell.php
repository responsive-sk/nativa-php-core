<?php

declare(strict_types = 1);

namespace Themes\minimal;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Themes\minimal\Chrome\Controls;
use Themes\minimal\Chrome\Nav;
use Themes\minimal\Chrome\Scripts;

/**
 * Shell — the minimal theme's public page shell (facade).
 *
 * Rendered by FrontendLayout when the active theme is 'minimal'. Keeps the
 * render() heredoc + criticalCss() contract; the header nav/user link live
 * in Themes\minimal\Chrome\Nav, the controls in Themes\minimal\Chrome\Controls,
 * the inline scripts in Themes\minimal\Chrome\Scripts and the critical-CSS
 * sections are real .css assets under assets/critical/ (loaded via InlineCss,
 * byte-parity guarded).
 *
 * Copy is bilingual through the Locales translation system (min.* keys,
 * EN fallback defaults) — same t() pattern as MagazineShell.
 */
final class Shell
{
    /**
     * Full minimal page — head (shared SEO contracts + critical CSS +
     * deferred minimal CSS + theme init) and body (header, mobile menu,
     * main, footer, back-to-top, minimal JS).
     */
    public static function render(
        string $title,
        string $content,
        string $siteName,
        string $description,
        string $currentPath,
        string $htmlLang,
        string $localeSwitcher,
        string $canonicalTag,
        string $hreflangTags,
        string $ogTags,
        string $noindexTag,
        string $structuredTag,
        string $feedTags = '',
    ): string {
        $assets = new AssetManager('starter');
        $cssUrls = $assets->cssUrls('minimal');
        $jsUrl = $assets->jsUrl('minimal');
        $year = date('Y');

        $styleTag = '<style nonce="__CSP_NONCE_PLACEHOLDER__">' . "\n"
            . self::criticalCss() . "\n" . '</style>' . "\n";
        $deferredCss = Controls::deferredCss($cssUrls);
        $jsTag = null !== $jsUrl
            ? '<script src="' . FrontendLayout::e($jsUrl) . '" defer></script>' . "\n"
            : '';

        $headerNav = Nav::navHtml($currentPath);
        $themeButton = Controls::themeButton();
        $userLink = Nav::userLink();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$htmlLang}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="theme-color" content="#0f172a">
            <link rel="icon" type="image/svg+xml" href="/favicon.svg">
            <link rel="icon" type="image/x-icon" href="/favicon.ico">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
            <title>{$title}</title>
            <meta name="description" content="{$description}">
            {$canonicalTag}
            {$hreflangTags}
            {$ogTags}
            {$noindexTag}
            {$structuredTag}
            {$feedTags}
            {$styleTag}
            {$deferredCss}
        HTML . Scripts::themeInitTag() . <<<HTML

        </head>
        <body class="minimal-body">
            <header class="minimal-header">
                <div class="minimal-header__inner">
                    <a href="/" class="minimal-header__brand">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 2L2 22h20L12 2z"/>
                        </svg>
                        <span>{$siteName}</span>
                    </a>
                    <input type="checkbox" id="minimal-menu-toggle" class="minimal-menu-toggle__input" aria-hidden="true" tabindex="-1">
                    <div class="navbar__actions">
                        {$headerNav}
                        {$localeSwitcher}
                        {$themeButton}
                        {$userLink}
                        <label for="minimal-menu-toggle" class="minimal-header__toggle" aria-label="Toggle navigation menu" tabindex="0">
                            <svg class="minimal-header__toggle-icon minimal-header__toggle-icon--open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                            </svg>
                            <svg class="minimal-header__toggle-icon minimal-header__toggle-icon--close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none;">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </label>
                    </div>
                    <label for="minimal-menu-toggle" class="minimal-header__overlay" aria-hidden="true"></label>
                </div>
            </header>
            <main class="minimal-main">
                {$content}
            </main>
            <footer class="minimal-footer">
                <div class="minimal-footer__inner">
                    <p class="minimal-footer__text">© {$year} {$siteName} — PHP 8.5, zero dependencies</p>
                </div>
            </footer>
            <button type="button" class="minimal-backtotop" id="min-backtotop" aria-label="Back to top" title="Back to top">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 15l-6-6-6 6"/>
                </svg>
            </button>
        HTML . Scripts::interactionTag() . <<<HTML

            {$jsTag}
        </body>
        </html>
        HTML;
    }

    /**
     * Minimal critical CSS — dev fallback parity: the starter critical
     * shell (tokens, st-locale / st-theme-toggle classes are reused by the
     * minimal header) plus the minimal-specific above-fold styles, in the
     * exact pre-split concatenation order (no separators — heredoc
     * boundaries ate the trailing newlines; byte-parity contract).
     */
    public static function criticalCss(): string
    {
        return FrontendLayout::criticalCss()
            . InlineCss::files([
                'Themes/minimal/assets/critical/shell.css',
                'Themes/minimal/assets/critical/mobile.css',
                'Themes/minimal/assets/critical/content.css',
                'Themes/minimal/assets/critical/article.css',
            ]);
    }
}
