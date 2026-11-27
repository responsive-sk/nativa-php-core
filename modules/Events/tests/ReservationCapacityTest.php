<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Domain\Exception\ReservationRefused;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class ReservationCapacityTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    private Event $event;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/events-capacity-' . bin2hex(random_bytes(6));
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

        $this->event = Event::create('sk_SK', [
            'slug'      => 'kapacita',
            'title'     => 'Kapacita',
            'starts_at' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s'),
            'capacity'  => 10,
            'published' => true,
        ]);
        EventsServices::events()->insert($this->event);
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
    public function pendingReservationsHoldSeats(): void
    {
        $service = EventsServices::reservationsService();

        $service->reserve($this->event, self::data(4), '127.0.0.1');

        self::assertSame(6, $service->remaining($this->event));

        $service->reserve($this->event, self::data(6), '127.0.0.1');

        self::assertSame(0, $service->remaining($this->event));

        try {
            $service->reserve($this->event, self::data(7), '127.0.0.1');
            self::fail('A party larger than the remaining capacity was accepted.');
        } catch (ReservationRefused $refused) {
            self::assertSame(ReservationRefused::CAPACITY, $refused->reason);
        }

        self::assertSame(10, $service->activeSeats($this->event->id));
        self::assertCount(2, $service->forEvent($this->event->id));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(int $party): array
    {
        return [
            'party_size' => $party,
            'name'       => 'Eva',
            'email'      => 'eva@example.test',
            'phone'      => '',
            'note'       => '',
        ];
    }
}
