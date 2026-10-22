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
use Themes\DocsPageTheme;

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
        $module = ThemeKernel::for($theme);

        // A theme with its own document surface composes the list; the
        // generic `{prefix}-section` markup stays the floor for every theme
        // that has no vocabulary for it.
        return Response::html(FrontendLayout::render(
            'Documentation — Nativa',
            $module instanceof DocsPageTheme
                ? $module->docsIndex($this->catalog->entries())
                : $this->content($module->prefix()),
            description: 'The guides that ship with the repository, rendered by the site.',
            currentPath: $request->getPathInfo(),
            lang: DocsCatalog::LANGUAGE,
            noindex: true,
            theme: $theme,
        ));
    }

    /**
     * The guide list, in the active theme's own vocabulary.
     *
     * The list is bounded by measure (`data-docs-prose`, 68ch) the same way a
     * guide's body is: a card carries up to 180 characters of description, and
     * on a wide display an unbounded one runs the full viewport. The attribute is
     * the docs surface's own, not a theme prefix, so every theme renders the same
     * readable column without a rule in its stylesheet — see
     * `DocsPageAction::readerLayout()` for the rule set that travels with it.
     */
    private function content(string $prefix): string
    {
        $html = '<section class="' . $prefix . '-section" data-docs-prose>'
            . '<h1 class="' . $prefix . '-section__title">Documentation</h1>';

        $entries = $this->catalog->entries();

        if ([] === $entries) {
            return $html
                . '<p>No guides are published in this deployment.</p>'
                . '</section>';
        }

        // Grouped in the order the groups first appear, so the map's order still
        // decides the sequence: a guide is filed by its key's first segment and
        // the group label is that segment. `intro`, `core-philosophy` and the
        // module guides have no segment and therefore form the leading group —
        // the reading order the map was written in.
        $groups = [];

        foreach ($entries as $entry) {
            $at = strpos($entry['key'], '/');
            $group = false === $at ? '' : substr($entry['key'], 0, $at);
            $groups[$group][] = $entry;
        }

        $html .= '<div class="' . $prefix . '-article__body">';

        foreach ($groups as $group => $members) {
            if ('' !== $group) {
                $html .= '<h2>' . FrontendLayout::e($this->groupLabel($group)) . '</h2>';
            }

            $html .= '<ul>';

            foreach ($members as $entry) {
                $html .= '<li>'
                    . '<a href="/docs/' . FrontendLayout::e($entry['key']) . '">'
                    . FrontendLayout::e($entry['title']) . '</a>'
                    . ('' !== $entry['description'] ? ' — ' . FrontendLayout::e($entry['description']) : '')
                    . '</li>';
            }

            $html .= '</ul>';
        }

        return $html . '</div></section>';
    }

    /**
     * A group is a directory under `docs/`, so its label is the directory's own
     * name made readable — never a second list the kernel would have to keep in
     * sync with the tree. `kernel-status` reads as "Kernel status".
     */
    private function groupLabel(string $group): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $group));
    }
}
