<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Core\Infrastructure\Env;
use Modules\Settings\Application\Mail\MailConfiguration;
use Modules\Settings\Application\Mail\MailTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MailConfigurationTest — one answer to "how does mail leave this site?".
 *
 * The claims that matter:
 *
 * 1. `auto` is the behaviour this tree already had, so an upgrade changes
 *    nothing: the dev log in a development-named environment, HTTP when the
 *    endpoint and sender exist, and no transport when neither does.
 * 2. An explicit stored choice wins, including sendmail over the development
 *    log. That is the deployment this screen exists for.
 * 3. A half configured transport is a named refusal, not a guess.
 * 4. A stored value wins over its environment fallback.
 */
final class MailConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users', 'Settings'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        foreach (self::envKeys() as $key) {
            $this->previous[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        Env::reset();
    }

    protected function tearDown(): void
    {
        foreach (self::envKeys() as $key) {
            $before = $this->previous[$key] ?? false;
            false === $before ? putenv($key) : putenv($key . '=' . $before);
        }

        Env::reset();

        parent::tearDown();
    }

    #[Test]
    public function autoKeepsTheDevelopmentLog(): void
    {
        self::appEnv('development');

        $configuration = MailConfiguration::fromStored([]);

        self::assertSame(MailTransport::Auto, $configuration->configured);
        self::assertSame(MailTransport::Log, $configuration->resolved);
        self::assertNull($configuration->problem);
    }

    #[Test]
    public function autoUsesHttpWhenTheEndpointAndSenderExist(): void
    {
        self::appEnv('production');
        putenv('MAIL_API_URL=https://mail.example.test/send');
        putenv('MAIL_FROM=no-reply@example.test');

        $configuration = MailConfiguration::fromStored([]);

        self::assertSame(MailTransport::Http, $configuration->resolved);
        self::assertSame('https://mail.example.test/send', $configuration->apiUrl);
        self::assertSame('no-reply@example.test', $configuration->from);
    }

    #[Test]
    public function autoWithoutATransportIsSilentlyDisabled(): void
    {
        self::appEnv('production');

        $configuration = MailConfiguration::fromStored([]);

        self::assertSame(MailTransport::Disabled, $configuration->resolved);
        self::assertNull($configuration->problem, 'Both keys empty is the ordinary no-mail state.');
    }

    #[Test]
    public function explicitSendmailOverridesTheDevelopmentLog(): void
    {
        self::appEnv('development');

        $configuration = MailConfiguration::fromStored([
            MailConfiguration::KEY_TRANSPORT => 'sendmail',
            MailConfiguration::KEY_FROM      => 'no-reply@example.test',
            MailConfiguration::KEY_SENDMAIL_PATH => MailConfiguration::DEFAULT_SENDMAIL_PATH,
        ]);

        self::assertSame(MailTransport::Sendmail, $configuration->configured);
        self::assertSame(MailTransport::Sendmail, $configuration->resolved);
        self::assertSame(MailConfiguration::DEFAULT_SENDMAIL_PATH, $configuration->sendmailPath);
    }

    #[Test]
    public function explicitDisabledNeverFallsBack(): void
    {
        self::appEnv('development');
        putenv('MAIL_API_URL=https://mail.example.test/send');
        putenv('MAIL_FROM=no-reply@example.test');

        $configuration = MailConfiguration::fromStored([
            MailConfiguration::KEY_TRANSPORT => 'disabled',
        ]);

        self::assertSame(MailTransport::Disabled, $configuration->resolved);
        self::assertSame('https://mail.example.test/send', $configuration->apiUrl);
    }

    #[Test]
    public function storedValuesWinOverTheEnvironmentFallback(): void
    {
        self::appEnv('production');
        putenv('MAIL_FROM=env@example.test');
        putenv('MAIL_API_URL=https://env.example.test/send');

        $configuration = MailConfiguration::fromStored([
            MailConfiguration::KEY_TRANSPORT => 'http',
            MailConfiguration::KEY_FROM      => 'settings@example.test',
            MailConfiguration::KEY_API_URL   => 'https://settings.example.test/send',
        ]);

        self::assertSame('settings@example.test', $configuration->from);
        self::assertSame('https://settings.example.test/send', $configuration->apiUrl);
    }

    #[Test]
    public function aHalfConfiguredHttpTransportIsRefusedByName(): void
    {
        self::appEnv('production');
        putenv('MAIL_API_URL=https://mail.example.test/send');

        $configuration = MailConfiguration::fromStored([]);

        self::assertSame(MailTransport::Disabled, $configuration->resolved);
        self::assertNotNull($configuration->problem);
        self::assertStringContainsString(MailConfiguration::ENV_FROM, (string) $configuration->problem);
    }

    #[Test]
    public function sendmailWithoutASenderIsRefusedByName(): void
    {
        self::appEnv('production');

        $configuration = MailConfiguration::fromStored([
            MailConfiguration::KEY_TRANSPORT => 'sendmail',
            MailConfiguration::KEY_SENDMAIL_PATH => MailConfiguration::DEFAULT_SENDMAIL_PATH,
        ]);

        self::assertSame(MailTransport::Disabled, $configuration->resolved);
        self::assertNotNull($configuration->problem);
        self::assertStringContainsString(MailConfiguration::ENV_FROM, (string) $configuration->problem);
    }

    #[Test]
    public function anUnknownStoredTransportFallsBackToAuto(): void
    {
        self::appEnv('development');

        $configuration = MailConfiguration::fromStored([
            MailConfiguration::KEY_TRANSPORT => 'smtp',
        ]);

        self::assertSame(MailTransport::Auto, $configuration->configured);
        self::assertSame(MailTransport::Log, $configuration->resolved);
    }

    /** @return list<string> */
    private static function envKeys(): array
    {
        return [
            'APP_ENV',
            MailConfiguration::ENV_FROM,
            MailConfiguration::ENV_API_URL,
            MailConfiguration::ENV_API_KEY,
            MailConfiguration::ENV_SENDMAIL_PATH,
        ];
    }

    private static function appEnv(string $value): void
    {
        Env::reset();
        putenv('APP_ENV=' . $value);
        $_ENV['APP_ENV'] = $value;
        $_SERVER['APP_ENV'] = $value;
    }
}
