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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * MailSettingsSaveTest — the Mail tab's write half.
 *
 * The save path is where a settings screen becomes trustworthy: a value it
 * accepts must be the value the runtime reads, an API key must survive a blank
 * field, and a refused save must write nothing. The success path is checked
 * through the repository; the refusal path is checked through the real HTML
 * response, so "the error landed on the Mail tab" is not a comment.
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

        $this->tmpDir = sys_get_temp_dir() . '/nativa-mail-settings-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        putenv('DB_AUDIT=' . $this->tmpDir . '/audit.db');
        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();
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

        if ('' === $this->tmpDir) {
            parent::tearDown();

            return;
        }

        putenv('DB_CONFIG');
        putenv('DB_AUDIT');
        putenv('DB_USERS');
        DatabaseConnectionManager::resetInstance();
        CardboardServices::reset();
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

    /**
     * @param array<string, string> $body
     */
    private function save(array $body): \Modules\Core\Infrastructure\Http\Response
    {
        return (new SettingsAction(self::router()))->saveMail(
            Request::create('POST', '/mark/settings/mail', [], $body),
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
