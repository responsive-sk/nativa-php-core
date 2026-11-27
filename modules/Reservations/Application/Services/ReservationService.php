<?php

declare(strict_types = 1);

namespace Modules\Reservations\Application\Services;

use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Reservations\Domain\Entities\Reservation;
use Modules\Reservations\Domain\Model\ReservationStatus;
use Modules\Reservations\Infrastructure\Persistence\Repositories\ReservationRepository;

/**
 * ReservationService - one owner for order writes and decisions.
 *
 * `record()` turns a submission into an order only when it carries a date and
 * a place. Repeating the call for the same submission is a no-op, so a
 * retried handler cannot create two orders from one form.
 */
final readonly class ReservationService
{
    public function __construct(private ReservationRepository $reservations)
    {
    }

    public function record(Form $form, FormSubmission $submission): ?Reservation
    {
        $reservation = Reservation::fromSubmission($form, $submission);

        if (null === $reservation) {
            return null;
        }

        $existing = $this->reservations->findBySubmissionId($submission->id);

        if (null !== $existing) {
            return $existing;
        }

        $this->reservations->insert($reservation);

        return $reservation;
    }

    /**
     * Confirm or cancel one order.
     *
     * A repeated decision is a no-op, and a cancelled order is terminal. The
     * caller uses `changed` to decide whether to write an audit row.
     *
     * @return array{reservation: Reservation|null, changed: bool}
     */
    public function decide(string $id, ReservationStatus $target, ?string $decidedBy): array
    {
        $reservation = $this->reservations->findById($id);

        if (null === $reservation || $reservation->status === $target || $reservation->status->isCancelled()) {
            return ['reservation' => $reservation, 'changed' => false];
        }

        if (ReservationStatus::New === $target) {
            return ['reservation' => $reservation, 'changed' => false];
        }

        $this->reservations->updateStatus($id, $target, $decidedBy);

        return ['reservation' => $reservation->withStatus($target, $decidedBy), 'changed' => true];
    }

    /**
     * @return list<Reservation>
     */
    public function all(?ReservationStatus $status = null): array
    {
        return $this->reservations->all($status);
    }

    public function findById(string $id): ?Reservation
    {
        return $this->reservations->findById($id);
    }

    public function countNew(): int
    {
        return $this->reservations->countNew();
    }
}
