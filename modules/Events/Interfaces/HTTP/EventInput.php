<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Events\Domain\Entities\Event;

/**
 * EventInput: one extraction for the create and edit forms.
 */
final class EventInput
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'slug'                 => $request->bodyString('slug'),
            'title'                => $request->bodyString('title'),
            'summary'              => $request->bodyString('summary'),
            'body'                 => $request->bodyString('body'),
            'starts_at'            => $request->bodyString('starts_at'),
            'ends_at'              => $request->bodyString('ends_at'),
            'venue'                => $request->bodyString('venue'),
            'address'              => $request->bodyString('address'),
            'capacity'             => $request->body('capacity'),
            'reservations_enabled' => null !== $request->body('reservations_enabled'),
            'published'            => null !== $request->body('published'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromEvent(Event $event): array
    {
        return [
            'slug'                 => $event->slug,
            'title'                => $event->title,
            'summary'              => $event->summary,
            'body'                 => $event->body,
            'starts_at'            => $event->startsAt->format('Y-m-d H:i'),
            'ends_at'              => $event->endsAt?->format('Y-m-d H:i') ?? '',
            'venue'                => $event->venue,
            'address'              => $event->address,
            'capacity'             => $event->capacity,
            'reservations_enabled' => $event->reservationsEnabled,
            'published'            => $event->published,
        ];
    }

    public static function locale(Request $request): string
    {
        $body = $request->bodyString('locale');
        $query = $request->queryString('locale', 'sk');

        return ContentLocaleServices::mapper()->code('' !== $body ? $body : $query);
    }

    public static function language(string $locale): string
    {
        return ContentLocaleServices::mapper()->languageCode($locale);
    }
}
