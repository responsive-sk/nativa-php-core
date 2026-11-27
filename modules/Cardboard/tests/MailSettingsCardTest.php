<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\SettingsAction;
use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Settings\Application\Mail\MailConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MailSettingsCardTest — the Mail tab's render half.
 *
 * The two failures this file is built to catch:
 *
 * 1. a stored API key rendered back into the HTML, which turns a settings
 *    screen into a credential display;
 * 2. a form that offers a transport the save path does not accept, which looks
 *    like a save that silently did nothing.
 */
final class MailSettingsCardTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Cardboard')) {
            $this->markTestSkipped('Cardboard is not installed.');
        }
    }

    #[Test]
    public function theMailTabRendersTheTransportChoices(): void
    {
        $stored = [
            MailConfiguration::KEY_TRANSPORT     => 'sendmail',
            MailConfiguration::KEY_FROM          => 'no-reply@example.test',
            MailConfiguration::KEY_SENDMAIL_PATH => MailConfiguration::DEFAULT_SENDMAIL_PATH,
            MailConfiguration::KEY_API_KEY       => 'secret-key',
        ];

        $html = SettingsAction::renderMailCard(
            MailConfiguration::fromStored($stored),
            $stored,
            '<input type="hidden" name="_token" value="csrf-token">',
            '/mark/settings/mail',
        );

        foreach (['auto', 'sendmail', 'http', 'disabled'] as $transport) {
            self::assertStringContainsString('value="' . $transport . '"', $html);
        }

        self::assertStringContainsString('action="/mark/settings/mail"', $html);
        self::assertStringContainsString('name="mail_from"', $html);
        self::assertStringContainsString('name="mail_sendmail_path"', $html);
        self::assertStringContainsString('name="mail_api_url"', $html);
        self::assertStringContainsString('name="mail_api_key"', $html);
        self::assertStringContainsString('name="clear_mail_api_key"', $html);
        self::assertStringContainsString('csrf-token', $html);
        self::assertStringContainsString('Sendmail (local MTA)', $html);
        self::assertStringContainsString('Configured', $html);
    }

    #[Test]
    public function theStoredApiKeyIsNeverRendered(): void
    {
        $stored = [
            MailConfiguration::KEY_TRANSPORT => 'http',
            MailConfiguration::KEY_FROM      => 'no-reply@example.test',
            MailConfiguration::KEY_API_URL   => 'https://mail.example.test/send',
            MailConfiguration::KEY_API_KEY   => 'secret-key',
        ];

        $html = SettingsAction::renderMailCard(
            MailConfiguration::fromStored($stored),
            $stored,
            '<input type="hidden" name="_token" value="csrf-token">',
            '/mark/settings/mail',
        );

        self::assertStringNotContainsString('secret-key', $html);
        self::assertStringContainsString('Key: Configured', $html);
    }

    #[Test]
    public function theMailTabIsInTheSettingsBar(): void
    {
        $html = CardboardLayout::settingsTabs(
            static fn (string $name): string => 'mark.settings' === $name ? '/mark/settings' : '/route/' . $name,
            'mail',
        );

        self::assertStringContainsString('href="/mark/settings?tab=mail" data-tab="mail"', $html);
        self::assertStringContainsString('m-tabs__btn--active', $html);
    }
}
