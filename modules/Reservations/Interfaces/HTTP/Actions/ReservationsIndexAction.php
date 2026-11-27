<?php

declare(strict_types = 1);

namespace Modules\Reservations\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Reservations\Application\ReservationsServices;
use Modules\Reservations\Domain\Model\ReservationStatus;
use Modules\Reservations\Interfaces\HTTP\ReservationsPanelHtml;

/**
 * GET /mark/reservations: the organizer's order inbox.
 *
 * Newest first. The optional `status` query narrows the list through the same
 * enum the rest of the module uses; an unknown value is ignored rather than
 * turned into an empty page.
 */
#[Route(path: '/mark/reservations', method: 'GET', name: 'mark.reservations')]
final readonly class ReservationsIndexAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $status = ReservationStatus::fromLabel($request->queryString('status', ''));
        $reservations = ReservationsServices::service()->all($status);

        return Response::html(CardboardLayout::render(
            'Reservations',
            ReservationsPanelHtml::index($reservations, $this->urlBuilder()),
            'reservations',
            router: $this->router(),
        ));
    }
}
