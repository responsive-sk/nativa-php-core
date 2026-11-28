<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\SettingsAction;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Application\Mail\MailConfiguration;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;
use Modules\Settings\Interfaces\HTTP\SettingsServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppEnvironment;
use Tests\Support\SqliteDatabase;

/**
 * MailSettingsSaveTest — the Mail tab's write half.
 *
 * The save path is where a settings screen becomes trustworthy: a value it
 * accepts must be the value the runtime reads, an API key must survive a blank
 * field, and a refused save must write nothing. The success path is checked
 * through the repository; the refusal path is checked through the real HTML
 * response, so "the error landed on the Mail tab" is not a comment. The test
 * message is exercised through a real executable, so the argv and the message
 * body are the process's own record rather than a mock's opinion.
 *
 * The class pins `APP_ENV=production` for its own run. "No transport is
 * configured" is a DEPLOYMENT answer: with a development `APP_ENV`, `auto`
 * quietly falls back to the dev log instead, so a suite that inherits the
 * environment says something different on a machine with a `.env` than in an
 * export that carries none. The premise is stated here rather than inherited.
 */
final class MailSettingsSaveTest extends TestCase
{
    use SqliteDatabase;

    private string $tmpDir = '';

    /** @var \PDO|null */
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        foreach (['Cardboard', 'Settings', 'Audit'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        AppEnvironment::pin();

        $this->tmpDir = sys_get_temp_dir() . '/nativa-mail-settings-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        putenv('DB_AUDIT=' . $this->tmpDir . '/audit.db');
        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();
        SettingsServices::reset();
        \Modules\Audit\Interfaces\HTTP\AuditServices::reset();

        $this->pdo = new \PDO('sqlite:' . $this->tmpDir . '/config.db');
        (new SettingsDatabaseMigrator($this->pdo))->run(self::quietOutput());
        (new \Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator(
            new \PDO('sqlite:' . $this->tmpDir . '/audit.db'),
        ))->run(self::quietOutput());
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();
        AppEnvironment::restore();

        if ('' === $this->tmpDir) {
            parent::tearDown();

            return;
        }

        putenv('DB_CONFIG');
        putenv('DB_AUDIT');
        putenv('DB_USERS');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();
        SettingsServices::reset();
        \Modules\Audit\Interfaces\HTTP\AuditServices::reset();

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmpDir);
        $this->restoreDatabaseEnvironment();
        parent::tearDown();
    }

