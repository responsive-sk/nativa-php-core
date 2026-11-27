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
use Modules\Events\Interfaces\HTTP\Actions\ReservationSubmitAction;
use Modules\Users\Domain\Contracts\Mailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class ReservationMailTest extends TestCase
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

        $this->dir = sys_get_temp_dir() . '/events-mail-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        putenv('DB_EVENTS=' . $this->dir . '/events.db');
        putenv('DB_USERS=' . $this->dir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        EventsServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new EventsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('events')))->run($output);

        $this->event = Event::create('sk_SK', [
            'slug'      => 'mail',
            'title'     => 'Mail test',
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
        putenv('DB_USERS');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        @rmdir($this->dir);
        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function aFailedGuestMailDoesNotRollBackTheReservation(): void
    {
        EventsServices::setMailer(new EventsMailer(new class implements Mailer {
            public function send(string $to, string $subject, string $text): bool
            {
                return false;
            }
        }));

        $response = (new ReservationSubmitAction())->handle(self::request());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, EventsServices::reservations()->countForEvent($this->event->id));
    }

    private static function request(): Request
    {
        $request = Request::create('POST', '/events/mail/reservations', [], [
            'name'       => 'Eva',
            'email'      => 'eva@example.test',
            'party_size' => '2',
            'consent'    => '1',
        ]);
        $request->setAttribute('locale', 'sk_SK');
        $request->setRouteParams(['slug' => 'mail']);

        return $request;
    }
}
