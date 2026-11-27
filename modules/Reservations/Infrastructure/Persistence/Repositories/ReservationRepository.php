<?php

declare(strict_types = 1);

namespace Modules\Reservations\Infrastructure\Persistence\Repositories;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Reservations\Domain\Entities\Reservation;
use Modules\Reservations\Domain\Model\ReservationStatus;
use Modules\Reservations\Infrastructure\Persistence\Statement;

/**
 * ReservationRepository - persistence for order reservations.
 *
 * `submission_id` is unique and is how a repeated handler call stays
 * idempotent. The list is newest first; the status filter is an indexed read
 * because the badge and the inbox both ask the same question.
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
            'INSERT INTO reservations (id, submission_id, form_slug, locale, status, service, event_date,'
            . ' event_place, name, email, phone, message, created_at, decided_at, decided_by)'
            . ' VALUES (:id, :submission, :form, :locale, :status, :service, :date, :place, :name,'
            . ' :email, :phone, :message, :created, :decided, :by)',
        );
        $stmt->execute([
            'id'         => $data['id'],
            'submission' => $data['submission_id'],
            'form'       => $data['form_slug'],
            'locale'     => $data['locale'],
            'status'     => $data['status'],
            'service'    => $data['service'],
            'date'       => $data['event_date'],
            'place'      => $data['event_place'],
            'name'       => $data['name'],
            'email'      => $data['email'],
            'phone'      => $data['phone'],
            'message'    => $data['message'],
            'created'    => $data['created_at'],
            'decided'    => $data['decided_at'],
            'by'         => $data['decided_by'],
        ]);
    }

    public function findById(string $id): ?Reservation
    {
        $stmt = Statement::prepare($this->connection, 'SELECT * FROM reservations WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $this->one($stmt->fetch());
    }

    public function findBySubmissionId(string $submissionId): ?Reservation
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT * FROM reservations WHERE submission_id = :submission',
        );
        $stmt->execute(['submission' => $submissionId]);

        return $this->one($stmt->fetch());
    }

    /**
     * @return list<Reservation>
     */
    public function all(?ReservationStatus $status = null): array
    {
        $sql = 'SELECT * FROM reservations';
        $params = [];

        if (null !== $status) {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status->name;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';

        $stmt = Statement::prepare($this->connection, $sql);
        $stmt->execute($params);

        return $this->many($stmt->fetchAll());
    }

    public function countNew(): int
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT COUNT(*) FROM reservations WHERE status = :status',
        );
        $stmt->execute(['status' => ReservationStatus::New->name]);

        return (int) $stmt->fetchColumn();
    }

    public function updateStatus(string $id, ReservationStatus $status, ?string $decidedBy): void
    {
        $stmt = Statement::prepare(
            $this->connection,
            'UPDATE reservations SET status = :status, decided_at = CURRENT_TIMESTAMP,'
            . ' decided_by = :by WHERE id = :id',
        );
        $stmt->execute([
            'id'     => $id,
            'status' => $status->name,
            'by'     => $decidedBy,
        ]);
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
