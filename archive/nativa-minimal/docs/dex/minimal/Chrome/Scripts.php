<?php

declare(strict_types = 1);

namespace Themes\minimal\Chrome;

use Modules\Core\Application\Services\View\InlineJs;

/**
 * Inline chrome scripts — the CSP-nonce'd IIFEs the shell ships in the
 * document. Kept as named string methods so the shell heredoc stays small
 * (C-pattern chunky rule) and the ES2015 constraints stay visible:
 * no `?.`/`??`, no arrow functions, `var` + index loops.
 *
 * The heredoc bodies carry the FULL output indentation (the shell embeds
 * these at the original heredoc position), so the served HTML is
 * byte-identical to the pre-split shell. The minimal theme renders the
 * theme toggle ONCE (header actions cluster), so the singular
 * querySelector wiring is correct here.
 */
final class Scripts
{
    /**
     * Head script — syncs data-theme from localStorage / OS preference
     * before first paint (no FOUC). Runs synchronously in <head>.
     */
    public static function themeInitTag(): string
    {
        $themeInitScript = InlineJs::file('Themes/minimal/assets/scripts/theme-init.js');

        return <<<HTML
            <script nonce="__CSP_NONCE_PLACEHOLDER__">{$themeInitScript}</script>
        HTML;
    }

    /**
     * Body script — theme toggle wiring (single instance).
     */
    public static function interactionTag(): string
    {
        $interactionScript = InlineJs::file('Themes/minimal/assets/scripts/interaction.js');

        return <<<HTML
            <script nonce="__CSP_NONCE_PLACEHOLDER__">{$interactionScript}</script>
        HTML;
    }
}
