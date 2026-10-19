<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * AuthorAvatar — renders the author's avatar + linked name on article lines
 * and in the author profile header.
 *
 * Avatar files are self-hosted under storage/uploads (CSP img-src 'self');
 * when a user has no avatar or the stored file is gone, a deterministic
 * initial-letter placeholder (inline SVG data: URI — CSP-safe, zero JS) is
 * rendered instead of a broken image. The email is a lookup key only and is
 * never part of the markup.
 */
final readonly class AuthorAvatar
{
    public function __construct(private LocalStorageProvider $storage)
    {
    }

    /**
     * Author line: linked avatar + name when the profile resolves, otherwise
     * the plain escaped fallback text (phase-1 behaviour).
     *
     * The avatar and the name are separated by a middle dot — the same ` · `
     * separator the meta line uses between date, category and views, so the
     * author reads as one more entry in that list instead of one glued token.
     * The separator lives in the MARKUP on purpose: `.st-author-avatar` is
     * kernel-owned markup emitted by every theme, and only four themes ship a
     * `margin-right` for it (starter/moonshine/magazine/minimal, plus the
     * critical copy) while erik pins 0 and jednotka/basic style it not at all —
     * spacing the dot with CSS would render `avatar␣␣␣ · Name` on four themes
     * and `avatar · Name` on the other two. One text node renders identically
     * everywhere, so no theme has to remember the rule.
     *
     * @param array{id: string, name: string, avatar: ?string}|null $profile
     */
    public function authorLine(?array $profile, string $fallback, int $size = 20): string
    {
        if (null === $profile || '' === $profile['id'] || '' === $profile['name']) {
            return FrontendLayout::e($fallback);
        }

        return '<a class="st-author-link" href="/authors/' . FrontendLayout::e(MarkdownRenderer::slugify($profile['name'])) . '">'
            . $this->img($profile['avatar'], $profile['name'], $size)
            . ' · '
            . FrontendLayout::e($profile['name'])
            . '</a>';
    }

    /**
     * Public URL for a stored avatar path, or null when there is no file.
     *
     * The database stores a path under storage/uploads (`2026/10/09/<hex>.jpg`),
     * not a URL. A caller that renders it directly gets a browser-relative
     * request — on `/authors/<slug>` that is `/authors/2026/10/09/…` and a 404 —
     * so every surface must pass the path through here.
     */
    public function url(?string $avatar): ?string
    {
        if (null === $avatar || '' === $avatar || !$this->storage->exists($avatar)) {
            return null;
        }

        return $this->storage->url($avatar);
    }

    /**
     * Avatar <img> — the stored file when it exists, else the placeholder.
     * alt="" (decorative: the name is already adjacent text in the link).
     */
    public function img(?string $avatar, string $name, int $size = 20): string
    {
        $src = $this->url($avatar) ?? $this->placeholderDataUri($name);

        return '<img class="st-author-avatar" src="' . FrontendLayout::e($src) . '" alt=""'
            . ' width="' . $size . '" height="' . $size . '" loading="lazy">';
    }

    /**
     * Deterministic initial-letter placeholder — inline SVG data: URI
     * (self-hosted, CSP img-src 'data:').
     */
    public function placeholderDataUri(string $name): string
    {
        $initial = strtoupper(mb_substr(trim($name), 0, 1));
        if ('' === $initial) {
            $initial = '?';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
            . '<circle cx="10" cy="10" r="10" fill="#3b82f6"/>'
            . '<text x="10" y="13.5" font-size="10" text-anchor="middle" fill="#ffffff" font-family="sans-serif">'
            . $initial . '</text></svg>';

        return 'data:image/svg+xml,' . rawurlencode($svg);
    }
}
