<?php

declare(strict_types = 1);

namespace Themes\magazine;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Application\Services\View\InlineJs;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Themes\magazine\Chrome\Controls;
use Themes\magazine\Chrome\Nav;

/**
 * Shell — the magazine theme's public page shell (facade).
 *
 * Rendered by FrontendLayout when the active theme is 'magazine'. Keeps the
 * render() heredoc + criticalCss() contract; the chrome lives in
 * Chrome\{Nav,Controls} and the critical-CSS sections are real .css assets
 * under assets/critical/ (loaded via InlineCss, byte-parity guarded).
 *
 * Copy is bilingual through the Locales translation system (mag.* keys,
 * EN fallback defaults) — same t() pattern as HomepageAction.
 */
final class Shell
{
    /**
     * Full magazine page — head (shared SEO contracts + critical CSS +
     * deferred magazine CSS + theme init) and body (header, mobile menu,
     * main, footer, back-to-top, theme toggle, magazine JS).
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
        $cssUrls = $assets->cssUrls('magazine');
        $jsUrl = $assets->jsUrl('magazine');
        $year = date('Y');

        $styleTag = '<style nonce="__CSP_NONCE_PLACEHOLDER__">' . "\n"
            . self::criticalCss() . "\n" . '</style>' . "\n";
        $deferredCss = Controls::deferredCss($cssUrls);
        $jsTag = null !== $jsUrl
            ? '<script src="' . FrontendLayout::e($jsUrl) . '" defer></script>' . "\n"
            : '';

        $desktopNav = Nav::navHtml($currentPath, 'mag-header__nav', 'mag-header__link');
        $mobileNav = Nav::navHtml($currentPath, 'mag-mobile-nav', 'mag-mobile-link');
        $footer = Nav::footerHtml($siteName, $description, $year);
        $themeButton = Controls::themeButton();
        $userLink = Controls::userLink();

        $magInteractionScript = InlineJs::file('Themes/magazine/assets/scripts/interaction.js');
        $magThemeInitScript = InlineJs::file('Themes/magazine/assets/scripts/theme-init.js');

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
            <script nonce="__CSP_NONCE_PLACEHOLDER__">{$magThemeInitScript}</script>
        </head>
        <body class="mag-page">
            <header class="mag-header">
                <div class="mag-container">
                    <div class="mag-header__inner">
                        <div class="mag-header__left">
                            <a class="mag-header__logo" href="/">
                                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                                    <line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="14" y2="11"/>
                                </svg>
                                <span>{$siteName}</span>
                            </a>
                        </div>
                        {$desktopNav}
                        <div class="mag-header__right navbar__actions">
                            <a class="mag-search-btn" href="/blog" aria-label="Search articles" title="Search articles">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                </svg>
                            </a>
                            {$themeButton}
                            {$userLink}
                            {$localeSwitcher}
                            <button type="button" class="mag-mobile-btn" id="mag-mobile-btn" aria-label="Toggle menu" aria-expanded="false" aria-controls="mag-mobile-menu">
                                <span></span><span></span><span></span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="mag-mobile-menu" id="mag-mobile-menu">
                    <div class="mag-container">
                        <nav class="mag-mobile-nav">
                            {$mobileNav}
                        </nav>
                    </div>
                </div>
            </header>
            <main class="mag-main">
                <div class="mag-container">
                    {$content}
                </div>
            </main>
            {$footer}
            <button type="button" class="mag-backtotop" id="mag-backtotop" aria-label="Back to top" title="Back to top">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 15l-6-6-6 6"/>
                </svg>
            </button>
            <script nonce="__CSP_NONCE_PLACEHOLDER__">{$magInteractionScript}</script>
            {$jsTag}
        </body>
        </html>
        HTML;
    }

    /**
     * Magazine critical CSS — dev fallback parity: the starter critical
     * shell (st-locale / st-theme-toggle classes are reused by the magazine
     * header) plus the magazine-specific above-fold styles.
     */
    public static function criticalCss(): string
    {
        return FrontendLayout::criticalCss() . self::magThemeCss();
    }

    /**
     * Magazine-specific critical CSS — the nine section files, in the
     * exact pre-split concatenation order (byte-parity contract).
     */
    private static function magThemeCss(): string
    {
        return InlineCss::files([
            'Themes/magazine/assets/critical/header.css',
            'Themes/magazine/assets/critical/layout.css',
            'Themes/magazine/assets/critical/grid.css',
            'Themes/magazine/assets/critical/hero.css',
            'Themes/magazine/assets/critical/home.css',
            'Themes/magazine/assets/critical/article.css',
            'Themes/magazine/assets/critical/toc.css',
            'Themes/magazine/assets/critical/taxonomy.css',
            'Themes/magazine/assets/critical/footer.css',
        ]);
    }
}
