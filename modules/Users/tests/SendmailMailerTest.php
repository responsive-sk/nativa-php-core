<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Settings\Application\Mail\MailConfiguration;
use Modules\Users\Infrastructure\Mail\SendmailMailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SendmailMailerTest — the transport a server with no SMTP relay actually has.
 *
 * The pipe is injected, so the suite asserts the argv, headers and body
 * without executing a real MTA. The claims that matter:
 *
 * 1. the binary receives `-t -i -f <from>` and the message on stdin;
 * 2. a non-zero exit is a refused send, logged by binary and status, and the
 *    body is never parked in the log;
 * 3. CR or LF cannot add a header through an address or a subject.
 */
final class SendmailMailerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Users')) {
            $this->markTestSkipped('Users is not installed.');
        }
    }

    #[Test]
    public function itPipesTheMessageToTheConfiguredBinary(): void
    {
        $seen = [];

        $mailer = new SendmailMailer(
            MailConfiguration::DEFAULT_SENDMAIL_PATH,
            'no-reply@example.test',
            static function (array $command, string $message) use (&$seen): array {
                $seen = ['command' => $command, 'message' => $message];

                return ['exit' => 0];
            },
        );

        self::assertTrue($mailer->send('owner@example.test', 'A new comment', "First line.\nSecond line."));

        self::assertSame(
            [MailConfiguration::DEFAULT_SENDMAIL_PATH, '-t', '-i', '-f', 'no-reply@example.test'],
            $seen['command'] ?? null,
        );

        $message = (string) ($seen['message'] ?? '');

        self::assertStringContainsString('From: no-reply@example.test', $message);
        self::assertStringContainsString('To: owner@example.test', $message);
        self::assertStringContainsString('Subject: A new comment', $message);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $message);
        self::assertStringContainsString("First line.\nSecond line.", $message);
    }

    #[Test]
    public function aNonZeroExitIsReportedAsNotDelivered(): void
    {
        $logFile = $this->logFile();
        $before = ini_get('error_log');
        // @mago-expect lint:no-ini-set
        ini_set('error_log', $logFile);

        try {
            $mailer = new SendmailMailer(
                MailConfiguration::DEFAULT_SENDMAIL_PATH,
                'no-reply@example.test',
                static fn (array $command, string $message): array => ['exit' => 75],
            );

            self::assertFalse($mailer->send('owner@example.test', 'Hello', 'The body.'));
        } finally {
            // @mago-expect lint:no-ini-set
            ini_set('error_log', false === $before ? '' : $before);
        }

        $log = (string) file_get_contents($logFile);

        self::assertStringContainsString(MailConfiguration::DEFAULT_SENDMAIL_PATH, $log);
        self::assertStringContainsString('75', $log);
        self::assertStringNotContainsString('The body.', $log, 'the message must not be parked in the log');
    }

    #[Test]
    public function itRefusesHeaderInjectionInTheRecipient(): void
    {
        $called = false;

        $mailer = new SendmailMailer(
            MailConfiguration::DEFAULT_SENDMAIL_PATH,
            'no-reply@example.test',
            static function (array $command, string $message) use (&$called): array {
                $called = true;

                return ['exit' => 0];
            },
        );

        self::assertFalse($mailer->send("victim@example.test\r\nBcc: thief@example.test", 'Hello', 'Body'));
        self::assertFalse($called, 'the pipe must not run for an address that would add a header');
    }

    #[Test]
    public function itStripsHeaderInjectionFromTheSubject(): void
    {
        $seen = '';

        $mailer = new SendmailMailer(
            MailConfiguration::DEFAULT_SENDMAIL_PATH,
            'no-reply@example.test',
            static function (array $command, string $message) use (&$seen): array {
                $seen = $message;

                return ['exit' => 0];
            },
        );

        self::assertTrue($mailer->send('owner@example.test', "Hello\r\nBcc: thief@example.test", 'Body'));
        self::assertStringNotContainsString("\r\nBcc:", $seen, 'the line break must not start a new header');
        self::assertStringContainsString('Subject: Hello Bcc: thief@example.test', $seen);
    }

    #[Test]
    public function aMissingBinaryIsRefusedWithoutRunningThePipe(): void
    {
        $called = false;

        $mailer = new SendmailMailer(
            '',
            'no-reply@example.test',
            static function (array $command, string $message) use (&$called): array {
                $called = true;

                return ['exit' => 0];
            },
        );

        self::assertFalse($mailer->send('owner@example.test', 'Hello', 'Body'));
        self::assertFalse($called);
    }

    #[Test]
    public function aHungBinaryIsStoppedAndReportedAsNotDelivered(): void
    {
        $logFile = $this->logFile();
        $binary = $this->slowExecutable(5);
        $before = ini_get('error_log');
        // @mago-expect lint:no-ini-set
        ini_set('error_log', $logFile);

        try {
            $mailer = new SendmailMailer($binary, 'no-reply@example.test', null, 1);
            $started = microtime(true);
            self::assertFalse($mailer->send('owner@example.test', 'Hello', 'The body.'));
            self::assertLessThan(3.0, microtime(true) - $started);
        } finally {
            // @mago-expect lint:no-ini-set
            ini_set('error_log', false === $before ? '' : $before);
            @unlink($binary);
        }

        $log = (string) file_get_contents($logFile);

        self::assertStringContainsString('timed out after 1 second', $log);
        self::assertStringNotContainsString('The body.', $log);
    }

    #[Test]
    public function aPipeThatThrowsIsReportedAsNotDelivered(): void
    {
        $mailer = new SendmailMailer(
            MailConfiguration::DEFAULT_SENDMAIL_PATH,
            'no-reply@example.test',
            static function (): array {
                throw new \RuntimeException('process failed');
            },
        );

        self::assertFalse($mailer->send('owner@example.test', 'Hello', 'Body'));
    }

    private function logFile(): string
    {
        $file = sys_get_temp_dir() . '/nativa-sendmail-log-' . bin2hex(random_bytes(6)) . '.log';
        touch($file);

        return $file;
    }

    private function slowExecutable(int $seconds): string
    {
        $file = sys_get_temp_dir() . '/nativa-sendmail-slow-' . bin2hex(random_bytes(6));
        file_put_contents($file, "#!/bin/sh\nexec sleep " . $seconds . "\n");
        chmod($file, 0o755);

        return $file;
    }
}
