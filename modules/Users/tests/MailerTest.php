<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Core\Infrastructure\Env;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Application\Mail\MailConfiguration;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;
use Modules\Settings\Interfaces\HTTP\SettingsServices;
use Modules\Users\Domain\Contracts\Mailer;
use Modules\Users\Infrastructure\Mail\DevLogMailer;
use Modules\Users\Infrastructure\Mail\HttpMailer;
use Modules\Users\Infrastructure\Mail\MailerFactory;
use Modules\Users\Infrastructure\Mail\NullMailer;
use Modules\Users\Infrastructure\Mail\SendmailMailer;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * MailerTest — the kernel's mail port: one message, three transports, one
 * environment rule.
 *
 * The claims that matter, in the order they can go wrong:
 *
 * 1. **Which transport an environment gets.** A development-named environment
 *    writes to the app log; anything else never does. This is the same rule the
 *    reset link follows (`PasswordResetMailerFactory` now asks this test's
 *    subject), and the whitelist — not "not production" — is the part that
 *    keeps `staging` and a typo'd `prodction` from writing user content to disk.
 * 2. **What the HTTP transport actually sends.** The documented JSON shape, the
 *    bearer header only when a key exists, and a boolean instead of an
 *    exception. Asserted with an injected sender: no network egress.
 * 3. **What the dev transport writes**, on a file the test owns — the whole
 *    message, because "did the notification reach the admin?" is the question
 *    that transport exists to answer.
 * 4. **That nothing falls back to a log file when delivery fails.** `send()`
 *    returns false and a line names the endpoint; the message body is not
 *    parked anywhere.
 */
final class MailerTest extends TestCase
{
    use SqliteDatabase;

    private string $tmpDir = '';

    /** @var array<string, string|null> */
    private array $envBefore = [];


    protected function setUp(): void
    {
        parent::setUp();

        // Kernel-only checkouts carry no Users slice: the kernel suite runs the
        // module tests too, and a missing class must skip the file rather than
        // fail it. Same guard as PasswordResetMailerTest.
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Users')) {
            $this->markTestSkipped('Users is not installed.');
        }

