<?php

declare(strict_types = 1);

namespace Modules\Events\Domain\Exception;

/**
 * ReservationRefused: a business refusal inside the capacity transaction.
 *
 * The code is a closed set the action can translate without reading a message:
 * `capacity`, `closed`, `cancelled`, `invalid`. A thrown exception rolls the
 * transaction back, which is what makes "over capacity writes nothing" true
 * even when the insert is the last statement.
 */
final class ReservationRefused extends \RuntimeException
{
    public const string CAPACITY = 'capacity';

    public const string CLOSED = 'closed';

    public const string CANCELLED = 'cancelled';

    public const string INVALID = 'invalid';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Reservation refused: ' . $reason);
    }
}
