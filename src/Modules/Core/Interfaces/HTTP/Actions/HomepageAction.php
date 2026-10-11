<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;

/**
 * HomepageAction — the kernel's own home surface.
 *
 * The showcase home (hero + feature sections + latest articles, copy through
 * the Locales translation system) is the app's, not the kernel's: its ledger
 * answer is `M` — it moves to the module/slice that owns the copy it renders
 * (`Modules\Site` in `../nativa-minimal`, task 1.1). What stays here is the
 * smallest surface the kernel can answer honestly with nothing installed: the
 * site name through the `ShellContextProvider` port, rendered by the kernel
 * theme from `ShellContext` + `PageContext` alone.
 *
 * No `Blog`, no `Locales`, no `Settings` import. The locale passed to the
 * page context is the mapper's default (itself the provider's answer with a
 * kernel fallback) — the kernel never hardcodes which locale owns `/`.
 */
#[Route(path: '/', method: 'GET', name: 'home')]
final class HomepageAction
{
    public function handle(Request $request): Response
    {
        $theme = (new ThemeResolver())->resolve($request);
        $locale = ContentServices::mapper()->defaultLocale();
        $siteName = ShellContextProviderRegistry::siteName() ?? 'Nativa PHP Core';

        $content = ThemeKernel::for($theme)->homepage(
            PageContext::forHomepage(
                $request,
                $theme,
                $locale,
                copy: ['title' => $siteName],
            ),
        );

        $homeUrl = $request->absoluteUrl('/');

        return Response::html(FrontendLayout::render(
            $siteName,
            $content,
            currentPath: '/',
            canonicalUrl: $homeUrl,
            theme: $theme,
        ));
    }
}
