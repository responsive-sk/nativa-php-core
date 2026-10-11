<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Application\Services\DocsCatalog;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;

/**
 * DocsIndexAction — GET /docs: the published guides.
 *
 * The list is the catalogue, so a document nobody published cannot appear here
 * even though its file sits in the same directory: the index is not a directory
 * listing, it is the publication boundary rendered.
 *
 * Deliberately `noindex` and without a canonical URL: these are engineering
 * guides, and the 182 links into them come from blog articles that already
 * rank. Keeping the surface out of the index is also what keeps the canonical
 * sitemap requirement ("every public INDEXABLE URL") literally true, so this
 * route needs no change to a canonical requirement.
 */
#[Route(path: '/docs', method: 'GET', name: 'docs-index')]
final readonly class DocsIndexAction
{
    private DocsCatalog $catalog;

    /** @param DocsCatalog|null $catalog Test seam for a deployment without docs/. */
    public function __construct(?DocsCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? new DocsCatalog();
    }

    public function handle(Request $request): Response
    {
        $theme = (new ThemeResolver())->resolve($request);

        return Response::html(FrontendLayout::render(
            'Documentation — Nativa',
            $this->content(ThemeKernel::for($theme)->prefix()),
            description: 'The guides that ship with the repository, rendered by the site.',
            currentPath: $request->getPathInfo(),
            lang: DocsCatalog::LANGUAGE,
            noindex: true,
            theme: $theme,
        ));
    }

    /** The guide list, in the active theme's own vocabulary. */
    private function content(string $prefix): string
    {
        $html = '<section class="' . $prefix . '-section">'
            . '<h1 class="' . $prefix . '-section__title">Documentation</h1>';

        $entries = $this->catalog->entries();

        if ([] === $entries) {
            return $html
                . '<p>No guides are published in this deployment.</p>'
                . '</section>';
        }

        $html .= '<div class="' . $prefix . '-article__body"><ul>';

        foreach ($entries as $entry) {
            $html .= '<li>'
                . '<a href="/docs/' . FrontendLayout::e($entry['key']) . '">'
                . FrontendLayout::e($entry['title']) . '</a>'
                . ('' !== $entry['description'] ? ' — ' . FrontendLayout::e($entry['description']) : '')
                . '</li>';
        }

        return $html . '</ul></div></section>';
    }
}
