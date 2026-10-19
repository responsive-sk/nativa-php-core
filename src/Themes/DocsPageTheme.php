<?php

declare(strict_types = 1);

namespace Themes;

/**
 * DocsPageTheme — the optional `/docs` surfaces a `ThemeModule` may compose.
 *
 * The docs routes live in the kernel (`DocsIndexAction`, `DocsPageAction`) and
 * used to render a generic list and article body in the theme's class PREFIX
 * alone — `{prefix}-section`, `{prefix}-section__title`, `{prefix}-article__body`.
 * A theme whose vocabulary has no `-section` block (boson's does not) therefore
 * served an unstyled document under a fully styled header, and the fix belongs
 * in the theme, not in Core: only the theme knows what its document surface is
 * called.
 *
 * Optional for the same reason `AuthPageTheme` is. A theme that implements this
 * contract composes the surfaces; the actions fall back to the generic markup
 * otherwise, so no installed theme changes behaviour by not answering.
 *
 * The actions keep everything a theme must not decide: the catalogue boundary
 * (`DocsCatalog`), the Markdown pipeline (`MarkdownRenderer` +
 * `MarkdownBlockContext`), the `lang`/`noindex` head flags and the
 * `FrontendLayout` wrapper. The theme receives a guide that has already been
 * resolved, rendered and escaped where escaping applies.
 */
interface DocsPageTheme
{
    /**
     * The guide list, as the theme renders it.
     *
     * @param list<array{key: string, title: string, description: string}> $entries
     */
    public function docsIndex(array $entries): string;

    /**
     * One guide: the heading, the optional source path and the rendered body.
     *
     * `$bodyHtml` is raw by contract — it is `MarkdownRenderer` output, the
     * same pipeline articles and pages read.
     */
    public function docsPage(string $key, string $title, string $bodyHtml): string;
}
