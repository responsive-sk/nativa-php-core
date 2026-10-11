<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * LlmsTxtAction — serves /llms.txt for LLM-friendly site overview.
 *
 * Follows the llms.txt v2 spec (https://github.com/answerdotai/llms-txt):
 * a Markdown file with an H1 header, brief description, and links to
 * key content sections in both locales.
 */
#[Route(path: '/llms.txt', method: 'GET', name: 'llms-txt')]
final class LlmsTxtAction
{
    /**
     * llms.txt body — Markdown link placeholders like (/sk/blog), made
     * absolute against the request host before serving.
     */
    private const string TEMPLATE = <<<'TXT'
        # Nativa Minimal — PHP 8.5 Starter Kit

        A minimal PHP 8.5 starter kit with zero production dependencies, SQLite per-module databases, admin panel, Markdown blog with full-text search, and two frontend themes.

        ## Sections

        - [Homepage](/): Bilingual landing page (Slovak and English)
        - [Blog — SK](/sk/blog): Slovak technical articles on PHP, performance, and web development
        - [Blog — EN](/en/blog): English technical articles on PHP, performance, and web development
        - [Blog RSS — SK](/sk/blog/feed.xml): RSS 2.0 feed (Slovak)
        - [Blog RSS — EN](/en/blog/feed.xml): RSS 2.0 feed (English)
        - [Blog Atom — SK](/sk/blog/feed.atom): Atom 1.0 feed (Slovak)
        - [Blog Atom — EN](/en/blog/feed.atom): Atom 1.0 feed (English)
        - [Authors](/authors): Blog author profiles
        - [Sitemap](/sitemap.xml): XML sitemap for search engines

        ## About

        Nativa Minimal is a learning-oriented starter kit demonstrating clean PHP 8.5 architecture with vertical-slice modules, middleware pipeline, CSRF protection, session-based auth with RBAC, and a Markdown blog with full-text search powered by SQLite FTS5.

        ## Details

        - Zero Composer runtime dependencies — self-hosted PSR-4 autoloader
        - SQLite per-module databases (users, config, locales, audit, media)
        - Two themes: starter (public, default) and moonshine (admin chrome behind /mark)
        - Admin panel at /mark (requires admin role)
        - Strict CSP with per-request nonces
        - Vanilla TypeScript + Vite frontend
        TXT;

    /**
     * @var list<string> paths linked from TEMPLATE (Markdown placeholder form)
     */
    private const array LINKED_PATHS = [
        '/',
        '/sk/blog',
        '/en/blog',
        '/sk/blog/feed.xml',
        '/en/blog/feed.xml',
        '/sk/blog/feed.atom',
        '/en/blog/feed.atom',
        '/authors',
        '/sitemap.xml',
    ];

    public function handle(Request $request): Response
    {
        $content = $this->absolutizeLinks($request->absoluteUrl(''), self::TEMPLATE);

        $response = new Response($content);
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');

        return $response;
    }

    /**
     * Replace the Markdown link placeholders in TEMPLATE with absolute URLs.
     */
    private function absolutizeLinks(string $host, string $template): string
    {
        $search = array_map(
            static fn (string $path): string => '(' . $path . ')',
            self::LINKED_PATHS,
        );
        $replace = array_map(
            static fn (string $path): string => '(' . $host . $path . ')',
            self::LINKED_PATHS,
        );

        return str_replace($search, $replace, $template);
    }
}
