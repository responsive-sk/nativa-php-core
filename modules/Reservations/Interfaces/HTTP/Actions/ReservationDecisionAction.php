<?php

declare(strict_types = 1);

namespace Modules\Reservations\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Reservations\Application\ReservationsServices;
use Modules\Reservations\Domain\Model\ReservationStatus;

/**
 * POST /mark/reservations/{id}/confirm and .../cancel.
 *
 * A repeated decision is a no-op, and a cancelled order cannot be confirmed
 * again. Only a changed decision writes an audit row, so a double click leaves
 * one event rather than two.
 */
#[Route(
    path: '/mark/reservations/{id}/confirm',
    method: 'POST',
    name: 'mark.reservations.confirm',
    handlerMethod: 'confirm',
)]
#[Route(
    path: '/mark/reservations/{id}/cancel',
    method: 'POST',
    name: 'mark.reservations.cancel',
    handlerMethod: 'cancel',
)]
final readonly class ReservationDecisionAction
{
    use ResolvesRouteUrls;

    public function confirm(Request $request): Response
    {
        return $this->decide($request, ReservationStatus::Confirmed);
    }

    public function cancel(Request $request): Response
    {
        return $this->decide($request, ReservationStatus::Cancelled);
    }

    private function decide(Request $request, ReservationStatus $target): Response
    {
        $id = (string) ($request->routeParam('id') ?? '');
        $result = ReservationsServices::service()->decide($id, $target, $this->actor());

        if ($result['changed'] && null !== $result['reservation']) {
            $reservation = $result['reservation'];

            AuditServices::log(
                ReservationStatus::Confirmed === $target ? 'reservation.confirm' : 'reservation.cancel',
                'reservation:' . $reservation->id,
                ($target === ReservationStatus::Confirmed ? 'Confirmed' : 'Cancelled')
                    . ' reservation for ' . $reservation->formSlug,
                $request->getClientIp(),
            );
        }

        return Response::redirect($this->url('mark.reservations'));
    }

    private function actor(): string
    {
        try {
            $user = AuditServices::auth()->currentUser();

            return null === $user ? 'system' : $user->id();
        } catch (\Throwable) {
            return 'system';
        }
    }
}
