<?php

declare(strict_types = 1);

namespace Modules\Events\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use Modules\Events\Interfaces\HTTP\Actions\EventsIndexAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class EventsIndexActionTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/events-index-' . bin2hex(random_bytes(6));
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
        @unlink($this->dir . '/events.db');
        @rmdir($this->dir);
        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function onlyUpcomingPublishedEventsAreListed(): void
    {
        $this->seed('future', 'Budúce podujatie', '+10 days', true);
        $this->seed('draft', 'Návrh podujatia', '+10 days', false);
        $this->seed('past', 'Minulé podujatie', '-10 days', true);

        $response = (new EventsIndexAction())->handle($this->request('/events', 'sk_SK'));
        $html = $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Budúce podujatie', $html);
        self::assertStringNotContainsString('Návrh podujatia', $html);
        self::assertStringNotContainsString('Minulé podujatie', $html);
        self::assertStringContainsString('CollectionPage', $html);
    }

    #[Test]
    public function theListingIsLocaleScoped(): void
    {
        $this->seed('slovenske', 'Slovenské podujatie', '+10 days', true, 'sk_SK');
        $this->seed('english', 'English event', '+10 days', true, 'en_US');

        $response = (new EventsIndexAction())->handle($this->request('/events', 'en_US'));
        $html = $response->getContent();

        self::assertStringContainsString('English event', $html);
        self::assertStringNotContainsString('Slovenské podujatie', $html);
        self::assertStringContainsString('/events', $html);
        self::assertStringContainsString('hreflang', $html);
    }

    #[Test]
    public function theStaticRouteWinsOverThePagesCatchAll(): void
    {
        $router = new Router([APP_BASE_PATH . '/src/Modules']);
        $match = $router->match(Request::create('GET', '/events'));

        self::assertNotNull($match);
        self::assertSame(EventsIndexAction::class, $match['action']);
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

    private function seed(string $slug, string $title, string $when, bool $published, string $locale = 'sk_SK'): void
    {
        EventsServices::events()->insert(Event::create($locale, [
            'slug'      => $slug,
            'title'     => $title,
            'summary'   => 'Krátky opis.',
            'starts_at' => (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'),
            'capacity'  => 20,
            'published' => $published,
        ]));
    }

    private function request(string $path, string $locale): Request
    {
        $request = Request::create('GET', $path);
        $request->setAttribute('locale', $locale);

        return $request;
    }
}
