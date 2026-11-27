<?php

declare(strict_types = 1);

namespace Modules\Events\Domain\Entities;

use DateTimeImmutable;
use Modules\Events\Domain\Model\ReservationStatus;

/**
 * Reservation: one party's place at one event.
 *
 * The entity owns the status vocabulary through `ReservationStatus`; no action
 * or view spells the three words itself. A row is immutable once read from the
 * database: a decision produces a new entity in the repository transition,
 * which keeps the old state from being mutated halfway through a render.
 */
// One reservation row is one entity: the guest details and the decision state
// belong to the same record.
final readonly class Reservation
{
    public function __construct(
        public string $id,
        public string $eventId,
        public string $locale,
        public ReservationStatus $status,
        public int $partySize,
        public string $name,
        public string $email,
        public string $phone,
        public string $note,
        public DateTimeImmutable $consentAt,
        public ?string $ipAddress,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $decidedAt,
        public ?string $decidedBy,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(string $eventId, string $locale, array $data, ?string $ipAddress = null): self
    {
        $now = new DateTimeImmutable();

        return new self(
            bin2hex(random_bytes(16)),
            $eventId,
            $locale,
            ReservationStatus::Pending,
            (int) ($data['party_size'] ?? 0),
            (string) ($data['name'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($data['phone'] ?? ''),
            (string) ($data['note'] ?? ''),
            $now,
            $ipAddress,
            $now,
            null,
            null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $created = self::date($row['created_at'] ?? null) ?? new DateTimeImmutable();
        $status = ReservationStatus::tryFrom((string) ($row['status'] ?? '')) ?? ReservationStatus::Pending;

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['event_id'] ?? ''),
            (string) ($row['locale'] ?? ''),
            $status,
            (int) ($row['party_size'] ?? 0),
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['phone'] ?? ''),
            (string) ($row['note'] ?? ''),
            self::date($row['consent_at'] ?? null) ?? $created,
            self::nullable($row['ip_address'] ?? null),
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
            'id'         => $this->id,
            'event_id'   => $this->eventId,
            'locale'     => $this->locale,
            'status'     => $this->status->value,
            'party_size' => $this->partySize,
            'name'       => $this->name,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'note'       => $this->note,
            'consent_at' => $this->consentAt->format('Y-m-d H:i:s'),
            'ip_address' => $this->ipAddress,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'decided_at' => $this->decidedAt?->format('Y-m-d H:i:s'),
            'decided_by' => $this->decidedBy,
        ];
    }

    public function isPending(): bool
    {
        return ReservationStatus::Pending === $this->status;
    }

    public function isConfirmed(): bool
    {
        return ReservationStatus::Confirmed === $this->status;
    }

    public function isCancelled(): bool
    {
        return ReservationStatus::Cancelled === $this->status;
    }

    public function holdsSeats(): bool
    {
        return $this->status->holdsSeats();
    }

    public function partySize(): int
    {
        return $this->partySize;
    }

    public function withStatus(ReservationStatus $status, ?string $decidedBy): self
    {
        return new self(
            $this->id,
            $this->eventId,
            $this->locale,
            $status,
            $this->partySize,
            $this->name,
            $this->email,
            $this->phone,
            $this->note,
            $this->consentAt,
            $this->ipAddress,
            $this->createdAt,
            new DateTimeImmutable(),
            $decidedBy,
        );
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
