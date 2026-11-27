<?php

declare(strict_types = 1);

namespace Modules\Events\Application\Services;

use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Modules\Events\Domain\Entities\Event;
use Modules\Markdown\Application\Services\MarkdownRenderer;

/**
 * EventsRenderer: the public HTML for the listing and the detail.
 *
 * Every class is the active theme's prefix plus the vocabulary the tree
 * already styles: `*-section`, `*-section__title`, `*-article__body` and
 * `*-form__*`. No theme gains an Events method.
 */
final class EventsRenderer
{
    /**
     * @param list<Event>          $events
     * @param array<string, string> $copy
     * @param \Closure(string): string $detailHref
     */
    public static function listing(array $events, array $copy, string $prefix, \Closure $detailHref): string
    {
        $title = $copy['events.title'] ?? 'Events';
        $html = '<section class="' . $prefix . '-section">'
            . '<h1 class="' . $prefix . '-section__title">' . FrontendLayout::e($title) . '</h1>'
            . '<div class="' . $prefix . '-article__body">';

        if ([] === $events) {
            $html .= '<p>' . FrontendLayout::e($copy['events.empty'] ?? 'No events are scheduled yet.') . '</p>';
        } else {
            $html .= '<ul class="' . $prefix . '-event-list">';

            foreach ($events as $event) {
                $html .= '<li class="' . $prefix . '-event-list__item">'
                    . '<time datetime="' . FrontendLayout::e($event->startsAt->format('c')) . '">'
                    . FrontendLayout::e($event->startsAt->format('Y-m-d H:i')) . '</time> '
                    . '<a href="' . FrontendLayout::e($detailHref($event->slug)) . '">'
                    . FrontendLayout::e($event->title) . '</a>';

                if ('' !== $event->summary) {
                    $html .= '<p>' . FrontendLayout::e($event->summary) . '</p>';
                }

                $html .= '</li>';
            }

            $html .= '</ul>';
        }

        return $html . '</div></section>';
    }

    /**
     * @param array<string, string> $copy
     * @param list<string>          $errors
     * @param array<string, string> $old
     */
    public static function detail(
        Event $event,
        int $remaining,
        array $copy,
        string $prefix,
        string $formAction,
        bool $sent,
        bool $past,
        array $errors = [],
        array $old = [],
    ): string {
        $renderer = new MarkdownRenderer();
        $body = $renderer->render($event->body, new \Modules\Markdown\Interfaces\HTTP\Markdown\MarkdownBlockContext(
            $event->locale,
            $prefix,
            '/events/' . $event->slug,
        ));

        $html = '<section class="' . $prefix . '-section">'
            . '<h1 class="' . $prefix . '-section__title">' . FrontendLayout::e($event->title) . '</h1>'
            . '<p class="' . $prefix . '-event__meta">'
            . '<strong>' . FrontendLayout::e($copy['events.date'] ?? 'Date') . ':</strong> '
            . FrontendLayout::e($event->startsAt->format('Y-m-d H:i'));

        if (null !== $event->endsAt) {
            $html .= ' – ' . FrontendLayout::e($event->endsAt->format('Y-m-d H:i'));
        }

        if ('' !== $event->venue) {
            $html .= '<br><strong>' . FrontendLayout::e($copy['events.venue'] ?? 'Venue') . ':</strong> '
                . FrontendLayout::e($event->venue);
        }

        if ('' !== $event->address) {
            $html .= '<br><strong>' . FrontendLayout::e($copy['events.address'] ?? 'Address') . ':</strong> '
                . FrontendLayout::e($event->address);
        }

        $html .= '</p><div class="' . $prefix . '-article__body">' . $body . '</div>';

        if ($sent) {
            $html .= '<p class="' . $prefix . '-form__success" role="status">'
                . FrontendLayout::e($copy['events.thanks'] ?? 'We received your reservation.')
                . '</p>';
        }

        if ($past) {
            $html .= '<p class="' . $prefix . '-event__closed">'
                . FrontendLayout::e($copy['events.past'] ?? 'This event has already taken place.')
                . '</p></section>';

            return $html;
        }

        if (!$event->reservationsOpen()) {
            $html .= '<p class="' . $prefix . '-event__closed">'
                . FrontendLayout::e($copy['events.closed'] ?? 'Reservations for this event are closed.')
                . '</p></section>';

            return $html;
        }

        $html .= '<div id="reserve" class="' . $prefix . '-event__reserve">'
            . '<h2 class="' . $prefix . '-section__title">'
            . FrontendLayout::e($copy['events.reserve'] ?? 'Reserve a place') . '</h2>'
            . '<p>' . FrontendLayout::e($copy['events.remaining'] ?? 'Seats left') . ': ' . $remaining . '</p>';

        if ($remaining < 1) {
            $html .= '<p>' . FrontendLayout::e($copy['events.full'] ?? 'This event is full.') . '</p></div></section>';

            return $html;
        }

        $html .= self::form($event, $copy, $prefix, $formAction, $errors, $old)
            . '</div></section>';

        return $html;
    }

