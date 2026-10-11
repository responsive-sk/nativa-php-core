<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Markdown;

/**
 * MarkdownBlockContext — what a block needs to expand a placeholder.
 *
 * A block cannot guess the visitor's language or the theme's CSS prefix: both
 * belong to the request, and Core has no ambient request to read them from
 * (a service locator for the request would be exactly the magic this kit
 * refuses). So the renderer takes the context as an argument and every caller
 * that knows the request passes it.
 *
 * `cssPrefix` is the resolved theme prefix (`st`, `er`, `ms`, `mag` — NO
 * trailing dash; that is what `ThemeModule::prefix()` returns and what every
 * other prefixed class in the kit is built from) so a block's markup can be
 * theme-native without importing anything from `Themes\` — the only file
 * allowed to do that is `ThemeKernel`.
 */
final readonly class MarkdownBlockContext
{
    public function __construct(
        public string $locale,
        public string $cssPrefix = 'st',
    ) {
    }

    /**
     * Context for a caller that has no request at hand (CLI, tests) — the
     * default locale of the content tree, which is where the configured
     * default lives.
     */
    public static function default(): self
    {
        return new self(locale: '');
    }
}
