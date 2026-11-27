<?php

declare(strict_types = 1);

namespace Modules\Events\Infrastructure\Persistence\Repositories;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Events\Domain\Entities\Reservation;
use Modules\Events\Domain\Model\ReservationStatus;
use Modules\Events\Infrastructure\Persistence\Statement;

/**
 * ReservationRepository: persistence for reservation rows.
 *
 * Capacity is a SUM over this table, not a counter on `events`: a counter would
 * be a second copy of the rows and would drift after any failed write. The
 * indexes on `(event_id, status, created_at)` and `(status, created_at)` are the
 * two reads that matter, the capacity sum and the pending badge.
 */
final readonly class ReservationRepository
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function insert(Reservation $reservation): void
    {
        $data = $reservation->toArray();
        $stmt = Statement::prepare(
            $this->connection,
            'INSERT INTO event_reservations (id, event_id, locale, status, party_size, name, email, phone,'
            . ' note, consent_at, ip_address, created_at, decided_at, decided_by)'
            . ' VALUES (:id, :event, :locale, :status, :party, :name, :email, :phone, :note, :consent,'
            . ' :ip, :created, :decided, :by)',
        );
        $stmt->execute([
            'id'      => $data['id'],
            'event'   => $data['event_id'],
            'locale'  => $data['locale'],
            'status'  => $data['status'],
            'party'   => $data['party_size'],
            'name'    => $data['name'],
            'email'   => $data['email'],
            'phone'   => $data['phone'],
            'note'    => $data['note'],
            'consent' => $data['consent_at'],
            'ip'      => $data['ip_address'],
            'created' => $data['created_at'],
            'decided' => $data['decided_at'],
            'by'      => $data['decided_by'],
        ]);
    }

    public function findById(string $id): ?Reservation
    {
        $stmt = Statement::prepare($this->connection, 'SELECT * FROM event_reservations WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $this->one($stmt->fetch());
    }

    /**
     * @return list<Reservation>
     */
    public function forEvent(string $eventId, ?ReservationStatus $status = null): array
    {
        $sql = 'SELECT * FROM event_reservations WHERE event_id = :event';
        $params = ['event' => $eventId];

        if (null !== $status) {
            $sql .= ' AND status = :status';
            $params['status'] = $status->value;
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = Statement::prepare($this->connection, $sql);
        $stmt->execute($params);

        return $this->many($stmt->fetchAll());
    }

    /**
     * Seats currently held: pending and confirmed party sizes, never cancelled.
     *
     * This is the read the capacity transaction makes under its write lock.
     */
    public function activeSeats(string $eventId): int
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT COALESCE(SUM(party_size), 0) FROM event_reservations'
            . ' WHERE event_id = :event AND status IN (:pending, :confirmed)',
        );
        $stmt->execute([
            'event'     => $eventId,
            'pending'   => ReservationStatus::Pending->value,
            'confirmed' => ReservationStatus::Confirmed->value,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function countForEvent(string $eventId): int
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT COUNT(*) FROM event_reservations WHERE event_id = :event',
        );
        $stmt->execute(['event' => $eventId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The admin badge's one number: pending rows only.
     */
    public function countPending(): int
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT COUNT(*) FROM event_reservations WHERE status = :status',
        );
        $stmt->execute(['status' => ReservationStatus::Pending->value]);

        return (int) $stmt->fetchColumn();
    }

    public function updateStatus(string $id, ReservationStatus $status, ?string $decidedBy): void
    {
        $stmt = Statement::prepare(
            $this->connection,
            'UPDATE event_reservations SET status = :status, decided_at = CURRENT_TIMESTAMP,'
            . ' decided_by = :by WHERE id = :id',
        );
        $stmt->execute([
            'id'     => $id,
            'status' => $status->value,
            'by'     => $decidedBy,
        ]);
    }

    public function deleteForEvent(string $eventId): void
    {
        Statement::prepare($this->connection, 'DELETE FROM event_reservations WHERE event_id = :event')
            ->execute(['event' => $eventId]);
    }

    private function one(mixed $row): ?Reservation
    {
        return is_array($row) ? Reservation::fromArray($row) : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Reservation>
     */
    private function many(array $rows): array
    {
        $reservations = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $reservations[] = Reservation::fromArray($row);
            }
        }

        return $reservations;
    }
}
