<?php

declare(strict_types=1);

namespace Modules\Events\Tests;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\EventsServices;
use Modules\Events\Application\Services\EventsMailer;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Domain\Entities\Reservation;
use Modules\Events\Domain\Model\ReservationStatus;
use Modules\Events\Infrastructure\Persistence\Migrations\EventsDatabaseMigrator;
use Modules\Events\Interfaces\HTTP\Actions\ReservationDecisionAction;
use Modules\Locales\Infrastructure\Persistence\Migrations\LocalesDatabaseMigrator;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Users\Domain\Contracts\Mailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

final class ReservationDecisionActionTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    private Event $event;

    private Router $router;

    private Mailer $mailer;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/events-decide-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        putenv('DB_EVENTS=' . $this->dir . '/events.db');
        putenv('DB_AUDIT=' . $this->dir . '/audit.db');
        putenv('DB_USERS=' . $this->dir . '/users.db');
        putenv('DB_LOCALES=' . $this->dir . '/locales.db');
        DatabaseConnectionManager::resetInstance();
        EventsServices::reset();
        AuditServices::reset();
        LocalesServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new EventsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('events')))->run($output);
        (new AuditDatabaseMigrator(DatabaseConnectionManager::instance()->connection('audit')))->run($output);
        (new LocalesDatabaseMigrator(DatabaseConnectionManager::instance()->connection('locales')))->run($output);

        $this->event = Event::create('sk_SK', [
            'slug'      => 'vecera',
            'title'     => 'Večera',
            'starts_at' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s'),
            'capacity'  => 10,
            'published' => true,
        ]);
        EventsServices::events()->insert($this->event);

        $this->router = new Router([APP_BASE_PATH . '/src/Modules']);
        $this->mailer = new class implements Mailer {
            /** @var list<array{to: string, subject: string, text: string}> */
            public array $sent = [];

            public function send(string $to, string $subject, string $text): bool
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];

                return true;
            }
        };
        EventsServices::setMailer(new EventsMailer($this->mailer));
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (!isset($this->dir)) {
            return;
        }

        EventsServices::reset();
        AuditServices::reset();
        LocalesServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_EVENTS');
        putenv('DB_AUDIT');
        putenv('DB_USERS');
        putenv('DB_LOCALES');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        @rmdir($this->dir);
        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function confirmingKeepsTheSeatAndCancellingReleasesIt(): void
    {
        $reservation = $this->reservation(3);

        (new ReservationDecisionAction($this->router))->confirm($this->request('confirm', $reservation));

        self::assertSame(ReservationStatus::Confirmed, EventsServices::reservations()->findById($reservation->id)?->status);
        self::assertSame(3, EventsServices::reservationsService()->activeSeats($this->event->id));

        (new ReservationDecisionAction($this->router))->cancel($this->request('cancel', $reservation));

        self::assertSame(ReservationStatus::Cancelled, EventsServices::reservations()->findById($reservation->id)?->status);
        self::assertSame(0, EventsServices::reservationsService()->activeSeats($this->event->id));
    }

    #[Test]
    public function aConfirmSendsOneConfirmationMail(): void
    {
        $reservation = $this->reservation(2);

        (new ReservationDecisionAction($this->router))->confirm($this->request('confirm', $reservation));
        (new ReservationDecisionAction($this->router))->confirm($this->request('confirm', $reservation));

        $sent = $this->mailer->sent;

        self::assertCount(1, $sent);
        self::assertStringContainsString('potvrdené', $sent[0]['subject']);
        self::assertSame(1, $this->auditCount('reservation.confirm'));
    }

    #[Test]
    public function aCancelSendsOneCancellationMail(): void
    {
        $reservation = $this->reservation(2);
        (new ReservationDecisionAction($this->router))->confirm($this->request('confirm', $reservation));

        (new ReservationDecisionAction($this->router))->cancel($this->request('cancel', $reservation));

        $sent = $this->mailer->sent;

        self::assertCount(2, $sent);
        self::assertStringContainsString('zrušená', $sent[1]['subject']);
        self::assertSame(1, $this->auditCount('reservation.cancel'));
    }

    private function reservation(int $party): Reservation
    {
        return EventsServices::reservationsService()->reserve($this->event, [
            'party_size' => $party,
            'name'       => 'Eva',
            'email'      => 'eva@example.test',
        ]);
    }

    private function request(string $action, Reservation $reservation): Request
    {
        $request = Request::create('POST', '/mark/events/vecera/reservations/' . $reservation->id . '/' . $action);
        $request->setAttribute('locale', 'sk_SK');
        $request->setRouteParams(['slug' => 'vecera', 'id' => $reservation->id]);

        return $request;
    }

    private function auditCount(string $action): int
    {
        $stmt = DatabaseConnectionManager::instance()->connection('audit')
            ->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $stmt->execute(['action' => $action]);

        return (int) $stmt->fetchColumn();
    }
}
