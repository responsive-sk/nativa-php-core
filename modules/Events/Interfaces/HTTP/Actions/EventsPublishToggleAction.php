<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Interfaces\HTTP\EventInput;
use Modules\PageCache\Application\Services\CacheInvalidator;

/**
 * POST /mark/events/{slug}/publish: publish or unpublish one event.
 */
#[Route(path: '/mark/events/{slug}/publish', method: 'POST', name: 'mark.events.publish-toggle')]
final readonly class EventsPublishToggleAction
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

        $updated = new Event(
            $event->id,
            $event->slug,
            $event->locale,
            $event->title,
            $event->summary,
            $event->body,
            $event->startsAt,
            $event->endsAt,
            $event->venue,
            $event->address,
            $event->capacity,
            $event->reservationsEnabled,
            !$event->published,
            $event->createdAt,
            new \DateTimeImmutable(),
        );

        EventsServices::events()->update($updated);
        CacheInvalidator::invalidate('/events');
        AuditServices::log(
            $updated->published ? 'event.publish' : 'event.unpublish',
            'event:' . $updated->id,
            ($updated->published ? 'Published ' : 'Unpublished ') . $updated->slug,
            $request->getClientIp(),
        );

        return Response::redirect($this->url('mark.events') . '?locale=' . $language);
    }
}
