<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Domain\PageSurface;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ErrorPageRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Events\Application\EventsServices;
use Modules\Events\Application\Services\EventStructuredData;
use Modules\Events\Application\Services\EventsRenderer;
use Modules\Locales\Domain\Model\Locale;

/**
 * GET /events/{slug}: one published event, past or upcoming.
 *
 * A draft is the styled 404 the Pages module uses. A past event stays readable
 * as a record but never opens the reservation form.
 */
#[Route(path: '/events/{slug}', method: 'GET', name: 'events.view')]
final readonly class EventViewAction
{
    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $locale = self::locale($request);
        $event = EventsServices::events()->findPublished($slug, $locale);

        if (null === $event) {
            return ErrorPageRenderer::response(
                404,
                'Not Found',
                'The requested event could not be found.',
                $request->getPathInfo(),
            );
        }

        $mapper = ContentLocaleServices::mapper();
        $theme = (new ThemeResolver())->resolve($request);
        $prefix = ThemeKernel::for($theme)->prefix();
        $copy = EventsServices::copy($locale);
        $remaining = EventsServices::reservationsService()->remaining($event);
        $sent = null !== $request->query('reservation-sent');
        $errors = $request->getAttribute('events_errors');
        $old = $request->getAttribute('events_old');

        $content = EventsRenderer::detail(
            $event,
            $remaining,
            $copy,
            $prefix,
            $mapper->urlPrefix($locale) . '/events/' . rawurlencode($event->slug) . '/reservations',
            $sent,
            !$event->isUpcoming(),
            is_array($errors) ? array_values(array_filter($errors, 'is_string')) : [],
            is_array($old) ? array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $old) : [],
        );

        $links = [];

        foreach ($mapper->locales() as $code) {
            $links[$code] = $mapper->urlPrefix($code) . '/events/' . rawurlencode($event->slug);
        }

        $canonical = $request->absoluteUrl($mapper->urlPrefix($locale) . '/events/' . $event->slug);

        return Response::html(FrontendLayout::render(
            $event->title . ' | ' . FrontendLayout::brandSuffix(),
            $content,
            description: '' !== $event->summary ? $event->summary : null,
            currentPath: $request->getPathInfo(),
            localeLinks: $links,
            lang: $mapper->languageCode($locale),
            hreflangLinks: self::hreflang($request, $links),
            canonicalUrl: $canonical,
            ogType: 'article',
            structuredData: EventStructuredData::event($event, $canonical),
            surface: PageSurface::ARTICLE,
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
