<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Events\Application\EventsServices;
use Modules\Events\Interfaces\HTTP\EventInput;
use Modules\Events\Interfaces\HTTP\EventsPanelHtml;

/**
 * GET /mark/events: the event lifecycle's index.
 */
#[Route(path: '/mark/events', method: 'GET', name: 'mark.events')]
final readonly class EventsPanelIndexAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = EventInput::locale($request);
        $language = EventInput::language($locale);
        $events = EventsServices::events()->listForLocale($locale);

        $links = '';

        foreach (ContentLocaleServices::mapper()->locales() as $code) {
            $lang = ContentLocaleServices::mapper()->languageCode($code);
            $links .= '<a class="m-btn m-btn-ghost" href="' . CardboardLayout::e($this->url('mark.events') . '?locale=' . $lang) . '">'
                . ($code === $locale ? '[' . CardboardLayout::e($lang) . ']' : CardboardLayout::e($lang)) . '</a> ';
        }

        $content = EventsPanelHtml::index(
            $events,
            $language,
            $this->urlBuilder(),
            EventsServices::reservations()->countPending(),
        ) . '<div class="m-filter-bar">' . $links . '</div>';

        return Response::html(CardboardLayout::render('Events', $content, 'events', router: $this->router()));
    }
}
