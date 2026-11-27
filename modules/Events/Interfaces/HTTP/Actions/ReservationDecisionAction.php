<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Exception\ReservationRefused;
use Modules\Events\Domain\Model\ReservationStatus;
use Modules\Events\Interfaces\HTTP\EventInput;
use Modules\PageCache\Application\Services\CacheInvalidator;

/**
 * POST /mark/events/{slug}/reservations/{id}/confirm and .../cancel.
 *
 * A repeated decision is a no-op: no second mail, no second audit row. A
 * cancelled row cannot be revived. Every changed decision invalidates only the
 * event pages.
 */
#[Route(path: '/mark/events/{slug}/reservations/{id}/confirm', method: 'POST', name: 'mark.events.reservations.confirm', handlerMethod: 'confirm')]
#[Route(path: '/mark/events/{slug}/reservations/{id}/cancel', method: 'POST', name: 'mark.events.reservations.cancel', handlerMethod: 'cancel')]
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
        $locale = EventInput::locale($request);
        $language = EventInput::language($locale);
        $slug = (string) ($request->routeParam('slug') ?? '');
        $id = (string) ($request->routeParam('id') ?? '');
        $event = EventsServices::events()->findBySlug($slug, $locale);
        $reservation = EventsServices::reservations()->findById($id);

        if (null === $event || null === $reservation || $reservation->eventId !== $event->id) {
            return Response::redirect($this->url('mark.events') . '?locale=' . $language);
        }

        try {
            $result = EventsServices::reservationsService()->decide($id, $target, $this->actor());
        } catch (ReservationRefused $refused) {
            return Response::html('A cancelled reservation cannot be confirmed.', 409);
        }

        if ($result['changed'] && null !== $result['reservation']) {
            $reservation = $result['reservation'];

            if (ReservationStatus::Confirmed === $target) {
                EventsServices::mailer()->confirmation($event, $reservation);
            } else {
                EventsServices::mailer()->cancellation($event, $reservation);
            }

            CacheInvalidator::invalidate('/events');
            AuditServices::log(
                ReservationStatus::Confirmed === $target ? 'reservation.confirm' : 'reservation.cancel',
                'reservation:' . $reservation->id,
                ($target === ReservationStatus::Confirmed ? 'Confirmed' : 'Cancelled')
                    . ' reservation for ' . $event->slug,
                $request->getClientIp(),
            );
        }

        return Response::redirect(
            $this->url('mark.events.reservations', ['slug' => $event->slug]) . '?locale=' . $language,
        );
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
