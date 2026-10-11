<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Infrastructure\Http\Response;

/**
 * ErrorPageRenderer — styled HTTP error pages (404/405/500).
 *
 * Renders the error through the public FrontendLayout shell (header,
 * navigation, footer, theme, CSP nonce) with an .st-hero content block.
 * When the layout itself cannot render — typically a 500 caused by a
 * database failure, since the shell reads menu/settings from SQLite — the
 * renderer falls back to a dependency-free bare HTML page with the same
 * status code (the error page must never fail to render).
 */
final class ErrorPageRenderer
{
    /**
     * Styled error Response — the single 404/405/500 entry point used by the
     * Kernel and by actions (Kernel::renderError and the actions' notFound()
     * helpers all delegate here, so every error page in the app is identical).
     */
    public static function response(int $status, string $title, string $detail): Response
    {
        return Response::html((new self())->render($status, $title, $detail), $status);
    }

    public function render(int $status, string $title, string $detail): string
    {
        $content = '<section class="st-hero">'
            . '<h1 class="st-hero__title">' . FrontendLayout::e($status . ' ' . $title) . '</h1>'
            . '<p class="st-hero__subtitle">' . FrontendLayout::e($detail) . '</p>'
            . '<a class="st-hero__link" href="/">Back to homepage</a>'
            . '</section>';

        try {
            return FrontendLayout::render(
                $status . ' ' . $title . ' ... Nativa Starter',
                $content,
                currentPath: '/',
                noindex: true,
            );
        } catch (\Throwable) {
            return $this->bare($status, $title, $detail);
        }
    }

    /**
     * Dependency-free fallback — inline styles only, no layout shell, no
     * database access. Keeps the error page renderable when the shell
     * itself broke (e.g. a 500 from a SQLite failure).
     */
    private function bare(int $status, string $title, string $detail): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDetail = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $status . ' ' . $safeTitle . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem">'
            . '<h1 style="font-size:1.5rem">' . $status . ' ' . $safeTitle . '</h1>'
            . '<p style="color:#555;white-space:pre-wrap">' . $safeDetail . '</p>'
            . '</body></html>';
    }
}
