<?php

declare(strict_types = 1);

namespace Themes\magazine\Chrome;

use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * Controls — magazine shell controls: auth-aware user link, theme toggle
 * button and the deferred-CSS loader.
 *
 * Moved verbatim from the original Shell (theme-section-split).
 */
final class Controls
{
    /**
     * Sign-in / admin link — auth-aware without ever opening users.db: a
     * session cookie must exist AND carry the auth stamp; guests get /login,
     * signed-in readers get /mark. Session is only started when the cookie is
     * present, so anonymous page views stay zero session I/O.
     */
    public static function userLink(): string
    {
        $signedIn = self::sessionHasAuth();

        $href = $signedIn ? '/mark' : '/login';
        $label = $signedIn
            ? LocalesServices::t('mag.nav_admin', 'Admin')
            : LocalesServices::t('mag.nav_login', 'Sign in');
        $icon = $signedIn
            ? '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'
            : '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>';

        return '<a class="mag-login-btn" href="' . FrontendLayout::e($href) . '"'
            . ' aria-label="' . FrontendLayout::e($label) . '" title="' . FrontendLayout::e($label) . '">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg>'
            . '<span class="mag-login-btn__text">' . FrontendLayout::e($label) . '</span>'
            . '</a>';
    }

    /**
     * Does the (already-started or startable) session carry an auth stamp?
     * Zero users.db I/O: only the session cookie + session file are touched,
     * and the session is only started when the cookie is present, so
     * anonymous page views stay zero session I/O.
     */
    private static function sessionHasAuth(): bool
    {
        $sessionName = session_name();
        if (false === $sessionName || !isset($_COOKIE[$sessionName])) {
            return false;
        }

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        /** @var array{auth?: array{user_id: string}} $session */
        $session = $_SESSION;

        return isset($session['auth']['user_id']);
    }

    /**
     * Theme toggle button — reuses the starter data-theme-toggle contract
     * and its icon swap classes (.st-theme-toggle__icon--sun/moon), so the
     * shared inline toggle script drives it.
     */
    public static function themeButton(): string
    {
        return '<button type="button" class="mag-theme-btn" data-theme-toggle aria-label="Toggle theme" title="Toggle theme">'
            . '<span class="st-theme-toggle__icon st-theme-toggle__icon--sun" aria-hidden="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>'
            . '</span>'
            . '<span class="st-theme-toggle__icon st-theme-toggle__icon--moon" aria-hidden="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>'
            . '</span>'
            . '</button>';
    }

    /**
     * Deferred magazine CSS — media="print" links flipped to "all" by the
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
