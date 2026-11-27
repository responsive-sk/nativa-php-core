<?php

declare(strict_types = 1);

namespace Modules\Reservations\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Infrastructure\Persistence\Migrations\FormsDatabaseMigrator;
use Modules\Forms\Interfaces\HTTP\Actions\FormSubmitAction;
use Modules\Reservations\Application\ReservationsServices;
use Modules\Reservations\Domain\Model\ReservationStatus;
use Modules\Reservations\Infrastructure\Persistence\Migrations\ReservationsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * ReservationSubmissionTest - an order form becomes one reservation.
 *
 * The handler is discovered by Forms when this module is installed. A form is
 * an order form when the submission carries a date and a place; a form that
 * asks only for a name and an e-mail therefore produces no reservation. The
 * service is the `service` answer, or the form title when the form has no
 * such field.
 */
final class ReservationSubmissionTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Reservations')
            || !is_dir(APP_BASE_PATH . '/src/Modules/Forms')
        ) {
            $this->markTestSkipped('Reservations or Forms is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/reservations-submit-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        putenv('DB_FORMS=' . $this->dir . '/forms.db');
        putenv('DB_RESERVATIONS=' . $this->dir . '/reservations.db');
        putenv('DB_USERS=' . $this->dir . '/users.db');
        putenv('DB_CONFIG=' . $this->dir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        FormsServices::reset();
        ReservationsServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new FormsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('forms')))->run($output);
        (new ReservationsDatabaseMigrator(new \PDO('sqlite:' . $this->dir . '/reservations.db')))->run($output);
        (new SettingsDatabaseMigrator(new \PDO('sqlite:' . $this->dir . '/config.db')))->run($output);
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (!isset($this->dir)) {
            return;
        }

        FormsServices::reset();
        ReservationsServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_FORMS');
        putenv('DB_RESERVATIONS');
        putenv('DB_USERS');
        putenv('DB_CONFIG');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);

        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function anOrderFormCreatesOneReservation(): void
    {
        $form = $this->orderForm();

        $response = (new FormSubmitAction())->handle(self::submit('objednavka-test', [
            'name' => 'Eva',
            'email' => 'eva@example.test',
            'phone' => '+421 900 000 000',
            'service' => 'Ohňová show',
            'event_date' => '2026-12-24',
            'event_place' => 'Bratislava',
        ]));

        $this->assertSame(302, $response->getStatusCode());

        $reservations = ReservationsServices::service()->all();

        $this->assertCount(1, $reservations);
        $this->assertSame('Ohňová show', $reservations[0]->service);
        $this->assertSame('2026-12-24', $reservations[0]->eventDate);
        $this->assertSame('Eva', $reservations[0]->name);
        $this->assertSame(ReservationStatus::New, $reservations[0]->status);
        $this->assertSame($form->slug, $reservations[0]->formSlug);
    }

    #[Test]
    public function aFormWithoutDateAndPlaceCreatesNothing(): void
    {
        $form = Form::create(
            'contact-test',
            'sk_SK',
            'Contact',
            '',
            'Thank you',
            FormSchema::fromRows([
                ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'max' => 200],
            ]),
            true,
        );
        FormsServices::forms()->insert($form);

        $response = (new FormSubmitAction())->handle(self::submit('contact-test', [
            'name' => 'Eva',
            'email' => 'eva@example.test',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], ReservationsServices::service()->all());
    }

    #[Test]
    public function anOrderFormWithoutServiceUsesTheFormTitle(): void
    {
        $form = Form::create(
            'objednavka-saso-test',
            'sk_SK',
            'Objednávka šaša',
            '',
            'Thank you',
            FormSchema::fromRows([
                ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'max' => 200],
                ['key' => 'event_date', 'label' => 'Date', 'type' => 'text', 'required' => true, 'max' => 40],
                ['key' => 'event_place', 'label' => 'Place', 'type' => 'text', 'required' => true, 'max' => 160],
            ]),
            true,
        );
        FormsServices::forms()->insert($form);

        $response = (new FormSubmitAction())->handle(self::submit('objednavka-saso-test', [
            'name' => 'Eva',
            'email' => 'eva@example.test',
            'event_date' => '2026-12-24',
            'event_place' => 'Bratislava',
        ]));

        $this->assertSame(302, $response->getStatusCode());

        $reservations = ReservationsServices::service()->all();

        $this->assertCount(1, $reservations);
        $this->assertSame('Objednávka šaša', $reservations[0]->service);
    }

    #[Test]
    public function aRepeatedSubmissionDoesNotDuplicateTheOrder(): void
    {
        $form = $this->orderForm();
        $submission = FormSubmission::create(
            $form->id,
            $form->slug,
            $form->locale,
            [
                'name' => 'Eva',
                'email' => 'eva@example.test',
                'service' => 'Ohňová show',
                'event_date' => '2026-12-24',
                'event_place' => 'Bratislava',
            ],
            null,
        );
        FormsServices::submissions()->insert($submission);

        $service = ReservationsServices::service();
        $first = $service->record($form, $submission);
        $second = $service->record($form, $submission);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $service->all());
    }

    private function orderForm(): Form
    {
        $form = Form::create(
            'objednavka-test',
            'sk_SK',
            'Order',
            '',
            'Thank you',
            FormSchema::fromRows([
                ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'max' => 120],
                ['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'max' => 200],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'required' => false, 'max' => 40],
                ['key' => 'service', 'label' => 'Service', 'type' => 'text', 'required' => true, 'max' => 160],
                ['key' => 'event_date', 'label' => 'Date', 'type' => 'text', 'required' => true, 'max' => 40],
                ['key' => 'event_place', 'label' => 'Place', 'type' => 'text', 'required' => false, 'max' => 160],
            ]),
            true,
        );

        FormsServices::forms()->insert($form);

        return $form;
    }

    /**
     * @param array<string, string> $body
     */
    private static function submit(string $slug, array $body): Request
    {
        $request = Request::create('POST', '/forms/' . $slug, [], $body);
        $request->setAttribute('locale', 'sk_SK');
        $request->setRouteParams(['slug' => $slug]);

        return $request;
    }
}
