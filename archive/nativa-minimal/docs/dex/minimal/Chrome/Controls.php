<?php

declare(strict_types = 1);

namespace Themes\minimal\Chrome;

use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * Controls — minimal shell controls: theme toggle button and the
 * deferred-CSS loader.
 *
 * Extracted verbatim from the pre-split Shell privates (visibility
 * promoted where the facade calls cross-class); the shell delegates to
 * these methods (C-pattern: explicit calls, no magic).
 */
final class Controls
{
    /**
     * Theme toggle button — reuses the starter data-theme-toggle contract
     * and its icon swap classes (.st-theme-toggle__icon--sun/moon), so the
     * shared inline toggle script drives it.
     */
    public static function themeButton(): string
    {
        return '<button type="button" class="st-theme-toggle" data-theme-toggle aria-label="Toggle theme" title="Toggle theme">'
            . '<span class="st-theme-toggle__icon st-theme-toggle__icon--sun" aria-hidden="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>'
            . '</span>'
            . '<span class="st-theme-toggle__icon st-theme-toggle__icon--moon" aria-hidden="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>'
            . '</span>'
            . '</button>';
    }

    /**
     * Deferred minimal CSS — media="print" links flipped to "all" by the
     * inline switcher (CSP nonce) so the below-fold styles never block the
     * first paint. The no-JS fallback link is wrapped in <noscript> so it
     * only applies when scripting is disabled — never a render-blocking
     * request on the critical path. Empty when the build is missing
     * (critical CSS only).
     *
     * @param list<string> $cssUrls
     */
    public static function deferredCss(array $cssUrls): string
    {
        if ([] === $cssUrls) {
            return '';
        }

        $links = '';
        $noscript = '';

        foreach ($cssUrls as $css) {
            $links .= '<link rel="stylesheet" href="' . FrontendLayout::e($css) . '" media="print">' . "\n";
            $noscript .= '<link rel="stylesheet" href="' . FrontendLayout::e($css) . '">' . "\n";
        }

        $switcher = '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
            . '(function(){var l=document.querySelectorAll(\'link[media="print"][rel="stylesheet"]\');'
            . 'for(var i=0;i<l.length;i++){l[i].addEventListener(\'load\',function(){this.media="all";});'
            . 'if(l[i].sheet){l[i].media="all";}}})();'
            . '</script>' . "\n";

        return $links . '<noscript>' . "\n" . $noscript . '</noscript>' . "\n" . $switcher;
    }
}
