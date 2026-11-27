<?php

declare(strict_types = 1);

namespace Modules\Events\Application\Services;

use Modules\Events\Domain\Entities\Event;

/**
 * EventStructuredData: the schema.org blocks the event surfaces announce.
 *
 * The encoder uses JSON_HEX_TAG so a `</script>` sequence in an event title can
 * never break out of the script element.
 */
final class EventStructuredData
{
    public static function event(Event $event, string $url): string
    {
        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Event',
            'name'        => $event->title,
            'startDate'   => $event->startsAt->format(\DATE_ATOM),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'url'         => $url,
        ];

        if (null !== $event->endsAt) {
            $data['endDate'] = $event->endsAt->format(\DATE_ATOM);
        }

        if ('' !== $event->summary) {
            $data['description'] = $event->summary;
        }

        if ('' !== $event->venue || '' !== $event->address) {
            $data['location'] = array_filter([
                '@type'   => 'Place',
                'name'    => $event->venue,
                'address' => $event->address,
            ], static fn (string $value): bool => '' !== $value);
        }

        return self::encode($data);
    }

    public static function collection(string $name, string $url, string $homeUrl): string
    {
        return self::encode([
            [
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                'name'     => $name,
                'url'      => $url,
            ],
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $homeUrl],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $name],
                ],
            ],
        ]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }
}
