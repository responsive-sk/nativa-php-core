<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Application\Services\EventsBadge;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Domain\Entities\Reservation;
use Modules\Events\Domain\Model\ReservationStatus;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class EventsBadgeTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/events-badge-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        putenv('DB_EVENTS=' . $this->dir . '/events.db');
        DatabaseConnectionManager::resetInstance();
        EventsServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new EventsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('events')))->run($output);
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (!isset($this->dir)) {
            return;
        }

        EventsServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_EVENTS');
        @unlink($this->dir . '/events.db');
        @rmdir($this->dir);
        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function theBadgeCountsPendingRowsOnly(): void
    {
        $event = Event::create('sk_SK', [
            'slug'      => 'badge',
            'title'     => 'Badge',
            'starts_at' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s'),
            'capacity'  => 20,
            'published' => true,
        ]);
        EventsServices::events()->insert($event);

        $pending = Reservation::create($event->id, 'sk_SK', [
            'party_size' => 1,
            'name'       => 'A',
            'email'      => 'a@example.test',
        ]);
        $pending2 = Reservation::create($event->id, 'sk_SK', [
            'party_size' => 1,
            'name'       => 'B',
            'email'      => 'b@example.test',
        ]);
        $confirmed = Reservation::create($event->id, 'sk_SK', [
            'party_size' => 1,
            'name'       => 'C',
            'email'      => 'c@example.test',
        ])->withStatus(ReservationStatus::Confirmed, null);

        EventsServices::reservations()->insert($pending);
        EventsServices::reservations()->insert($pending2);
        EventsServices::reservations()->insert($confirmed);

        $badge = new EventsBadge();

        self::assertSame('/mark/events', $badge->navHref());
        self::assertSame(2, $badge->count());
    }
}
