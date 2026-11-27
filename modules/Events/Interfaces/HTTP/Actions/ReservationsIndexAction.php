<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Model\ReservationStatus;
use Modules\Events\Interfaces\HTTP\EventInput;
use Modules\Events\Interfaces\HTTP\EventsPanelHtml;

/**
 * GET /mark/events/{slug}/reservations: one event's organizer inbox.
 */
#[Route(path: '/mark/events/{slug}/reservations', method: 'GET', name: 'mark.events.reservations')]
final readonly class ReservationsIndexAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = EventInput::locale($request);
        $language = EventInput::language($locale);
        $slug = (string) ($request->routeParam('slug') ?? '');
        $event = EventsServices::events()->findBySlug($slug, $locale);

        if (null === $event) {
            return Response::html(
                CardboardLayout::render('Event not found', CardboardLayout::emptyState(
                    'Event not found',
                    'The event was removed or belongs to another locale.',
                    $this->url('mark.events') . '?locale=' . $language,
                    'Back to events',
                ), 'events', router: $this->router()),
                404,
            );
        }

        $reservations = EventsServices::reservationsService()->forEvent($event->id);
        $counts = [
            ReservationStatus::Pending->value   => 0,
            ReservationStatus::Confirmed->value => 0,
            ReservationStatus::Cancelled->value => 0,
        ];

        foreach ($reservations as $reservation) {
            $counts[$reservation->status->value] = ($counts[$reservation->status->value] ?? 0) + 1;
        }

        return Response::html(CardboardLayout::render(
            'Reservations',
            EventsPanelHtml::reservations($event, $reservations, $counts, $this->urlBuilder()),
            'events',
            router: $this->router(),
        ));
    }
}
