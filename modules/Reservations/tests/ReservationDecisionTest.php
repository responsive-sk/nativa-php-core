<?php

declare(strict_types = 1);

namespace Modules\Reservations\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Reservations\Application\ReservationsServices;
use Modules\Reservations\Domain\Entities\Reservation;
use Modules\Reservations\Domain\Model\ReservationStatus;
use Modules\Reservations\Infrastructure\Persistence\Migrations\ReservationsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * ReservationDecisionTest - the two organizer decisions.
 *
 * A repeated decision is a no-op, and cancellation is terminal. Both rules
 * protect the audit log and the customer from a second answer to the same
 * order.
 */
final class ReservationDecisionTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    private Reservation $reservation;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Reservations')) {
            $this->markTestSkipped('Reservations is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/reservations-decision-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        putenv('DB_RESERVATIONS=' . $this->dir . '/reservations.db');
        DatabaseConnectionManager::resetInstance();
        ReservationsServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new ReservationsDatabaseMigrator(new \PDO('sqlite:' . $this->dir . '/reservations.db')))->run($output);

        $form = Form::create(
            'objednavka-test',
            'sk_SK',
            'Objednávka test',
            '',
            '',
            FormSchema::fromRows([
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 120],
            ]),
            true,
        );
        $submission = FormSubmission::create(
            'form-id',
            'objednavka-test',
            'sk_SK',
            [
                'name' => 'Eva',
                'email' => 'eva@example.test',
                'service' => 'Ohňová show',
                'event_date' => '2026-12-24',
                'event_place' => 'Bratislava',
            ],
            null,
        );
        $reservation = Reservation::fromSubmission($form, $submission);
        $this->assertNotNull($reservation);
        $this->reservation = $reservation;

        ReservationsServices::reservations()->insert($reservation);
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (!isset($this->dir)) {
            return;
        }

        ReservationsServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_RESERVATIONS');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);

        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function confirmAndCancelChangeTheStatus(): void
    {
        $service = ReservationsServices::service();

        $confirmed = $service->decide($this->reservation->id, ReservationStatus::Confirmed, 'admin');
        $this->assertTrue($confirmed['changed']);
        $this->assertSame(ReservationStatus::Confirmed, $confirmed['reservation']?->status);

        $repeated = $service->decide($this->reservation->id, ReservationStatus::Confirmed, 'admin');
        $this->assertFalse($repeated['changed']);

        $cancelled = $service->decide($this->reservation->id, ReservationStatus::Cancelled, 'admin');
        $this->assertTrue($cancelled['changed']);
        $this->assertSame(ReservationStatus::Cancelled, $cancelled['reservation']?->status);
    }

    #[Test]
    public function aCancelledOrderCannotBeConfirmed(): void
    {
        $service = ReservationsServices::service();
        $service->decide($this->reservation->id, ReservationStatus::Cancelled, 'admin');

        $revived = $service->decide($this->reservation->id, ReservationStatus::Confirmed, 'admin');

        $this->assertFalse($revived['changed']);
        $this->assertSame(ReservationStatus::Cancelled, $service->findById($this->reservation->id)?->status);
    }
}
