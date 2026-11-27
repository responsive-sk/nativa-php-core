<?php

declare(strict_types = 1);

namespace Modules\Reservations\Domain\Entities;

use DateTimeImmutable;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Reservations\Domain\Model\ReservationStatus;

/**
 * Reservation - one order taken from a form submission.
 *
 * A reservation form is one that asks for an event date and a place. Those
 * two answers, with the customer, are the reason the order exists, and they
 * are the rule that tells an order form apart from a plain contact form
 * without a second setting an operator has to maintain. The service is the
 * `service` answer, or the form title when the form does not ask for one.
 *
 * The submission's answers are copied into the reservation row rather than
 * linked by a foreign key across two databases. The order is a business
 * record and outlives the form definition; `submission_id` is kept only for
 * idempotence, so it is unique and never used as a join.
 */
final readonly class Reservation
{
    public function __construct(
        public string $id,
        public string $submissionId,
        public string $formSlug,
        public string $locale,
        public ReservationStatus $status,
        public string $service,
        public string $eventDate,
        public string $eventPlace,
        public string $name,
        public string $email,
        public string $phone,
        public string $message,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $decidedAt = null,
        public ?string $decidedBy = null,
    ) {
    }

    public static function fromSubmission(Form $form, FormSubmission $submission): ?self
    {
        $service = self::text($submission->payload['service'] ?? '');
        $eventDate = self::text($submission->payload['event_date'] ?? '');
        $eventPlace = self::text($submission->payload['event_place'] ?? '');
        $name = self::text($submission->payload['name'] ?? '');
        $email = self::text($submission->payload['email'] ?? '');

        if ('' === $service) {
            $service = self::text($form->title);
        }

        if ('' === $service) {
            $service = $form->slug;
        }

        if ('' === $eventDate || '' === $eventPlace || '' === $name || '' === $email) {
            return null;
        }

        return new self(
            bin2hex(random_bytes(16)),
            $submission->id,
            $submission->formSlug,
            $submission->locale,
            ReservationStatus::New,
            $service,
            $eventDate,
            $eventPlace,
            $name,
            $email,
            self::text($submission->payload['phone'] ?? ''),
            self::text($submission->payload['message'] ?? $submission->payload['note'] ?? ''),
            $submission->createdAt ?? new DateTimeImmutable(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $created = self::date($row['created_at'] ?? null) ?? new DateTimeImmutable();
        $status = ReservationStatus::fromLabel(strtolower((string) ($row['status'] ?? ''))) ?? ReservationStatus::New;

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['submission_id'] ?? ''),
            (string) ($row['form_slug'] ?? ''),
            (string) ($row['locale'] ?? ''),
            $status,
            (string) ($row['service'] ?? ''),
            (string) ($row['event_date'] ?? ''),
            (string) ($row['event_place'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['phone'] ?? ''),
            (string) ($row['message'] ?? ''),
            $created,
            self::date($row['decided_at'] ?? null),
            self::nullable($row['decided_by'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'submission_id' => $this->submissionId,
            'form_slug'     => $this->formSlug,
            'locale'        => $this->locale,
            'status'        => $this->status->name,
            'service'       => $this->service,
            'event_date'    => $this->eventDate,
            'event_place'   => $this->eventPlace,
            'name'          => $this->name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'message'       => $this->message,
            'created_at'    => $this->createdAt->format('Y-m-d H:i:s'),
            'decided_at'    => $this->decidedAt?->format('Y-m-d H:i:s'),
            'decided_by'    => $this->decidedBy,
        ];
    }

    public function withStatus(ReservationStatus $status, ?string $decidedBy): self
    {
        return new self(
            $this->id,
            $this->submissionId,
            $this->formSlug,
            $this->locale,
            $status,
            $this->service,
            $this->eventDate,
            $this->eventPlace,
            $this->name,
            $this->email,
            $this->phone,
            $this->message,
            $this->createdAt,
            new DateTimeImmutable(),
            $decidedBy,
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function nullable(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $string = is_scalar($value) ? (string) $value : '';

        return '' === $string ? null : $string;
    }
}
