<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Domain\Entities\Reservation;
use Modules\Events\Domain\Model\ReservationStatus;

/**
 * EventsPanelHtml: the Mark surface's markup, kept out of the route actions.
 *
 * The actions own decisions and redirects; this class owns the forms and the
 * escaping. Both the create and edit pages call `editor()`, so the two forms
 * cannot drift apart.
 */
final class EventsPanelHtml
{
    /**
     * @param list<Event>                          $events
     * @param \Closure(string, array<string, string|int>): string $url
     */
    public static function index(array $events, string $language, \Closure $url, int $pending): string
    {
        $content = '<div class="m-filter-bar">'
            . '<a class="m-btn m-btn-primary" href="' . CardboardLayout::e($url('mark.events.create') . '?locale=' . rawurlencode($language)) . '">New event</a>'
            . '<span class="m-muted">' . $pending . ' pending reservation(s)</span>'
            . '</div>';

        if ([] === $events) {
            return $content . CardboardLayout::emptyState(
                'No events in this locale',
                'Create an event, then publish it when the programme is ready.',
            );
        }

        $rows = '';

        foreach ($events as $event) {
            $rows .= self::indexRow($event, $language, $url);
        }

        return $content . '<div class="m-card"><table class="m-table"><thead><tr>'
            . '<th>Title</th><th>Starts</th><th>State</th><th>Capacity</th><th>Reservations</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    public static function editor(?Event $event, array $data, string $language, \Closure $url, array $errors = []): string
    {
        $action = null === $event
            ? $url('mark.events.create') . '?locale=' . rawurlencode($language)
            : $url('mark.events.edit', ['slug' => $event->slug]) . '?locale=' . rawurlencode($language);
        $errorHtml = '';

        if ([] !== $errors) {
            $errorHtml = '<div class="m-alert m-alert-error" role="alert"><ul>';

            foreach ($errors as $error) {
                $errorHtml .= '<li>' . CardboardLayout::e($error) . '</li>';
            }

            $errorHtml .= '</ul></div>';
        }

        $value = static fn (string $key): string => CardboardLayout::e((string) ($data[$key] ?? ''));
        $checked = static fn (string $key): string => (bool) ($data[$key] ?? false) ? ' checked' : '';

        return $errorHtml
            . '<form method="POST" action="' . CardboardLayout::e($action) . '" class="m-form">'
            . CardboardLayout::csrfField()
            . '<input type="hidden" name="locale" value="' . CardboardLayout::e($language) . '">'
            . self::field('slug', 'Slug', $value('slug'), 'text', true)
            . self::field('title', 'Title', $value('title'), 'text', true)
            . self::field('summary', 'Summary', $value('summary'), 'text', false)
            . self::field('starts_at', 'Starts at', $value('starts_at'), 'text', true, 'YYYY-MM-DD HH:MM')
            . self::field('ends_at', 'Ends at', $value('ends_at'), 'text', false, 'YYYY-MM-DD HH:MM')
            . self::field('venue', 'Venue', $value('venue'), 'text', false)
            . self::field('address', 'Address', $value('address'), 'text', false)
            . self::field('capacity', 'Capacity', $value('capacity'), 'number', true)
            . '<div class="m-field"><label><input type="checkbox" name="reservations_enabled" value="1"' . $checked('reservations_enabled') . '> Reservations enabled</label></div>'
            . '<div class="m-field"><label><input type="checkbox" name="published" value="1"' . $checked('published') . '> Published</label></div>'
            . '<div class="m-field"><label for="body">Body</label><textarea id="body" name="body" rows="14">' . $value('body') . '</textarea></div>'
            . '<button class="m-btn m-btn-primary" type="submit">Save event</button>'
            . '</form>';
    }

    /**
     * @param list<Reservation> $reservations
     * @param array<string, int> $counts
     * @param \Closure(string, array<string, string|int>): string $url
     */
    public static function reservations(Event $event, array $reservations, array $counts, \Closure $url): string
    {
        $summary = '<p class="m-muted">' . CardboardLayout::e($event->title) . ': '
            . $counts[ReservationStatus::Pending->value] . ' pending, '
            . $counts[ReservationStatus::Confirmed->value] . ' confirmed, '
            . $counts[ReservationStatus::Cancelled->value] . ' cancelled.</p>';

        if ([] === $reservations) {
            return $summary . CardboardLayout::emptyState('No reservations yet', 'Requests will appear here.');
        }

        $rows = '';

        foreach ($reservations as $reservation) {
            $rows .= self::reservationRow($event, $reservation, $url);
        }

        return $summary . '<div class="m-card"><table class="m-table"><thead><tr>'
            . '<th>Guest</th><th>Party</th><th>Status</th><th>Received</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * @param \Closure(string, array<string, string|int>): string $url
     */
    private static function indexRow(Event $event, string $language, \Closure $url): string
    {
        $localeQuery = '?locale=' . rawurlencode($language);
        $actions = '<a class="m-btn m-btn-ghost" href="' . CardboardLayout::e($url('mark.events.edit', ['slug' => $event->slug]) . $localeQuery) . '">Edit</a> '
            . '<a class="m-btn m-btn-ghost" href="' . CardboardLayout::e($url('mark.events.reservations', ['slug' => $event->slug]) . $localeQuery) . '">Reservations</a> '
            . self::postButton($url('mark.events.publish-toggle', ['slug' => $event->slug]) . $localeQuery, $event->published ? 'Unpublish' : 'Publish')
            . self::postButton($url('mark.events.delete', ['slug' => $event->slug]) . $localeQuery, 'Delete', 'Delete this event?');

        return '<tr>'
            . '<td>' . CardboardLayout::e($event->title) . '<br><code>' . CardboardLayout::e($event->slug) . '</code></td>'
            . '<td>' . CardboardLayout::e($event->startsAt->format('Y-m-d H:i')) . '</td>'
            . '<td>' . ($event->published ? 'Published' : 'Draft') . '</td>'
            . '<td>' . $event->capacity . '</td>'
            . '<td><a href="' . CardboardLayout::e($url('mark.events.reservations', ['slug' => $event->slug]) . $localeQuery) . '">Open inbox</a></td>'
            . '<td>' . $actions . '</td></tr>';
    }

    /**
     * @param \Closure(string, array<string, string|int>): string $url
     */
    private static function reservationRow(Event $event, Reservation $reservation, \Closure $url): string
    {
        $params = ['slug' => $event->slug, 'id' => $reservation->id];
        $actions = '';

        if ($reservation->isPending() || $reservation->isConfirmed()) {
            $actions .= self::postButton($url('mark.events.reservations.confirm', $params), 'Confirm');
            $actions .= self::postButton($url('mark.events.reservations.cancel', $params), 'Cancel', 'Cancel this reservation?');
        }

        return '<tr>'
            . '<td>' . CardboardLayout::e($reservation->name) . '<br><span class="m-muted">' . CardboardLayout::e($reservation->email) . '</span></td>'
            . '<td>' . $reservation->partySize . '</td>'
            . '<td><span class="m-badge">' . CardboardLayout::e($reservation->status->value) . '</span></td>'
            . '<td>' . CardboardLayout::e($reservation->createdAt->format('Y-m-d H:i')) . '</td>'
            . '<td>' . $actions . '</td></tr>';
    }

    private static function postButton(string $action, string $label, ?string $confirm = null): string
    {
        $onsubmit = null === $confirm ? '' : ' onsubmit="return confirm(\'' . CardboardLayout::e($confirm) . '\')"';

        return '<form method="POST" action="' . CardboardLayout::e($action) . '" style="display:inline"' . $onsubmit . '>'
            . CardboardLayout::csrfField()
            . '<button class="m-btn m-btn-ghost" type="submit">' . CardboardLayout::e($label) . '</button></form> ';
    }

    private static function field(string $name, string $label, string $value, string $type, bool $required = false, string $hint = ''): string
    {
        return '<div class="m-field"><label for="' . CardboardLayout::e($name) . '">' . CardboardLayout::e($label) . '</label>'
            . '<input id="' . CardboardLayout::e($name) . '" name="' . CardboardLayout::e($name) . '" type="' . CardboardLayout::e($type) . '"'
            . ' value="' . $value . '"' . ($required ? ' required' : '') . '>'
            . ('' === $hint ? '' : '<small class="m-muted">' . CardboardLayout::e($hint) . '</small>')
            . '</div>';
    }
}
