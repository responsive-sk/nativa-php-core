<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Domain\SitemapEntry;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Application\Services\EventsSitemapContributor;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class EventsSitemapContributorTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/events-sitemap-' . bin2hex(random_bytes(6));
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
    public function publishedEventsAreListedAndDraftsAreNot(): void
    {
        $this->seed('zive', true);
        $this->seed('navrh', false);

        $paths = array_map(
            static fn (SitemapEntry $entry): string => $entry->path,
            (new EventsSitemapContributor())->entries(),
        );
        $prefix = ContentLocaleServices::mapper()->urlPrefix(ContentLocaleServices::mapper()->defaultLocale());

        self::assertContains($prefix . '/events', $paths);
        self::assertContains($prefix . '/events/zive', $paths);
        self::assertNotContains($prefix . '/events/navrh', $paths);
    }

    private function seed(string $slug, bool $published): void
    {
        EventsServices::events()->insert(Event::create('sk_SK', [
            'slug'      => $slug,
            'title'     => 'Event ' . $slug,
            'starts_at' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s'),
            'capacity'  => 20,
            'published' => $published,
        ]));
    }
}