    /**
     * @param array<string, string> $copy
     * @param list<string>          $errors
     * @param array<string, string> $old
     */
    private static function form(Event $event, array $copy, string $prefix, string $action, array $errors, array $old): string
    {
        $errorHtml = '';

        if ([] !== $errors) {
            $errorHtml = '<div class="' . $prefix . '-form__errors" role="alert"><ul>';

            foreach ($errors as $error) {
                $errorHtml .= '<li>' . FrontendLayout::e($error) . '</li>';
            }

            $errorHtml .= '</ul></div>';
        }

        $value = static fn (string $key): string => FrontendLayout::e($old[$key] ?? '');

        return $errorHtml
            . '<form method="POST" action="' . FrontendLayout::e($action) . '" class="' . $prefix . '-form">'
            . CsrfMiddleware::field()
            . '<input type="text" name="event_website" value="" tabindex="-1" autocomplete="off" class="' . $prefix . '-form__honeypot" aria-hidden="true">'
            . self::field($prefix, $copy['events.reservation_name'] ?? 'Name', 'name', $value('name'), 'text', true)
            . self::field($prefix, $copy['events.reservation_email'] ?? 'E-mail', 'email', $value('email'), 'email', true)
            . self::field($prefix, $copy['events.reservation_phone'] ?? 'Phone', 'phone', $value('phone'), 'tel', false)
            . self::field($prefix, $copy['events.party_size'] ?? 'Party size', 'party_size', $value('party_size'), 'number', true, '1', (string) $event->capacity)
            . '<div class="' . $prefix . '-form__field"><label for="note">' . FrontendLayout::e($copy['events.reservation_note'] ?? 'Note') . '</label>'
            . '<textarea id="note" name="note" rows="4">' . $value('note') . '</textarea></div>'
            . '<div class="' . $prefix . '-form__field"><label><input type="checkbox" name="consent" value="1"' . ('' !== $value('consent') ? ' checked' : '') . '> '
            . FrontendLayout::e($copy['events.consent'] ?? 'I agree to the use of my details.') . '</label></div>'
            . '<button class="' . $prefix . '-form__submit" type="submit">'
            . FrontendLayout::e($copy['events.submit'] ?? 'Send reservation') . '</button>'
            . '</form>';
    }

    private static function field(string $prefix, string $label, string $name, string $value, string $type, bool $required, ?string $min = null, ?string $max = null): string
    {
        return '<div class="' . $prefix . '-form__field">'
            . '<label for="' . FrontendLayout::e($name) . '">' . FrontendLayout::e($label) . '</label>'
            . '<input id="' . FrontendLayout::e($name) . '" name="' . FrontendLayout::e($name) . '" type="' . FrontendLayout::e($type) . '"'
            . ' value="' . $value . '"' . ($required ? ' required' : '')
            . (null === $min ? '' : ' min="' . FrontendLayout::e($min) . '"')
            . (null === $max ? '' : ' max="' . FrontendLayout::e($max) . '"')
            . '></div>';
    }
}
