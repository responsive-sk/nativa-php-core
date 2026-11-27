<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Application\Services\EventsMailer;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use Modules\Events\Interfaces\HTTP\Actions\EventViewAction;
use Modules\Events\Interfaces\HTTP\Actions\ReservationSubmitAction;
use Modules\PageCache\Application\Services\PageCacheService;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Users\Domain\Contracts\Mailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class ReservationSubmitActionTest extends TestCase
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

        $this->dir = sys_get_temp_dir() . '/events-submit-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        putenv('DB_EVENTS=' . $this->dir . '/events.db');
        putenv('DB_USERS=' . $this->dir . '/users.db');
        putenv('DB_CONFIG=' . $this->dir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        EventsServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new EventsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('events')))->run($output);
        (new SettingsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('config')))->run($output);

        $this->event = Event::create('sk_SK', [
            'slug'      => 'kapacita',
            'title'     => 'Kapacita',
            'starts_at' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s'),
            'capacity'  => 10,
            'published' => true,
        ]);
        EventsServices::events()->insert($this->event);
        EventsServices::setMailer(new EventsMailer(new class implements Mailer {
            public function send(string $to, string $subject, string $text): bool
            {
                return true;
            }
        }));
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
        putenv('DB_USERS');
        putenv('DB_CONFIG');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        @rmdir($this->dir);
        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function aValidRequestStoredOnceRedirectsToThePrivateConfirmation(): void
    {
        $response = (new ReservationSubmitAction())->handle($this->request([
            'name'       => 'Eva',
            'email'      => 'eva@example.test',
            'party_size' => '2',
            'consent'    => '1',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/events/kapacita?reservation-sent=1#reserve', $response->getHeader('Location'));
        self::assertSame(1, EventsServices::reservations()->countForEvent($this->event->id));
    }

    #[Test]
    public function anOverCapacityRequestWritesNothing(): void
    {
        EventsServices::reservationsService()->reserve($this->event, [
            'party_size' => 8,
            'name'       => 'Prvá',
            'email'      => 'prva@example.test',
        ]);

        $response = (new ReservationSubmitAction())->handle($this->request([
            'name'       => 'Eva',
            'email'      => 'eva@example.test',
            'party_size' => '3',
            'consent'    => '1',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($response->getHeader('Location'));
        self::assertStringContainsString('role="alert"', $response->getContent());
        self::assertSame(1, EventsServices::reservations()->countForEvent($this->event->id));
        self::assertSame(2, EventsServices::reservationsService()->remaining($this->event));
    }

    #[Test]
    public function aFilledHoneypotWritesNothing(): void
    {
        $response = (new ReservationSubmitAction())->handle($this->request([
            'name'         => 'Bot',
            'email'        => 'bot@example.test',
            'party_size'   => '2',
            'consent'      => '1',
            'event_website' => 'https://spam.test',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, EventsServices::reservations()->countForEvent($this->event->id));
    }

    #[Test]
    public function avalidationFailureKeepsTheOldInput(): void
    {
        $response = (new ReservationSubmitAction())->handle($this->request([
            'name'       => 'Eva',
            'email'      => 'not-an-email',
            'party_size' => '2',
            'consent'    => '1',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($response->getHeader('Location'));
        self::assertStringContainsString('Eva', $response->getContent());
        self::assertStringContainsString('role="alert"', $response->getContent());
        self::assertSame(0, EventsServices::reservations()->countForEvent($this->event->id));
    }

    #[Test]
    public function theConfirmationIsNotCacheable(): void
    {
        $cache = new PageCacheService($this->dir . '/cache');
        $request = Request::create('GET', '/events/kapacita', ['reservation-sent' => '1']);
        $request->setAttribute('locale', 'sk_SK');
        $request->setRouteParams(['slug' => 'kapacita']);

        self::assertFalse($cache->isCacheableRequest($request));

        $response = (new EventViewAction())->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('/We received|Rezerváciu sme prijali/', $response->getContent());
    }

    /**
     * @param array<string, string> $body
     */
    private function request(array $body): Request
    {
        $request = Request::create('POST', '/events/kapacita/reservations', [], $body);
        $request->setAttribute('locale', 'sk_SK');
        $request->setRouteParams(['slug' => 'kapacita']);

        return $request;
    }
}
