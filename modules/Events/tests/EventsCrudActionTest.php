<?php

declare(strict_types = 1);

namespace Modules\Events\Tests;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Domain\Entities\Reservation;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use Modules\Events\Interfaces\HTTP\Actions\EventsCreateAction;
use Modules\Events\Interfaces\HTTP\Actions\EventsDeleteAction;
use Modules\Events\Interfaces\HTTP\Actions\EventsPublishToggleAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class EventsCrudActionTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    private Router $router;

    /** @var list<string> */
    private array $cacheFiles = [];

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/events-crud-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        putenv('DB_EVENTS=' . $this->dir . '/events.db');
        putenv('DB_AUDIT=' . $this->dir . '/audit.db');
        putenv('DB_USERS=' . $this->dir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        EventsServices::reset();
        AuditServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new EventsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('events')))->run($output);
        (new AuditDatabaseMigrator(DatabaseConnectionManager::instance()->connection('audit')))->run($output);

        $this->router = new Router([APP_BASE_PATH . '/src/Modules']);
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (!isset($this->dir)) {
            return;
        }

        foreach ($this->cacheFiles as $file) {
            is_file($file) && unlink($file);
        }

        EventsServices::reset();
        AuditServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_EVENTS');
        putenv('DB_AUDIT');
        putenv('DB_USERS');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);

        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function anEventWriteInvalidatesOnlyEventPages(): void
    {
        $this->create('letny-tabor');
        $event = EventsServices::events()->findBySlug('letny-tabor', 'sk_SK');
        self::assertNotNull($event);

        $eventsEntry = $this->cache('/events', 'events-hash');
        $detailEntry = $this->cache('/events/letny-tabor', 'detail-hash');
        $unrelated = $this->cache('/kontakt', 'kontakt-hash');

        $response = (new EventsPublishToggleAction($this->router))->handle(
            $this->request('POST', '/mark/events/letny-tabor/publish', slug: 'letny-tabor'),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertFalse(is_file($eventsEntry));
        self::assertFalse(is_file($detailEntry));
        self::assertTrue(is_file($unrelated), 'An event write removed a cached page it did not change.');

        self::assertGreaterThan(0, $this->auditCount('event.publish'));
    }

    #[Test]
    public function anEventWithReservationsCannotBeDeleted(): void
    {
        $this->create('rezervovany');
        $event = EventsServices::events()->findBySlug('rezervovany', 'sk_SK');
        self::assertNotNull($event);

        EventsServices::reservations()->insert(Reservation::create($event->id, 'sk_SK', [
            'party_size' => 2,
            'name'       => 'Eva',
            'email'      => 'eva@example.test',
        ]));

        $response = (new EventsDeleteAction($this->router))->handle(
            $this->request('POST', '/mark/events/rezervovany/delete', slug: 'rezervovany'),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($response->getHeader('Location'));
        self::assertNotNull(EventsServices::events()->findBySlug('rezervovany', 'sk_SK'));
        self::assertSame(1, EventsServices::reservations()->countForEvent($event->id));
    }

    private function create(string $slug): void
    {
        $response = (new EventsCreateAction($this->router))->handle($this->request('POST', '/mark/events/create', [
            'slug'                 => $slug,
            'title'                => 'Letný tábor',
            'summary'              => 'Týždeň pre deti.',
            'body'                 => 'Program.',
            'starts_at'            => '2027-08-10 18:00',
            'ends_at'              => '2027-08-10 20:00',
            'venue'                => 'Centrum',
            'address'              => 'Hlavná 1',
            'capacity'             => '20',
            'reservations_enabled' => '1',
        ], 'sk'));

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * @param array<string, string>|string $body
     */
    private function request(string $method, string $path, array|string $body = [], ?string $slug = null): Request
    {
        $request = Request::create($method, $path, [], is_array($body) ? $body : []);
        $request->setAttribute('locale', 'sk_SK');

        if (null !== $slug) {
            $request->setRouteParams(['slug' => $slug]);
        }

        return $request;
    }

    private function cache(string $path, string $name): string
    {
        $dir = APP_BASE_PATH . '/' . \Modules\PageCache\Application\Services\PageCacheService::DEFAULT_DIR;
        is_dir($dir) || mkdir($dir, 0o777, true);
        $file = $dir . '/events-test-' . $name . '-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, json_encode([
            'content'  => '<html>cached</html>',
            'statusCode' => 200,
            'headers'  => ['Content-Type' => 'text/html'],
            'path'     => $path,
            'cachedAt' => time(),
            'ttl'      => 300,
        ]));
        $this->cacheFiles[] = $file;

        return $file;
    }

    private function auditCount(string $action): int
    {
        $stmt = DatabaseConnectionManager::instance()->connection('audit')
            ->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $stmt->execute(['action' => $action]);

        return (int) $stmt->fetchColumn();
    }
}
