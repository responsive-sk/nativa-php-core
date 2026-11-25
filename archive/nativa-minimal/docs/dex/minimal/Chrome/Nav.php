<?php

declare(strict_types = 1);

namespace Themes\minimal\Chrome;

use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Settings\Interfaces\HTTP\SettingsServices;

/**
 * Nav — minimal shell header nav (DB-driven) and the auth-aware
 * sign-in/admin link.
 *
 * Extracted verbatim from the pre-split Shell privates (visibility
 * promoted where the facade calls cross-class); the shell delegates to
 * these methods (C-pattern: explicit calls, no magic).
 */
final class Nav
{
    /**
     * Header nav — DB-driven menu (SettingsServices::menu), same seeded
     * items the starter header renders (Home / Articles / Admin), styled
     * with the minimal link contract. The checkbox mobile menu is CSS-only.
     */
    public static function navHtml(string $currentPath): string
    {
        $links = '';

        foreach (SettingsServices::menu()->menu('header', $currentPath) as $item) {
            $links .= '<a class="minimal-header__link' . ($item->active() ? ' active' : '') . '" href="'
                . FrontendLayout::e($item->href()) . '">'
                . FrontendLayout::e($item->label()) . '</a>';
        }

        return '<nav class="minimal-header__nav" aria-label="Main navigation">' . $links . '</nav>';
    }

    /**
     * Sign-in / admin link — auth-aware without ever opening users.db: a
     * session cookie must exist AND carry the auth stamp; guests get /login,
     * signed-in readers get /mark (same pattern as the magazine shell).
     */
    public static function userLink(): string
    {
        $signedIn = self::sessionHasAuth();

        $href = $signedIn ? '/mark' : '/login';
        $label = $signedIn
            ? LocalesServices::t('min.nav_admin', 'Admin')
            : LocalesServices::t('min.nav_login', 'Sign in');
        $icon = $signedIn
            ? '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'
            : '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>';

        return '<a class="minimal-login-btn" href="' . FrontendLayout::e($href) . '"'
            . ' aria-label="' . FrontendLayout::e($label) . '" title="' . FrontendLayout::e($label) . '">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg>'
            . '<span class="minimal-login-btn__text">' . FrontendLayout::e($label) . '</span>'
            . '</a>';
    }

    /**
     * Does the (already-started or startable) session carry an auth stamp?
     * Zero users.db I/O — only the session cookie + session file are touched,
     * and the session is only started when the cookie is present.
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
}
