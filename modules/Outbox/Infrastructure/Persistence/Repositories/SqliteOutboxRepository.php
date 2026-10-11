<?php

declare(strict_types = 1);

namespace Modules\Outbox\Infrastructure\Persistence\Repositories;

use Modules\Outbox\Domain\Model\OutboxMessage;

/**
 * SqliteOutboxRepository — SQLite implementation of the outbox repository.
 */
final readonly class SqliteOutboxRepository
{
    public function __construct(
        private \PDO $pdo,
    ) {
    }

    public function save(OutboxMessage $message): void
    {
        $data = $message->toArray();

        $stmt = $this->pdo->prepare('
            INSERT INTO outbox_messages (id, event_type, event_payload, metadata, status, attempts, max_attempts, last_attempt_at, processed_at, error_message, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $data['id'],
            $data['event_type'],
            $data['event_payload'],
            $data['metadata'],
            $data['status'],
            $data['attempts'],
            $data['max_attempts'],
            $data['last_attempt_at'],
            $data['processed_at'],
            $data['error_message'],
            $data['created_at'],
        ]);
    }

    public function findById(string $id): ?OutboxMessage
    {
        $stmt = $this->pdo->prepare('SELECT * FROM outbox_messages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (false === $row || !is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return OutboxMessage::fromArray($row);
    }

    /**
     * @return list<OutboxMessage>
     */
    public function findPending(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM outbox_messages
            WHERE status = ? AND attempts < max_attempts
            ORDER BY created_at ASC
            LIMIT ?
        ');
        $stmt->execute(['pending', $limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        /** @var list<OutboxMessage> $messages */
        $messages = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $messages[] = OutboxMessage::fromArray($row);
        }

        return $messages;
    }

    public function update(OutboxMessage $message): void
    {
        $data = $message->toArray();

        $stmt = $this->pdo->prepare('
            UPDATE outbox_messages
            SET status = ?, attempts = ?, last_attempt_at = ?, processed_at = ?, error_message = ?
            WHERE id = ?
        ');

        $stmt->execute([
            $data['status'],
            $data['attempts'],
            $data['last_attempt_at'],
            $data['processed_at'],
            $data['error_message'],
            $data['id'],
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM outbox_messages WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function cleanProcessed(string $interval = '7 days'): int
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM outbox_messages
            WHERE status = ? AND processed_at < datetime(\'now\', \'-\' || ?)
        ');
        $stmt->execute(['processed', $interval]);

        return $stmt->rowCount();
    }

    /**
     * @return array{pending: int, processing: int, processed: int, failed: int}
     */
    public function countByStatus(): array
    {
        $stmt = $this->pdo->query('
            SELECT
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed
            FROM outbox_messages
        ');

        if (false === $stmt) {
            return ['pending' => 0, 'processing' => 0, 'processed' => 0, 'failed' => 0];
        }

        $stmt->execute(['pending', 'processing', 'processed', 'failed']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (false === $row || !is_array($row)) {
            return ['pending' => 0, 'processing' => 0, 'processed' => 0, 'failed' => 0];
        }

        $pending = $row['pending'] ?? 0;
        $processing = $row['processing'] ?? 0;
        $processed = $row['processed'] ?? 0;
        $failed = $row['failed'] ?? 0;

        return [
            'pending' => is_int($pending) ? $pending : (int) $pending,
            'processing' => is_int($processing) ? $processing : (int) $processing,
            'processed' => is_int($processed) ? $processed : (int) $processed,
            'failed' => is_int($failed) ? $failed : (int) $failed,
        ];
    }
}
