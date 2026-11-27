<?php

declare(strict_types = 1);

namespace Modules\Reservations\Domain\Model;

/**
 * ReservationStatus - the closed vocabulary of an order reservation.
 *
 * The case name is the stored token: `New`, `Confirmed`, `Cancelled`. The
 * lower-case label is what the panel and the `status` query use, and it is
 * derived rather than stored as a second string.
 *
 * `New` is an order that arrived and still waits for an organizer. `Confirmed`
 * is the same order after the organizer accepted it. `Cancelled` is terminal:
 * a cancelled order is not revived, the customer places a new one.
 */
enum ReservationStatus
{
    case New;

    case Confirmed;

    case Cancelled;

    public function label(): string
    {
        return strtolower($this->name);
    }

    public function isNew(): bool
    {
        return self::New === $this;
    }

    public function isCancelled(): bool
    {
        return self::Cancelled === $this;
    }

    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $status) {
            if ($status->label() === $label) {
                return $status;
            }
        }

        return null;
    }

    /**
     * The stored values, in declaration order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->name,
            self::cases(),
        );
    }
}
