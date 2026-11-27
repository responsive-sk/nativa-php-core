<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Events\Application\EventsServices;
use Modules\Events\Interfaces\HTTP\EventInput;
use Modules\PageCache\Application\Services\CacheInvalidator;

/**
 * POST /mark/events/{slug}/delete: delete only an event with no reservations.
 *
 * A reservation is a promise to a guest. Removing its event would erase the
 * only record of that promise, so the refusal is part of the policy and not a
 * database cascade. Unpublish instead when the programme changes.
 */
#[Route(path: '/mark/events/{slug}/delete', method: 'POST', name: 'mark.events.delete')]
final readonly class EventsDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = EventInput::locale($request);
        $language = EventInput::language($locale);
        $slug = (string) ($request->routeParam('slug') ?? '');
        $event = EventsServices::events()->findBySlug($slug, $locale);

        if (null === $event) {
            return Response::redirect($this->url('mark.events') . '?locale=' . $language);
        }

        if (EventsServices::reservations()->countForEvent($event->id) > 0) {
            return Response::html(
                'Event has reservations',
                409,
            );
        }

        EventsServices::events()->delete($event->id);
        CacheInvalidator::invalidate('/events');
        AuditServices::log(
            'event.delete',
            'event:' . $event->id,
            'Deleted event ' . $event->slug,
            $request->getClientIp(),
        );

        return Response::redirect($this->url('mark.events') . '?locale=' . $language);
    }
}
