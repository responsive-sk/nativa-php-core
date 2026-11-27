<?php

declare(strict_types = 1);

namespace Modules\Events\Application\Services;

use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Domain\Entities\Reservation;
use Modules\Users\Application\Services\MarkNotifier;
use Modules\Users\Domain\Contracts\Mailer;
use Modules\Users\Infrastructure\Mail\MailerFactory;

/**
 * EventsMailer: the four messages an event produces.
 *
 * A stored reservation is never rolled back because a mail failed. Every send
 * is best-effort, synchronous and logged with the reservation id and mail kind
 * only: the guest's address and message body never enter the log.
 */
final readonly class EventsMailer
{
    public function __construct(
        private Mailer $mailer,
        private ?MarkNotifier $notifier = null,
    ) {
    }

    public static function create(): self
    {
        return new self(MailerFactory::create(), MarkNotifier::create());
    }

    public function acknowledgement(Event $event, Reservation $reservation): bool
    {
        return $this->sendGuest($event, $reservation, 'acknowledgement');
    }

    public function confirmation(Event $event, Reservation $reservation): bool
    {
        return $this->sendGuest($event, $reservation, 'confirmation');
    }

    public function cancellation(Event $event, Reservation $reservation): bool
    {
        return $this->sendGuest($event, $reservation, 'cancellation');
    }

    public function operatorAlert(Event $event, Reservation $reservation): bool
    {
        if (null === $this->notifier) {
            return false;
        }

        try {
            return $this->notifier->send(
                'New reservation: ' . $event->title,
                $this->body($event, $reservation, 'operator'),
            );
        } catch (\Throwable $e) {
            $this->logFailure($reservation, 'operator', $e->getMessage());

            return false;
        }
    }

    private function sendGuest(Event $event, Reservation $reservation, string $kind): bool
    {
        $copy = $this->copy($event->locale);
        $subjectKey = 'events.mail.' . $kind . '_subject';
        $subject = $copy[$subjectKey] ?? match ($kind) {
            'confirmation' => 'Your place is confirmed: {event}',
            'cancellation' => 'Your reservation was cancelled: {event}',
            default        => 'We received your reservation: {event}',
        };

        try {
            $sent = $this->mailer->send(
                $reservation->email,
                str_replace('{event}', $event->title, $subject),
                $this->body($event, $reservation, $kind, $copy),
            );

            if (!$sent) {
                $this->logFailure($reservation, $kind, 'transport returned false');
            }

            return $sent;
        } catch (\Throwable $e) {
            $this->logFailure($reservation, $kind, $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, string> $copy
     */
    private function body(Event $event, Reservation $reservation, string $kind, array $copy = []): string
    {
        $copy = [] === $copy ? $this->copy($event->locale) : $copy;
        $intro = $copy['events.mail.' . $kind . '_body'] ?? match ($kind) {
            'confirmation' => 'Your place is confirmed.',
            'cancellation' => 'Your reservation was cancelled.',
            'operator'     => 'A new reservation arrived.',
            default        => 'We received your reservation.',
        };

        return $intro . "\n\n"
            . $event->title . "\n"
            . ($copy['events.date'] ?? 'Date') . ': ' . $event->startsAt->format('Y-m-d H:i') . "\n"
            . ($event->venue === '' ? '' : ($copy['events.venue'] ?? 'Venue') . ': ' . $event->venue . "\n")
            . ($copy['events.party_size'] ?? 'Party size') . ': ' . $reservation->partySize . "\n"
            . ($copy['events.reservation_name'] ?? 'Name') . ': ' . $reservation->name . "\n";
    }

    /**
     * @return array<string, string>
     */
    private function copy(string $locale): array
    {
        return ShellContextProviderRegistry::copy('events', $locale);
    }

    private function logFailure(Reservation $reservation, string $kind, string $reason): void
    {
        error_log(sprintf(
            '[events] reservation=%s mail=%s failed: %s',
            $reservation->id,
            $kind,
            $reason,
        ));
    }
}
