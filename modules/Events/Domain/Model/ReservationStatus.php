<?php

declare(strict_types = 1);

namespace Modules\Events\Domain\Model;

/**
 * ReservationStatus: the closed vocabulary of a reservation's lifecycle.
 *
 * `pending` is a real hold: the seats were free when the request arrived.
 * `confirmed` keeps that hold and changes only what the guest was told.
 * `cancelled` releases the seats and is terminal for this row; the guest must
 * ask again rather than an operator reviving a cancelled request.
 */
enum ReservationStatus: string
{
    case Pending = 'pending';

    case Confirmed = 'confirmed';

    case Cancelled = 'cancelled';

    /**
     * The statuses whose party sizes count against capacity.
     *
     * @return list<string>
     */
    public static function holdingValues(): array
    {
        return [self::Pending->value, self::Confirmed->value];
    }

    public function holdsSeats(): bool
    {
        return self::Cancelled !== $this;
    }
}
