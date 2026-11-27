<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use Modules\Events\Interfaces\HTTP\Actions\EventViewAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class EventViewActionTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/events-view-' . bin2hex(random_bytes(6));
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
    public function aDraftIsNotFoundAndAPastPublishedEventStillRenders(): void
    {
        $this->seed('past', 'Minulé podujatie', '-10 days', true);
        $this->seed('draft', 'Návrh podujatia', '+10 days', false);

        $past = (new EventViewAction())->handle($this->request('/events/past', 'past'));
        $draft = (new EventViewAction())->handle($this->request('/events/draft', 'draft'));

        self::assertSame(200, $past->getStatusCode());
        self::assertStringContainsString('Minulé podujatie', $past->getContent());
        self::assertStringNotContainsString('name="party_size"', $past->getContent());
        self::assertSame(404, $draft->getStatusCode());
        self::assertStringNotContainsString('Návrh podujatia', $draft->getContent());
    }

    #[Test]
    public function theDetailCarriesEventStructuredData(): void
    {
        $this->seed('koncert', 'Koncert', '+10 days', true, 'Centrum', 'Hlavná 1');

        $response = (new EventViewAction())->handle($this->request('/events/koncert', 'koncert'));
        $html = $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"@type":"Event"', $html);
        self::assertStringContainsString('"startDate"', $html);
        self::assertStringContainsString('Centrum', $html);
        self::assertStringContainsString('rel="canonical"', $html);
        self::assertStringContainsString('/events/koncert', $html);
    }

    private function seed(string $slug, string $title, string $when, bool $published, string $venue = '', string $address = ''): void
    {
        EventsServices::events()->insert(Event::create('sk_SK', [
            'slug'      => $slug,
            'title'     => $title,
            'starts_at' => (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'),
            'venue'     => $venue,
            'address'   => $address,
            'capacity'  => 20,
            'published' => $published,
        ]));
    }

    private function request(string $path, string $slug): Request
    {
        $request = Request::create('GET', $path);
        $request->setAttribute('locale', 'sk_SK');
        $request->setRouteParams(['slug' => $slug]);

        return $request;
    }
}