    #[Test]
    public function savePersistsTheSelectedTransport(): void
    {
        $script = $this->executable();

        $response = $this->save([
            MailConfiguration::KEY_TRANSPORT     => 'sendmail',
            MailConfiguration::KEY_FROM          => 'no-reply@example.test',
            MailConfiguration::KEY_SENDMAIL_PATH => $script,
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/mark/settings?tab=mail', $response->getHeader('Location'));

        $repository = $this->repository();
        self::assertSame('sendmail', $repository->get(MailConfiguration::KEY_TRANSPORT));
        self::assertSame('no-reply@example.test', $repository->get(MailConfiguration::KEY_FROM));
        self::assertSame($script, $repository->get(MailConfiguration::KEY_SENDMAIL_PATH));
        self::assertSame(1, $this->auditRows('settings.mail'));
    }

    #[Test]
    public function blankApiKeyKeepsTheStoredOne(): void
    {
        $this->repository()->set(MailConfiguration::KEY_API_KEY, 'secret-key', 'mail', false);
        CardboardServices::reset();

        $response = $this->save([
            MailConfiguration::KEY_TRANSPORT => 'http',
            MailConfiguration::KEY_FROM      => 'no-reply@example.test',
            MailConfiguration::KEY_API_URL   => 'https://mail.example.test/send',
            MailConfiguration::KEY_API_KEY   => '',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('secret-key', $this->repository()->get(MailConfiguration::KEY_API_KEY));
    }

    #[Test]
    public function clearApiKeyRemovesIt(): void
    {
        $this->repository()->set(MailConfiguration::KEY_API_KEY, 'secret-key', 'mail', false);
        CardboardServices::reset();

        $response = $this->save([
            MailConfiguration::KEY_TRANSPORT      => 'http',
            MailConfiguration::KEY_FROM           => 'no-reply@example.test',
            MailConfiguration::KEY_API_URL        => 'https://mail.example.test/send',
            MailConfiguration::KEY_API_KEY        => '',
            // The name of a checkbox, not a credential.
            // @mago-expect lint:no-literal-password
            'clear_mail_api_key'  => 'on',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('', $this->repository()->get(MailConfiguration::KEY_API_KEY));
    }

    #[Test]
    public function invalidSendmailPathIsRefused(): void
    {
        $response = $this->save([
            MailConfiguration::KEY_TRANSPORT     => 'sendmail',
            MailConfiguration::KEY_FROM          => 'no-reply@example.test',
            MailConfiguration::KEY_SENDMAIL_PATH => 'relative/sendmail',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('absolute path', (string) $response->getContent());
        self::assertNull($this->repository()->get(MailConfiguration::KEY_TRANSPORT));
    }

    #[Test]
    public function unknownTransportIsRefused(): void
    {
        $response = $this->save([
            MailConfiguration::KEY_TRANSPORT => 'smtp',
            MailConfiguration::KEY_FROM      => 'no-reply@example.test',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('auto, sendmail, http, disabled', (string) $response->getContent());
        self::assertNull($this->repository()->get(MailConfiguration::KEY_TRANSPORT));
    }

    #[Test]
    public function sendmailWithoutSenderIsRefused(): void
    {
        $response = $this->save([
            MailConfiguration::KEY_TRANSPORT     => 'sendmail',
            MailConfiguration::KEY_FROM          => '',
            MailConfiguration::KEY_SENDMAIL_PATH => $this->executable(),
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Sendmail needs a sender address', (string) $response->getContent());
    }

    #[Test]
    public function invalidHttpEndpointIsRefused(): void
    {
        $response = $this->save([
            MailConfiguration::KEY_TRANSPORT => 'http',
            MailConfiguration::KEY_FROM      => 'no-reply@example.test',
            MailConfiguration::KEY_API_URL   => 'ftp://mail.example.test/send',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('absolute http or https URL', (string) $response->getContent());
    }

    #[Test]
    public function testMailUsesTheSavedSendmailTransport(): void
    {
        $script = $this->capturingExecutable();

        $this->repository()->set(MailConfiguration::KEY_TRANSPORT, 'sendmail', 'mail', false);
        $this->repository()->set(MailConfiguration::KEY_FROM, 'no-reply@example.test', 'mail', false);
        $this->repository()->set(MailConfiguration::KEY_SENDMAIL_PATH, $script, 'mail', false);
        CardboardServices::reset();
        SettingsServices::reset();

        $response = $this->sendTest('ops@example.test');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'Test message accepted by Sendmail (local MTA) for ops@example.test.',
            (string) $response->getContent(),
        );
        self::assertSame(1, $this->auditRows('settings.mail.test'));

        $argv = (string) file_get_contents($this->tmpDir . '/argv.txt');
        self::assertStringContainsString('-t', $argv);
        self::assertStringContainsString('-i', $argv);
        self::assertStringContainsString('no-reply@example.test', $argv);

        $message = (string) file_get_contents($this->tmpDir . '/message.txt');
        self::assertStringContainsString('To: ops@example.test', $message);
        self::assertStringContainsString('Subject: Test mail from the Mark panel', $message);
        self::assertStringContainsString('If you received it, mail delivery works.', $message);
    }

    #[Test]
    public function testMailRefusesAnInvalidRecipient(): void
    {
        $response = $this->sendTest('not-an-address');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Enter a valid recipient address', (string) $response->getContent());
        self::assertSame(0, $this->auditRows('settings.mail.test'));
    }

    #[Test]
    public function testMailReportsADisabledTransport(): void
    {
        $this->repository()->set(MailConfiguration::KEY_TRANSPORT, 'disabled', 'mail', false);
        CardboardServices::reset();
        SettingsServices::reset();

        $response = $this->sendTest('ops@example.test');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'Mail delivery is disabled in the saved settings. The test message was not sent.',
            (string) $response->getContent(),
        );
        self::assertStringNotContainsString('storage/logs/app.log', (string) $response->getContent());
        self::assertSame(0, $this->auditRows('settings.mail.test'));
    }

    #[Test]
    public function testMailExplainsThatNoTransportIsConfigured(): void
    {
        $response = $this->sendTest('ops@example.test');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'No mail transport is configured. Choose Sendmail or HTTP in the Mail tab',
            (string) $response->getContent(),
        );
        self::assertSame(0, $this->auditRows('settings.mail.test'));
    }

    /**
     * @param array<string, string> $body
     */
    private function save(array $body): \Modules\Core\Infrastructure\Http\Response
    {
        return (new SettingsAction(self::router()))->saveMail(
            Request::create('POST', '/mark/settings/mail', [], $body),
        );
    }

    private function sendTest(string $to): \Modules\Core\Infrastructure\Http\Response
    {
        return (new SettingsAction(self::router()))->sendTestMail(
            Request::create('POST', '/mark/settings/mail/test', [], ['mail_test_to' => $to]),
        );
    }

    private function repository(): SettingsRepository
    {
        return new SettingsRepository(DatabaseConnectionManager::instance()->connect('config'));
    }

    private function auditRows(string $action): int
    {
        $stmt = $this->pdoAudit()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $stmt->execute([':action' => $action]);

        return (int) $stmt->fetchColumn();
    }

    private function pdoAudit(): \PDO
    {
        return new \PDO('sqlite:' . $this->tmpDir . '/audit.db');
    }

    private function executable(): string
    {
        $file = $this->tmpDir . '/sendmail-probe';
        file_put_contents($file, "#!/bin/sh\nexit 0\n");
        chmod($file, 0o755);

        return $file;
    }

    private function capturingExecutable(): string
    {
        $file = $this->tmpDir . '/sendmail-capture';
        $argv = escapeshellarg($this->tmpDir . '/argv.txt');
        $message = escapeshellarg($this->tmpDir . '/message.txt');
        file_put_contents($file, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . $argv . "\ncat > " . $message . "\nexit 0\n");
        chmod($file, 0o755);

        return $file;
    }

    private static function router(): RouterInterface
    {
        return new class implements RouterInterface {
            public function match(Request $request): ?array
            {
                return null;
            }

            public function url(string $name, array $params = []): string
            {
                return 'mark.settings' === $name ? '/mark/settings' : '/route/' . $name;
            }
        };
    }

    private static function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }

}
