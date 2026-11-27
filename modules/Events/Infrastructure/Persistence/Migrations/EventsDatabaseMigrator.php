<?php

declare(strict_types = 1);

namespace Modules\Events\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\SqlDialect;

/**
 * EventsDatabaseMigrator: creates the events.db schema.
 *
 * Two tables share one database because capacity is a question about the
 * event and its reservation rows in one transaction. A split database cannot
 * answer that atomically.
 *
 * Every statement is idempotent (`CREATE TABLE/INDEX IF NOT EXISTS`), so a
 * second migrate never fails and never duplicates a table or an index.
 */
#[AsMigrator(module: 'events', table: 'events')]
final readonly class EventsDatabaseMigrator implements MigratorInterface
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
        $output->writeln('<info>Migrating events.db...</info>');

        $this->createEventsTable();
        $this->createReservationsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: events</info>');
        $output->writeln('<info>  ✓ Created table: event_reservations</info>');
    }

    private function createEventsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS events (
                    id                   VARCHAR(32) PRIMARY KEY,
                    slug                 VARCHAR(80) NOT NULL,
                    locale               VARCHAR(10) NOT NULL,
                    title                VARCHAR(160) NOT NULL,
                    summary              VARCHAR(500) NOT NULL DEFAULT '',
                    body                 TEXT NOT NULL DEFAULT '',
                    starts_at            {$this->timestamp} NOT NULL,
                    ends_at              {$this->timestamp} DEFAULT NULL,
                    venue                VARCHAR(160) NOT NULL DEFAULT '',
                    address              VARCHAR(240) NOT NULL DEFAULT '',
                    capacity             INTEGER NOT NULL CHECK (capacity > 0),
                    reservations_enabled INTEGER NOT NULL DEFAULT 1 CHECK (reservations_enabled IN (0, 1)),
                    published            INTEGER NOT NULL DEFAULT 0 CHECK (published IN (0, 1)),
                    created_at           {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    updated_at           {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createReservationsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS event_reservations (
                    id          VARCHAR(32) PRIMARY KEY,
                    event_id    VARCHAR(32) NOT NULL,
                    locale      VARCHAR(10) NOT NULL,
                    status      VARCHAR(12) NOT NULL DEFAULT 'pending'
                                CHECK (status IN ('pending', 'confirmed', 'cancelled')),
                    party_size  INTEGER NOT NULL CHECK (party_size > 0),
                    name        VARCHAR(120) NOT NULL,
                    email       VARCHAR(200) NOT NULL,
                    phone       VARCHAR(40) NOT NULL DEFAULT '',
                    note        TEXT NOT NULL DEFAULT '',
                    consent_at  {$this->timestamp} NOT NULL,
                    ip_address  VARCHAR(45) DEFAULT NULL,
                    created_at  {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    decided_at  {$this->timestamp} DEFAULT NULL,
                    decided_by  VARCHAR(64) DEFAULT NULL
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_events_slug_locale ON events(slug, locale)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_locale_published_starts ON events(locale, published, starts_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_event_reservations_event_status_created ON event_reservations(event_id, status, created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_event_reservations_status_created ON event_reservations(status, created_at)');
    }
}
