<?php

declare(strict_types = 1);

namespace Modules\Events\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class EventRepositoryContractTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/events-repo-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        putenv('DB_EVENTS=' . $this->dir . '/events.db');
        DatabaseConnectionManager::resetInstance();
        EventsServices::reset();

        $this->migrate();
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

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);

        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function aSlugIsUniquePerLocale(): void
    {
        $first = self::event('letny-tabor', 'sk_SK', '+10 days');
        EventsServices::events()->insert($first);

        try {
            EventsServices::events()->insert(self::event('letny-tabor', 'sk_SK', '+11 days'));
            self::fail('A second event with the same slug and locale was accepted.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('slug', strtolower($e->getMessage()));
        }

        EventsServices::events()->insert(self::event('letny-tabor', 'en_US', '+12 days'));

        self::assertCount(1, EventsServices::events()->upcomingPublished('sk_SK'));
        self::assertNotNull(EventsServices::events()->findBySlug('letny-tabor', 'en_US'));
    }

    #[Test]
    public function aSecondMigrateCreatesNoDuplicateTable(): void
    {
        $this->migrate();

        $tables = DatabaseConnectionManager::instance()->connection('events')
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('events', 'event_reservations')")
            ->fetchAll();

        self::assertCount(2, $tables);
    }

    #[Test]
    public function aDraftIsAbsentFromTheUpcomingListAndNotFindableAsPublished(): void
    {
        EventsServices::events()->insert(self::event('draft', 'sk_SK', '+10 days', false));
        EventsServices::events()->insert(self::event('live', 'sk_SK', '+10 days'));

        $upcoming = EventsServices::events()->upcomingPublished('sk_SK');

        self::assertCount(1, $upcoming);
        self::assertSame('live', $upcoming[0]->slug);
        self::assertNull(EventsServices::events()->findPublished('draft', 'sk_SK'));
        self::assertNotNull(EventsServices::events()->findPublished('live', 'sk_SK'));
    }

    private function migrate(): void
    {
        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new EventsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('events')))->run($output);
    }

    private static function event(string $slug, string $locale, string $starts, bool $published = true): Event
    {
        return Event::create($locale, [
            'slug'       => $slug,
            'title'      => 'Event ' . $slug,
            'starts_at'  => (new \DateTimeImmutable($starts))->format('Y-m-d H:i:s'),
            'capacity'   => 20,
            'published'  => $published,
        ]);
    }
}
