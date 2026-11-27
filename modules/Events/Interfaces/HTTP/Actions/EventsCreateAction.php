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
use Modules\Events\Interfaces\HTTP\EventsPanelHtml;
use Modules\PageCache\Application\Services\CacheInvalidator;

/**
 * GET|POST /mark/events/create: one event editor.
 */
#[Route(path: '/mark/events/create', method: 'GET|POST', name: 'mark.events.create')]
final readonly class EventsCreateAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $locale = EventInput::locale($request);
        $language = EventInput::language($locale);
        $data = $request->isMethod('POST')
            ? EventInput::fromRequest($request)
            : [
                'slug'                 => '',
                'title'                => '',
                'summary'              => '',
                'body'                 => '',
                'starts_at'            => '',
                'ends_at'              => '',
                'venue'                => '',
                'address'              => '',
                'capacity'             => 20,
                'reservations_enabled' => true,
                'published'            => false,
            ];

        if (!$request->isMethod('POST')) {
            return Response::html($this->page($data, $language));
        }

        $normalized = EventValidator::normalize($data);
        $errors = EventValidator::errors($normalized);

        if ([] === $errors && EventsServices::events()->exists($normalized['slug'], $locale)) {
            $errors[] = 'That slug is already used by another event in this locale.';
        }

        if ([] !== $errors) {
            return Response::html($this->page($data, $language, $errors), 400);
        }

        $event = Event::create($locale, $normalized);
        EventsServices::events()->insert($event);
        CacheInvalidator::invalidate('/events');
        AuditServices::log(
            'event.create',
            'event:' . $event->id,
            'Created event ' . $event->slug . ' (' . $locale . ')',
            $request->getClientIp(),
        );

        return Response::redirect($this->url('mark.events') . '?locale=' . $language);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private function page(array $data, string $language, array $errors = []): string
    {
        return CardboardLayout::render(
            'New event',
            EventsPanelHtml::editor(null, $data, $language, $this->urlBuilder(), $errors),
            'events',
            router: $this->router(),
        );
    }
}