        $this->pinSqliteDatabase();
        $this->tmpDir = sys_get_temp_dir() . '/nativa-mailer-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        SettingsServices::reset();
        (new SettingsDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/config.db')))
            ->run($this->quietOutput());

        foreach (self::envKeys() as $key) {
            $value = getenv($key);
            $this->envBefore[$key] = false === $value ? null : $value;
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();
        SettingsServices::reset();
        DatabaseConnectionManager::resetInstance();

        foreach (self::envKeys() as $key) {
            $before = $this->envBefore[$key] ?? null;
            putenv(null === $before ? $key : $key . '=' . $before);
        }

        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * The keys the transports and the factory read. A method rather than a
     * constant: this file is measured by the declaration ratchet, and a list of
     * environment variable NAMES is not a value the tree owns anywhere.
     *
     * @return list<string>
     */
    private static function envKeys(): array
    {
        return [
            'APP_ENV',
            MailConfiguration::ENV_API_URL,
            MailConfiguration::ENV_API_KEY,
            MailConfiguration::ENV_FROM,
            MailConfiguration::ENV_SENDMAIL_PATH,
            'LOG_LEVEL',
        ];
    }

    #[Test]
    public function aDevelopmentEnvironmentGetsTheLogTransport(): void
    {
        self::appEnv('development');

        self::assertTrue(MailerFactory::writesToLog());
        self::assertInstanceOf(DevLogMailer::class, MailerFactory::create());
    }

    /**
     * The other half of the rule, and the one a "not production" test would
     * miss: an environment nobody classified is not development.
     */
    #[Test]
    public function onlyNamesThatSayDevelopmentKeepTheLogTransport(): void
    {
        foreach (['production' => false, 'staging' => false, 'prodction' => false, 'development' => true, 'local' => true] as $name => $expected) {
            self::appEnv($name);

            self::assertSame(
                $expected,
                MailerFactory::writesToLog(),
                sprintf('APP_ENV=%s resolved the wrong transport', $name),
            );
        }

        self::appEnv(null);
        self::assertTrue(MailerFactory::writesToLog(), 'an unset APP_ENV is this tree\'s development default');
    }

    #[Test]
    public function aProductionDeploymentWithoutATransportGetsTheRealNullAnswer(): void
    {
        self::appEnv('production');

        self::assertInstanceOf(NullMailer::class, MailerFactory::create());
        self::assertNull(MailerFactory::http());
    }

    #[Test]
    public function aConfiguredProductionDeploymentGetsTheHttpTransport(): void
    {
        self::appEnv('production');
        putenv('MAIL_API_URL=https://mail.example.test/send');
        putenv('MAIL_FROM=no-reply@example.test');

        self::assertInstanceOf(HttpMailer::class, MailerFactory::create());
    }

    /**
     * The stored choice, not the environment, is what a deployment with only
     * sendmail needs. It also proves an explicit choice wins in development,
     * where `auto` would otherwise write to the log.
     */
    #[Test]
    public function aStoredSendmailChoiceIsTheMailerTheFactoryReturns(): void
    {
        self::appEnv('development');

        $settings = new SettingsRepository(DatabaseConnectionManager::instance()->connect('config'));
        $settings->set(MailConfiguration::KEY_TRANSPORT, 'sendmail', 'mail', false);
        $settings->set(MailConfiguration::KEY_FROM, 'no-reply@example.test', 'mail', false);
        $settings->set(MailConfiguration::KEY_SENDMAIL_PATH, MailConfiguration::DEFAULT_SENDMAIL_PATH, 'mail', false);
        SettingsServices::reset();

        self::assertFalse(MailerFactory::writesToLog());
        self::assertInstanceOf(SendmailMailer::class, MailerFactory::create());
    }

    /**
     * A settings table that is missing or unmigrated must not make every mail
     * send throw. The environment fallback is the state a fresh checkout has.
     */
    #[Test]
    public function anUnavailableSettingsTableFallsBackToTheEnvironment(): void
    {
        self::appEnv('production');
        putenv('DB_CONFIG=' . $this->tmpDir . '/missing.db');
        putenv('MAIL_API_URL=https://mail.example.test/send');
        putenv('MAIL_FROM=no-reply@example.test');
        DatabaseConnectionManager::resetInstance();
        SettingsServices::reset();

        self::assertInstanceOf(HttpMailer::class, MailerFactory::create());
    }

    /**
     * Half a transport is refused by name: the endpoint without a sender is the
     * shape most providers reject, and the log has to say which key is missing.
     */
    #[Test]
    public function aHalfConfiguredTransportIsRefusedAndNamed(): void
    {
        self::appEnv('production');
        putenv('MAIL_API_URL=https://mail.example.test/send');

        $logFile = $this->tmpDir . '/factory.log';
        $before = ini_get('error_log');
        // Routing `error_log()` to a probe file is the only way to assert what
        // the factory wrote; the previous value is restored in the finally.
        // @mago-expect lint:no-ini-set
        ini_set('error_log', $logFile);

        try {
            self::assertNull(MailerFactory::http());
            self::assertInstanceOf(NullMailer::class, MailerFactory::create());
        } finally {
            // @mago-expect lint:no-ini-set
            ini_set('error_log', false === $before ? '' : $before);
        }

        self::assertStringContainsString(
            'missing MAIL_FROM',
            (string) file_get_contents($logFile),
            'the refusal must name the key, or "mail is configured, why is nothing arriving" is unanswerable',
        );
    }

    #[Test]
    public function theHttpTransportPostsTheDocumentedShape(): void
    {
        $seen = [];

        $mailer = new HttpMailer(
            'https://mail.example.test/send',
            'secret-key',
            'no-reply@example.test',
            static function (string $endpoint, array $headers, string $body) use (&$seen): array {
                $seen = ['endpoint' => $endpoint, 'headers' => $headers, 'body' => $body];

                return ['status' => 202, 'body' => '{}'];
            },
        );

        self::assertTrue($mailer->send('owner@example.test', 'Hello', 'The body.'));

        self::assertSame('https://mail.example.test/send', $seen['endpoint'] ?? null);
        self::assertContains('Authorization: Bearer secret-key', $seen['headers'] ?? []);
        self::assertContains('Content-Type: application/json', $seen['headers'] ?? []);

        /** @var array{from: string, to: string, subject: string, text: string} $payload */
        $payload = json_decode((string) ($seen['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('no-reply@example.test', $payload['from']);
        self::assertSame('owner@example.test', $payload['to']);
        self::assertSame('Hello', $payload['subject']);
        self::assertSame('The body.', $payload['text']);
    }

    #[Test]
    public function theBearerHeaderIsOmittedWhenNoKeyIsConfigured(): void
    {
        $seen = [];

        $mailer = new HttpMailer(
            'https://relay.example.test/send',
            '',
            'no-reply@example.test',
            static function (string $endpoint, array $headers, string $body) use (&$seen): array {
                $seen = $headers;

                return ['status' => 200, 'body' => '{}'];
            },
        );

        $mailer->send('owner@example.test', 'Hello', 'Body');

        foreach ($seen as $header) {
            self::assertStringNotContainsString(
                'Authorization:',
                $header,
                'a bearer header with no key is a broken credential, not a default',
            );
        }
    }

    /**
     * A refused connection is a false and a log line — never an exception into
     * the request or job that asked for the message.
     */
    #[Test]
    public function aTransportThatThrowsIsReportedAsNotDelivered(): void
    {
        $logFile = $this->tmpDir . '/transport.log';
        $before = ini_get('error_log');
        // Routing `error_log()` to a probe file is the only way to assert what
        // the factory wrote; the previous value is restored in the finally.
        // @mago-expect lint:no-ini-set
        ini_set('error_log', $logFile);

        try {
            $mailer = new HttpMailer(
                'https://mail.example.test/send',
                'secret-key',
                'no-reply@example.test',
                static function (): array {
                    throw new \RuntimeException('connection refused');
                },
            );

            self::assertFalse($mailer->send('owner@example.test', 'Hello', 'Body'));
        } finally {
            // @mago-expect lint:no-ini-set
            ini_set('error_log', false === $before ? '' : $before);
        }

        self::assertStringContainsString('could not be delivered', (string) file_get_contents($logFile));
        self::assertStringNotContainsString('Body', (string) file_get_contents($logFile), 'the message must not be parked in the error log');
    }

    #[Test]
    public function theDevTransportWritesTheWholeMessageToTheFileItWasGiven(): void
    {
        $file = $this->tmpDir . '/dev.log';

        $mailer = new DevLogMailer($file);

        self::assertTrue($mailer->send('owner@example.test', 'A new comment', "First line.\nSecond line."));

        $written = (string) file_get_contents($file);

        self::assertStringContainsString('[mailer:dev] To: owner@example.test', $written);
        self::assertStringContainsString("Subject: A new comment\n\nFirst line.\nSecond line.", $written);
    }

    /**
     * The port exists so a module can ask for a mail; this test is the only
     * place that says a caller cannot depend on anything narrower.
     */
    #[Test]
    public function thePortIsWhatTheFactoryReturns(): void
    {
        self::appEnv('production');

        self::assertInstanceOf(Mailer::class, MailerFactory::create());
    }

    /**
     * Set APP_ENV on every layer the environment reads.
     *
     * `Env::get()` reads its cache, then `$_ENV`, then `$_SERVER`, then the
     * process environment — and a local `.env` has already filled the first
     * three. A test that only calls `putenv()` therefore keeps seeing the
     * deployment's value, which is how four mailer assertions went red on a
     * machine with a `.env` while CI, which has none, stayed green.
     */
    private function quietOutput(): CliOutput
    {
        return new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }

    private static function appEnv(?string $value): void
    {
        Env::reset();

        if (null === $value) {
            putenv('APP_ENV');
            unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);

            return;
        }

        putenv('APP_ENV=' . $value);
        $_ENV['APP_ENV'] = $value;
        $_SERVER['APP_ENV'] = $value;
    }

}
