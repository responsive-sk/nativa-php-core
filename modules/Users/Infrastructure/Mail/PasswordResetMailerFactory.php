<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Settings\Application\Mail\MailConfiguration;
use Modules\Settings\Application\Mail\MailTransport;
use Modules\Users\Domain\Contracts\PasswordResetMailer;

/**
 * PasswordResetMailerFactory — the ONE place that decides how a reset link
 * travels, asked once by the composition root (`AuthServices::resets()`).
 *
 * It now shares `MailerFactory::configuration()` with every other message, so
 * a deployment that selects sendmail for notifications also delivers reset
 * links through sendmail. Four implementations, one rule:
 *
 *   - `log` keeps the development log, only where `auto` selected it;
 *   - `sendmail` posts the shared reset message through the local MTA;
 *   - `http` posts the documented JSON contract;
 *   - `disabled` is the null mailer.
 *
 * A reset link additionally needs `APP_URL`: the port has no Request, and a
 * link built from a Host header the caller controls is a phishing surface.
 * Without it the factory refuses the transport and names the missing key
 * instead of guessing a host.
 *
 * The default when `APP_ENV` is unset is `development`, which is this tree's
 * existing reading of an unset `APP_ENV` and what makes a fresh checkout work
 * without a `.env`. A deployment states its environment, and only an explicit
 * development entry in the whitelist writes a credential to disk.
 */
final class PasswordResetMailerFactory
{
    /**
     * The mailer this deployment delivers with.
     */
    public static function create(): PasswordResetMailer
    {
        $configuration = MailerFactory::configuration();

        return match ($configuration->resolved) {
            MailTransport::Log      => new DevLogPasswordResetMailer(AppPaths::instance()->logs('app.log')),
            MailTransport::Sendmail => self::sendmail($configuration),
            MailTransport::Http     => self::httpFrom($configuration) ?? new NullPasswordResetMailer(),
            default                 => new NullPasswordResetMailer(),
        };
    }

    /**
     * The configured HTTP reset transport, or null when this deployment did
     * not resolve to HTTP or has no `APP_URL`.
     *
     * Kept for existing callers and tests that ask this narrower question. It
     * reads the same configuration as `create()`, so a stored sendmail choice
     * cannot leave this method claiming HTTP is active.
     */
    public static function http(): ?HttpPasswordResetMailer
    {
        $configuration = MailerFactory::configuration();

        return MailTransport::Http === $configuration->resolved
            ? self::httpFrom($configuration)
            : null;
    }

    /**
     * Whether `create()` returns the dev-log implementation.
     *
     * Read by the surfaces that tell a developer where the link went. It is
     * never a security decision: `create()` asks the same configuration.
     */
    public static function writesToLog(): bool
    {
        return MailerFactory::writesToLog();
    }

    private static function sendmail(MailConfiguration $configuration): PasswordResetMailer
    {
        $appUrl = self::appUrl();

        if (null === $appUrl) {
            return new NullPasswordResetMailer();
        }

        return new MailerPasswordResetMailer(
            new SendmailMailer($configuration->sendmailPath, $configuration->from),
            $appUrl,
        );
    }

    private static function httpFrom(MailConfiguration $configuration): ?HttpPasswordResetMailer
    {
        $appUrl = self::appUrl();

        if (null === $appUrl) {
            return null;
        }

        return new HttpPasswordResetMailer(
            $configuration->apiUrl,
            $configuration->apiKey,
            $configuration->from,
            $appUrl,
        );
    }

    /**
     * The public base URL, or null after naming the missing key.
     *
     * All three HTTP keys are required together because each one is
     * load-bearing for a link that works: without the endpoint there is
     * nothing to send through, without the sender most providers refuse the
     * message, and without `APP_URL` the link cannot be built at all. The
     * sendmail path needs the first two from the mail configuration and this
     * one from the same place, so both transports obey one rule.
     */
    private static function appUrl(): ?string
    {
        $appUrl = trim((string) (Env::get('APP_URL') ?? ''));

        if ('' !== $appUrl) {
            return $appUrl;
        }

        error_log(
            '[mailer] reset mail transport not configured: missing APP_URL; nothing will be delivered '
            . '(storage/logs/app.log is NOT used as a fallback)',
        );

        return null;
    }
}
