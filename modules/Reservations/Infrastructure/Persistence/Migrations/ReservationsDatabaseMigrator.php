<?php

declare(strict_types = 1);

namespace Modules\Reservations\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use Modules\Reservations\Domain\Model\ReservationStatus;

/**
 * ReservationsDatabaseMigrator - creates the orders database.
 *
 * One table holds the business record. The form submission that produced it
 * lives in forms.db and is referenced only by `submission_id`, which is unique:
 * the two databases cannot share a transaction, so the row is a snapshot, not
 * a foreign key across files.
 *
 * Every statement is idempotent, so a second migrate never fails or duplicates
 * a table or an index.
 */
#[AsMigrator(module: 'reservations', table: 'reservations')]
final readonly class ReservationsDatabaseMigrator implements MigratorInterface
{
    private SqlDialect $dialect;

    private string $timestamp;

    public function __construct(private \PDO $pdo)
    {
        $this->dialect = SqlDialect::forPdo($pdo);
        $this->timestamp = $this->dialect->timestampType();
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating reservations.db...</info>');

        $this->createReservationsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: reservations</info>');
    }

    private function createReservationsTable(): void
    {
        $statuses = implode(', ', array_map(
            static fn (ReservationStatus $status): string => "'" . $status->name . "'",
            ReservationStatus::cases(),
        ));
        $new = ReservationStatus::New->name;

        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS reservations (
                    id            VARCHAR(32) PRIMARY KEY,
                    submission_id VARCHAR(32) NOT NULL,
                    form_slug     VARCHAR(80) NOT NULL,
                    locale        VARCHAR(10) NOT NULL,
                    status        VARCHAR(12) NOT NULL DEFAULT '{$new}'
                                  CHECK (status IN ({$statuses})),
                    service       VARCHAR(160) NOT NULL,
                    event_date    VARCHAR(40) NOT NULL,
                    event_place   VARCHAR(160) NOT NULL DEFAULT '',
                    name          VARCHAR(120) NOT NULL,
                    email         VARCHAR(200) NOT NULL,
                    phone         VARCHAR(40) NOT NULL DEFAULT '',
                    message       TEXT NOT NULL DEFAULT '',
                    created_at    {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    decided_at    {$this->timestamp} DEFAULT NULL,
                    decided_by    VARCHAR(64) DEFAULT NULL
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_reservations_submission ON reservations(submission_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservations_status_created ON reservations(status, created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservations_form_created ON reservations(form_slug, created_at)');
    }
}
