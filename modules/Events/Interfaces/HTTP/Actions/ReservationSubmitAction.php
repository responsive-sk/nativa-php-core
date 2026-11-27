<?php

declare(strict_types = 1);

namespace Modules\Events\Interfaces\HTTP\Actions;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ErrorPageRenderer;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Exception\ReservationRefused;

/**
 * POST /events/{slug}/reservations: the public request.
 *
 * The honeypot is checked before the lookup so a bot cannot probe which slugs
 * exist. Validation and capacity refusals re-render the detail with the old
 * input and no `Location` header. A stored row redirects to the private
 * confirmation marker, and mail is sent after the transaction commits.
 */
#[Route(path: '/events/{slug}/reservations', method: 'POST', name: 'events.reservations.store')]
final readonly class ReservationSubmitAction
{
    private const int RATE_LIMIT = 5;

    private const int RATE_WINDOW = 300;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $locale = self::locale($request);
        $language = ContentLocaleServices::mapper()->languageCode($locale);
        $path = ContentLocaleServices::mapper()->urlPrefix($locale) . '/events/' . rawurlencode($slug);

        if (null !== $this->stringOrNull($request->body('event_website'))) {
            return Response::redirect($path . '?reservation-sent=1#reserve');
        }

        $event = EventsServices::events()->findPublished($slug, $locale);

        if (null === $event) {
            return ErrorPageRenderer::response(
                404,
                'Not Found',
                'The requested event could not be found.',
                $request->getPathInfo(),
            );
        }

        $copy = EventsServices::copy($locale);
        $ip = $request->getClientIp();

        if (!EventsServices::limiter()->isAllowed('event-reservation:' . $ip, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return ErrorPageRenderer::response(
                429,
                'Too many requests',
                $copy['events.error.rate_limit'] ?? 'Too many attempts. Please try again shortly.',
                $request->getPathInfo(),
            );
        }

        if (!$event->reservationsOpen()) {
            return ErrorPageRenderer::response(
                409,
                'Reservations closed',
                $copy['events.closed'] ?? 'Reservations for this event are closed.',
                $request->getPathInfo(),
            );
        }

        $errors = $this->errors($request, $event->capacity, $copy);

        if ([] !== $errors) {
            return $this->reRender($request, $errors, $slug);
        }

        $data = [
            'party_size' => (int) $request->body('party_size'),
            'name'       => (string) $this->stringOrNull($request->body('name')),
            'email'      => (string) $this->stringOrNull($request->body('email')),
            'phone'      => (string) $this->stringOrNull($request->body('phone')),
            'note'       => (string) $this->stringOrNull($request->body('note')),
        ];

        try {
            $reservation = EventsServices::reservationsService()->reserve($event, $data, $ip);
        } catch (ReservationRefused $refused) {
            $errors = ReservationRefused::CAPACITY === $refused->reason
                ? [$copy['events.error.party'] ?? 'There are not enough seats left for this party.']
                : [$copy['events.closed'] ?? 'Reservations for this event are closed.'];

            return $this->reRender($request, array_values($errors), $slug);
        }

        // Best effort, after commit: a failed message never removes the row.
        EventsServices::mailer()->acknowledgement($event, $reservation);
        EventsServices::mailer()->operatorAlert($event, $reservation);

        return Response::redirect($path . '?reservation-sent=1#reserve');
    }

    /**
     * @param array<string, string> $copy
     *
     * @return list<string>
     */
    private function errors(Request $request, int $capacity, array $copy): array
    {
        $errors = [];
        $name = $this->stringOrNull($request->body('name'));
        $email = $this->stringOrNull($request->body('email'));
        $party = filter_var($request->body('party_size'), FILTER_VALIDATE_INT);
        $consent = $request->body('consent');

        if (null === $name) {
            $errors[] = $copy['events.error.required'] ?? 'This field is required.';
        } elseif (mb_strlen($name) > 120) {
            $errors[] = 'Name is too long (max 120 characters).';
        }

        if (null === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $copy['events.error.email'] ?? 'Enter a valid e-mail address.';
        } elseif (mb_strlen($email) > 200) {
            $errors[] = 'E-mail is too long (max 200 characters).';
        }

        if (false === $party || $party < 1 || $party > $capacity) {
            $errors[] = $copy['events.error.party'] ?? 'Party size must be a whole number from 1 to the capacity.';
        }

        if (null === $consent || '' === trim((string) $consent)) {
            $errors[] = $copy['events.error.consent'] ?? 'We need your consent to handle the reservation.';
        }

        return $errors;
    }

    /**
     * @param list<string> $errors
     */
    private function reRender(Request $request, array $errors, string $slug): Response
    {
        $request->setAttribute('events_errors', $errors);
        $request->setAttribute('events_old', [
            'name'       => (string) $this->stringOrNull($request->body('name')),
            'email'      => (string) $this->stringOrNull($request->body('email')),
            'phone'      => (string) $this->stringOrNull($request->body('phone')),
            'note'       => (string) $this->stringOrNull($request->body('note')),
            'party_size' => (string) ($request->body('party_size') ?? ''),
            'consent'    => null !== $request->body('consent') ? '1' : '',
        ]);
        $request->setRouteParams(['slug' => $slug]);

        return (new EventViewAction())->handle($request);
    }

    private static function locale(Request $request): string
    {
        $locale = $request->getAttribute('locale');

        return ContentLocaleServices::mapper()->code(is_string($locale) ? $locale : '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
