<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Domain\Validation\EventValidator;
use Modules\Events\Interfaces\HTTP\EventInput;
use Modules\Events\Interfaces\HTTP\EventsAdminHtml;
use Modules\PageCache\Application\Services\CacheInvalidator;

/**
 * GET|POST /mark/events/{slug}/edit: edit one event.
 *
 * A capacity change that would strand existing holds is refused. The operator
 * cancels reservations first, or raises the capacity; the editor never silently
 * accepts a number the existing rows already exceed.
 */
#[Route(path: '/mark/events/{slug}/edit', method: 'GET|POST', name: 'mark.events.edit')]
final readonly class EventsEditAction
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

        $data = $request->isMethod('POST') ? EventInput::fromRequest($request) : EventInput::fromEvent($event);

        if (!$request->isMethod('POST')) {
            return Response::html($this->page($event, $data, $language));
        }

        $normalized = EventValidator::normalize($data);
        $errors = EventValidator::errors($normalized);

        if ([] === $errors && EventsServices::events()->exists($normalized['slug'], $locale, $event->id)) {
            $errors[] = 'That slug is already used by another event in this locale.';
        }

        $held = EventsServices::reservationsService()->activeSeats($event->id);

        if ([] === $errors && $normalized['capacity'] < $held) {
            $errors[] = 'Capacity cannot be below the ' . $held . ' seats already held.';
        }

        if ([] !== $errors) {
            return Response::html($this->page($event, $data, $language, $errors), 400);
        }

        $updated = new Event(
            $event->id,
            $normalized['slug'],
            $locale,
            $normalized['title'],
            $normalized['summary'],
            $normalized['body'],
            EventValidator::date($normalized['starts_at']) ?? $event->startsAt,
            EventValidator::date($normalized['ends_at']),
            $normalized['venue'],
            $normalized['address'],
            $normalized['capacity'],
            $normalized['reservations_enabled'],
            $normalized['published'],
            $event->createdAt,
            new \DateTimeImmutable(),
        );

        EventsServices::events()->update($updated);
        CacheInvalidator::invalidate('/events');
        AuditServices::log(
            'event.update',
            'event:' . $updated->id,
            'Updated event ' . $updated->slug . ' (' . $locale . ')',
            $request->getClientIp(),
        );

        return Response::redirect($this->url('mark.events') . '?locale=' . $language);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private function page(Event $event, array $data, string $language, array $errors = []): string
    {
        return CardboardLayout::render(
            'Edit event',
            EventsAdminHtml::editor($event, $data, $language, $this->urlBuilder(), $errors),
            'events',
            router: $this->router(),
        );
    }
}
