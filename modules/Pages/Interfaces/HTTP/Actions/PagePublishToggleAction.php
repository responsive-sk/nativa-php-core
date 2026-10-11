<?php

declare(strict_types = 1);

namespace Modules\Pages\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\CardboardKit\Application\Services\AdminLocale;
use Modules\CardboardKit\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Pages\Interfaces\HTTP\PagesTableRenderer;

/**
 * PagePublishToggleAction — POST /mark/pages/{slug}/publish.
 *
 * Flips the published flag of a single page (body `to` = 1|0, default:
 * invert the current state). HTMX requests get the updated table row back;
 * native form submits are redirected to the index with a toast — a
 * POST-only URL is never rendered as a page. Mirrors the articles
 * publish-toggle contract the table switch posts to.
 */
#[Route(path: '/mark/pages/{slug}/publish', method: 'POST', name: 'pages.publish-toggle')]
final class PagePublishToggleAction
{
    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $localeRaw = is_string($request->query('locale')) ? $request->query('locale') : 'sk';
        $localeParam = AdminLocale::raw($localeRaw);
        $locale = AdminLocale::canonical($localeRaw);

        $crud = new PageCrudService();
        $toRaw = $request->body('to');
        $to = is_string($toRaw) && in_array($toRaw, ['0', '1'], true)
            ? '1' === $toRaw
            : !$crud->isPublished($slug, $locale);

        $changed = $crud->setPublished($slug, $to, $locale);

        if ($changed) {
            CacheInvalidator::flush();
            AuditServices::log(
                $to ? 'page.publish' : 'page.unpublish',
                'page',
                ($to ? 'Published: ' : 'Unpublished: ') . $slug,
                $request->getClientIp(),
            );
        }

        if (AdminLayout::isHtmx($request)) {
            $pages = $crud->list($locale);
            foreach ($pages as $page) {
                if ($page['slug'] === $slug) {
                    return Response::html(PagesTableRenderer::row($page, $localeParam));
                }
            }

            return Response::html('');
        }

        $toast = $changed
            ? ($to ? 'Page+published' : 'Page+unpublished')
            : 'Page+not+found';

        return Response::redirect('/mark/pages?locale=' . $localeParam . '&toast=' . $toast);
    }
}
