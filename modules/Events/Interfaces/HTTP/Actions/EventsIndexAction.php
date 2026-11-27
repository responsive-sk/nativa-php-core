<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Domain\PageSurface;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Events\Application\EventsServices;
use Modules\Events\Application\Services\EventStructuredData;
use Modules\Events\Application\Services\EventsRenderer;
use Modules\Locales\Domain\Model\Locale;

/**
 * GET /events: the public upcoming programme.
 *
 * The literal path wins over the Pages `/{slug}` catch-all because the router
 * tries literal routes before parameterized ones.
 */
#[Route(path: '/events', method: 'GET', name: 'events.index')]
final readonly class EventsIndexAction
{
    public function handle(Request $request): Response
    {
        $locale = self::locale($request);
        $mapper = ContentLocaleServices::mapper();
        $events = EventsServices::events()->upcomingPublished($locale);
        $theme = (new ThemeResolver())->resolve($request);
        $prefix = ThemeKernel::for($theme)->prefix();
        $copy = EventsServices::copy($locale);
        $canonical = $request->absoluteUrl($mapper->urlPrefix($locale) . '/events');

        $links = [];

        foreach ($mapper->locales() as $code) {
            $links[$code] = $mapper->urlPrefix($code) . '/events';
        }

        $content = EventsRenderer::listing(
            $events,
            $copy,
            $prefix,
            static fn (string $slug): string => $mapper->urlPrefix($locale) . '/events/' . $slug,
        );

        return Response::html(FrontendLayout::render(
            ($copy['events.title'] ?? 'Events') . ' | ' . FrontendLayout::brandSuffix(),
            $content,
            description: $copy['events.empty'] ?? null,
            currentPath: $request->getPathInfo(),
            localeLinks: $links,
            lang: $mapper->languageCode($locale),
            hreflangLinks: self::hreflang($request, $links),
            canonicalUrl: $canonical,
            ogType: 'website',
            structuredData: EventStructuredData::collection(
                $copy['events.title'] ?? 'Events',
                $canonical,
                $request->absoluteUrl('/'),
            ),
            surface: PageSurface::LISTING,
            theme: $theme,
        ));
    }

    /**
     * @param array<string, string> $links
     *
     * @return array<string, string>
     */
    private static function hreflang(Request $request, array $links): array
    {
        $base = $request->absoluteUrl('');
        $hreflang = [];

        foreach ($links as $code => $path) {
            $hreflang[Locale::fromString($code)->language()] = $base . $path;
        }

        $default = ContentLocaleServices::mapper()->defaultLocale();

        if (isset($links[$default])) {
            $hreflang['x-default'] = $base . $links[$default];
        }

        return $hreflang;
    }

    private static function locale(Request $request): string
    {
        $locale = $request->getAttribute('locale');

        return ContentLocaleServices::mapper()->code(is_string($locale) ? $locale : '');
    }
}
