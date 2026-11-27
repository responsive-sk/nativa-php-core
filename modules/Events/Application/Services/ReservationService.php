<?php

declare(strict_types = 1);

namespace Modules\Events\Application\Services;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\Driver;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Domain\Entities\Reservation;
use Modules\Events\Domain\Exception\ReservationRefused;
use Modules\Events\Domain\Model\ReservationStatus;
use Modules\Events\Infrastructure\Persistence\Repositories\EventRepository;
use Modules\Events\Infrastructure\Persistence\Repositories\ReservationRepository;

/**
 * ReservationService: one owner for every read and write that touches seats.
 *
 * A reservation is inserted only when the capacity sum and the insert are in
 * the same write transaction. The dialect's `beginWrite()` is `BEGIN IMMEDIATE`
 * on SQLite, so two visitors cannot both read the last free seat before either
 * writes. A busy conflict is retried a bounded number of times; a business
 * refusal is never retried because it is not a transient failure.
 */
final readonly class ReservationService
{
    private const int WRITE_ATTEMPTS = 3;

    private SqlDialect $dialect;

    public function __construct(
        private ConnectionInterface $connection,
        private EventRepository $events,
        private ReservationRepository $reservations,
    ) {
        $this->dialect = SqlDialect::forDriver(Driver::fromName($connection->driverName()));
    }

    public function remaining(Event $event): int
    {
        return $event->remainingSeats($this->reservations->activeSeats($event->id));
    }

    /**
     * Insert a pending reservation, or throw a business refusal.
     *
     * @param array<string, mixed> $data
     *
     * @throws ReservationRefused
     */
    public function reserve(Event $event, array $data, ?string $ipAddress = null): Reservation
    {
        $partySize = (int) ($data['party_size'] ?? 0);

        if ($partySize < 1) {
            throw new ReservationRefused(ReservationRefused::INVALID);
        }

        return $this->write(function () use ($event, $data, $ipAddress, $partySize): Reservation {
            // Re-read under the write lock: the event may have been unpublished
            // or its capacity lowered after the caller resolved it.
            $current = $this->events->findById($event->id);

            if (null === $current || !$current->reservationsOpen()) {
                throw new ReservationRefused(ReservationRefused::CLOSED);
            }

            $held = $this->reservations->activeSeats($current->id);

            if ($current->remainingSeats($held) < $partySize) {
                throw new ReservationRefused(ReservationRefused::CAPACITY);
            }

            $reservation = Reservation::create($current->id, $current->locale, $data, $ipAddress);
            $this->reservations->insert($reservation);

            return $reservation;
        });
    }

    /**
     * Confirm or cancel one reservation.
     *
     * Repeating the same decision is a no-op that reports `changed: false`, so
     * the caller sends no second mail and writes no second audit row. A
     * cancelled row cannot be confirmed: the guest asks again instead.
     *
     * @return array{reservation: Reservation|null, changed: bool}
     *
     * @throws ReservationRefused
     */
    public function decide(string $id, ReservationStatus $target, ?string $decidedBy): array
    {
        return $this->write(function () use ($id, $target, $decidedBy): array {
            $reservation = $this->reservations->findById($id);

            if (null === $reservation) {
                return ['reservation' => null, 'changed' => false];
            }

            if ($reservation->status === $target) {
                return ['reservation' => $reservation, 'changed' => false];
            }

            if (ReservationStatus::Confirmed === $target && $reservation->isCancelled()) {
                throw new ReservationRefused(ReservationRefused::CANCELLED);
            }

            $this->reservations->updateStatus($id, $target, $decidedBy);

            return ['reservation' => $reservation->withStatus($target, $decidedBy), 'changed' => true];
        });
    }

    /**
     * @return list<Reservation>
     */
    public function forEvent(string $eventId, ?ReservationStatus $status = null): array
    {
        return $this->reservations->forEvent($eventId, $status);
    }

    public function activeSeats(string $eventId): int
    {
        return $this->reservations->activeSeats($eventId);
    }

    public function countForEvent(string $eventId): int
    {
        return $this->reservations->countForEvent($eventId);
    }

    /**
     * Run one write transaction, retrying only a transient busy conflict.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function write(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= self::WRITE_ATTEMPTS; ++$attempt) {
            $this->connection->execute($this->dialect->beginWrite());

            try {
                $result = $callback();
                $this->connection->commit();

                return $result;
            } catch (\Throwable $e) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                if (!$e instanceof ReservationRefused && $attempt < self::WRITE_ATTEMPTS && $this->isBusy($e)) {
                    usleep(10_000 * $attempt);

                    continue;
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Reservation write exhausted its attempts.');
    }

    private function isBusy(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database is busy')
            || str_contains($message, 'deadlock')
            || str_contains($message, 'serialization');
    }
}
