<?php

declare(strict_types = 1);

namespace Modules\Queue\Application\Services;

use Closure;
use Modules\Queue\Exceptions\PoisonJobException;
use PDO;
use Throwable;

/**
 * DatabaseQueue — SQLite-backed job queue on the jobs / failed_jobs
 * tables (QueueDatabaseMigrator schema).
 *
 * Reservation runs under BEGIN IMMEDIATE with a guarded UPDATE
 * (`... WHERE id = ? AND reserved_at IS NULL`): SQLite has no
 * SELECT ... FOR UPDATE / SKIP LOCKED, so the second contender's UPDATE
 * matches zero rows and gets null instead of the same job. That deviation
 * from row-locking queues is load-bearing — do not "simplify" the guard
 * away.
 *
 * The `attempts` column is authoritative: reserve increments it, and the
 * worker parks the job in failed_jobs (with the error message) once
 * attempts >= max_attempts. Handler failures and unknown handlers both
 * land there — never a fatal.
 *
 * Time is a `Y-m-d H:i:s` string column compared lexicographically; the
 * clock is injectable for tests (default: wall time).
 */
final class DatabaseQueue
{
    private readonly Closure $now;

    public function __construct(
        private readonly PDO $pdo,
        private readonly HandlerRegistry $handlers,
        ?callable $now = null,
    ) {
        $this->now = $now instanceof Closure ? $now : time(...);
    }

    /**
     * Push a job. Returns the job id (32-char hex, tree convention).
     *
     * @param array<string, mixed> $args JSON-encoded into the payload column
     */
    public function push(
        string $handler,
        array $args = [],
        string $queue = 'default',
        int $maxAttempts = 3,
        int $delaySeconds = 0,
    ): string {
        $id = bin2hex(random_bytes(16));

        $statement = $this->pdo->prepare(
            'INSERT INTO jobs (id, queue, handler, payload, attempts, max_attempts, reserved_at, available_at)'
            . ' VALUES (:id, :queue, :handler, :payload, 0, :max_attempts, NULL, :available_at)',
        );
        $statement->execute([
            ':id' => $id,
            ':queue' => $queue,
            ':handler' => $handler,
            ':payload' => json_encode($args, flags: JSON_THROW_ON_ERROR),
            ':max_attempts' => max(1, $maxAttempts),
            ':available_at' => $this->stamp($delaySeconds),
        ]);

        return $id;
    }

    /**
     * Reserve the next available job (null queue = any queue, earliest
     * available_at first). Returns null when nothing is reservable.
     *
     * @throws PoisonJobException when the row's payload is corrupt — the
     *   row is already parked in failed_jobs; call reserve() again.
     */
    public function reserve(?string $queue = null): ?QueuedJob
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $row = $this->findAvailable($queue);

            if (null === $row) {
                $this->pdo->exec('ROLLBACK');

                return null;
            }

            $row['attempts'] = (int) $row['attempts'] + 1;

            try {
                $job = QueuedJob::fromRow($row);
            } catch (Throwable $e) {
                // Corrupt payload: park the row before anyone can spin on
                // it, then tell the caller to move to the next job.
                $this->parkPoison($row, $e->getMessage());
                $this->pdo->exec('COMMIT');

                throw new PoisonJobException(
                    sprintf('Job %s has a corrupt payload and was moved to failed_jobs.', $row['id']),
                    previous: $e,
                );
            }

            $claimed = $this->pdo->prepare(
                'UPDATE jobs SET reserved_at = :now, attempts = attempts + 1'
                . ' WHERE id = :id AND reserved_at IS NULL',
            );
            $claimed->execute([':now' => $this->stamp(), ':id' => $row['id']]);

            if (0 === $claimed->rowCount()) {
                // Lost the race: another process reserved it first.
                $this->pdo->exec('ROLLBACK');

                return null;
            }

            $this->pdo->exec('COMMIT');

            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }

            throw $e;
        }
    }

    /**
     * Delete a finished job.
     */
    public function delete(string $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id');
        $statement->execute([':id' => $id]);
    }

    /**
     * Release a reserved job back to available (optional backoff delay).
     */
    public function release(string $id, int $delaySeconds = 0): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE jobs SET reserved_at = NULL, available_at = :at WHERE id = :id',
        );
        $statement->execute([':at' => $this->stamp($delaySeconds), ':id' => $id]);
    }

    /**
     * Park an exhausted job in failed_jobs with the error that sank it.
     */
    public function fail(QueuedJob $job, string $error): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO failed_jobs (id, queue, handler, payload, attempts, error)'
            . ' VALUES (:id, :queue, :handler, :payload, :attempts, :error)',
        );
        $statement->execute([
            ':id' => $job->id,
            ':queue' => $job->queue,
            ':handler' => $job->handler,
            ':payload' => json_encode($job->args, flags: JSON_THROW_ON_ERROR),
            ':attempts' => $job->attempts,
            ':error' => $error,
        ]);

        $this->delete($job->id);
    }

    /**
     * Run up to $limit jobs from $queue (null = any queue).
     *
     * A throwing handler releases the job for retry until attempts reach
     * max_attempts, then parks it in failed_jobs. Corrupt payloads and
     * unknown handlers travel the same path.
     *
     * @return array{processed: int, failed: int}
     */
    public function work(?string $queue = null, int $limit = 10): array
    {
        $processed = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            try {
                $job = $this->reserve($queue);
            } catch (PoisonJobException $e) {
                // Already parked in failed_jobs by reserve().
                $failed++;

                continue;
            }

            if (null === $job) {
                break;
            }

            try {
                $this->handlers->run($job->handler, $job->args);
                $this->delete($job->id);
                $processed++;
            } catch (Throwable $e) {
                if ($job->attempts >= $job->maxAttempts) {
                    $this->fail($job, $e->getMessage());
                    $failed++;
                } else {
                    $this->release($job->id);
                }
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * Move a corrupt-payload row to failed_jobs. The caller holds the
     * IMMEDIATE transaction; this neither commits nor rolls back.
     *
     * @param array<string, mixed> $row
     */
    private function parkPoison(array $row, string $error): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO failed_jobs (id, queue, handler, payload, attempts, error)'
            . ' VALUES (:id, :queue, :handler, :payload, :attempts, :error)',
        );
        $statement->execute([
            ':id' => $row['id'],
            ':queue' => $row['queue'],
            ':handler' => $row['handler'],
            ':payload' => $row['payload'],
            ':attempts' => $row['attempts'],
            ':error' => 'Corrupt payload: ' . $error,
        ]);

        $this->delete($row['id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAvailable(?string $queue): ?array
    {
        if (null === $queue) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM jobs WHERE reserved_at IS NULL AND available_at <= :now'
                . ' ORDER BY available_at ASC LIMIT 1',
            );
            $statement->execute([':now' => $this->stamp()]);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT * FROM jobs WHERE queue = :queue AND reserved_at IS NULL AND available_at <= :now'
                . ' ORDER BY available_at ASC LIMIT 1',
            );
            $statement->execute([':queue' => $queue, ':now' => $this->stamp()]);
        }

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return false === $row ? null : $row;
    }

    private function stamp(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', ($this->now)() + $offsetSeconds);
    }
}
