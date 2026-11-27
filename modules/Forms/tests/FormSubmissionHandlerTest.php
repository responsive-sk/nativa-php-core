<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Application\Services\FormSubmissionHandlerRegistry;
use Modules\Forms\Domain\Contracts\FormSubmissionHandler;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Infrastructure\Persistence\Migrations\FormsDatabaseMigrator;
use Modules\Forms\Interfaces\HTTP\Actions\FormSubmitAction;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * FormSubmissionHandlerTest - a stored submission reaches installed handlers.
 *
 * The handler is the seam a feature module uses to derive its own record from
 * a form. The submission is stored first, and a handler that throws is logged
 * rather than allowed to turn a delivered form into an error page.
 */
final class FormSubmissionHandlerTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/forms-handlers-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        putenv('DB_FORMS=' . $this->dir . '/forms.db');
        putenv('DB_USERS=' . $this->dir . '/users.db');
        putenv('DB_CONFIG=' . $this->dir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        FormsServices::reset();

        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new FormsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('forms')))->run($output);
        // The migrator seeds `contact`; this test inserts its own definition
        // with that slug, the same reset FormSubmitActionTest makes.
        DatabaseConnectionManager::instance()->connection('forms')->exec('DELETE FROM forms');
        (new SettingsDatabaseMigrator(new \PDO('sqlite:' . $this->dir . '/config.db')))->run($output);
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (!isset($this->dir)) {
            return;
        }

        FormsServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_FORMS');
        putenv('DB_USERS');
        putenv('DB_CONFIG');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);

        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function aHandlerReceivesTheStoredSubmission(): void
    {
        $seen = new class implements FormSubmissionHandler {
            /** @var list<array{string, FormSubmission}> */
            public array $handled = [];

            public function handle(Form $form, FormSubmission $submission): void
            {
                $this->handled[] = [$form->slug, $submission];
            }
        };

        FormsServices::setSubmissionHandlers(FormSubmissionHandlerRegistry::fromHandlers([$seen]));

        $form = $this->form();
        $response = (new FormSubmitAction())->handle(self::submit([
            'name' => 'Eva',
            'email' => 'eva@example.test',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $seen->handled);
        $this->assertSame('contact', $seen->handled[0][0]);

        $submissions = FormsServices::submissions()->listForForm($form->id);
        $this->assertCount(1, $submissions);
        $this->assertSame($submissions[0]->id, $seen->handled[0][1]->id);
    }

    #[Test]
    public function aThrowingHandlerDoesNotLoseTheSubmission(): void
    {
        $throwing = new class implements FormSubmissionHandler {
            public function handle(Form $form, FormSubmission $submission): void
            {
                throw new \RuntimeException('handler failure');
            }
        };

        FormsServices::setSubmissionHandlers(FormSubmissionHandlerRegistry::fromHandlers([$throwing]));

        $form = $this->form();
        $response = (new FormSubmitAction())->handle(self::submit([
            'name' => 'Eva',
            'email' => 'eva@example.test',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, FormsServices::submissions()->listForForm($form->id));
    }

    private function form(): Form
    {
        $form = Form::create(
            'contact',
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

        return $form;
    }

    /**
     * @param array<string, string> $body
     */
    private static function submit(array $body): Request
    {
        $request = Request::create('POST', '/forms/contact', [], $body);
        $request->setAttribute('locale', 'sk_SK');
        $request->setRouteParams(['slug' => 'contact']);

        return $request;
    }
}
