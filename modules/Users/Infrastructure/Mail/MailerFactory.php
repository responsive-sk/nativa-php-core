<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Settings\Application\Mail\MailConfiguration;
use Modules\Settings\Application\Mail\MailTransport;
use Modules\Settings\Interfaces\HTTP\SettingsServices;
use Modules\Users\Domain\Contracts\Mailer;

/**
 * MailerFactory — the ONE place that decides how a message leaves this tree.
 *
 * The decision itself lives in `Modules\Settings\Application\Mail\MailConfiguration`:
 * the operator's stored choice first, the `MAIL_*` environment second, the
 * built-in default last. This class only turns that answer into an
 * implementation.
 *
 * The three implementations keep their old contract:
 *
 *   - the development log only for a development-named environment under
 *     `auto`, or when an operator explicitly selected it (never offered in the
 *     form);
 *   - sendmail through the configured local binary;
 *   - HTTP through the documented JSON endpoint;
 *   - the null mailer, which is a real answer for a deployment with no
 *     transport at all.
 *
 * A half configured transport is logged by name, never guessed at: sending
 * through an endpoint with no sender address is refused by most providers with
 * a message an operator will never see in this log.
 */
final class MailerFactory
{
    /**
     * The mailer this deployment delivers with.
     */
    public static function create(): Mailer
    {
        $configuration = self::configuration();

        if (null !== $configuration->problem) {
            error_log('[mailer] ' . $configuration->problem);
        }

        return match ($configuration->resolved) {
            MailTransport::Log      => new DevLogMailer(AppPaths::instance()->logs('app.log')),
            MailTransport::Sendmail => new SendmailMailer($configuration->sendmailPath, $configuration->from),
            MailTransport::Http     => new HttpMailer(
                $configuration->apiUrl,
                $configuration->apiKey,
                $configuration->from,
            ),
            default => new NullMailer(),
        };
    }

    /**
     * The configured HTTP transport, or null when this deployment did not
     * resolve to HTTP.
     *
     * Kept because existing callers and tests ask this narrower question. The
     * answer comes from the same configuration as `create()`, so a stored
     * sendmail choice cannot leave this method claiming HTTP is active.
     */
    public static function http(): ?HttpMailer
    {
        $configuration = self::configuration();

        if (MailTransport::Http !== $configuration->resolved) {
            return null;
        }

        return new HttpMailer($configuration->apiUrl, $configuration->apiKey, $configuration->from);
    }

    /**
     * Whether `create()` returns the dev-log implementation.
     */
    public static function writesToLog(): bool
    {
        return MailTransport::Log === self::configuration()->resolved;
    }

    /**
     * Resolve the one configuration every mailer asks.
     *
     * A missing or unmigrated settings table degrades to the environment,
     * which is the state a fresh checkout already has. The catch keeps a
     * settings outage from turning a mail send into a 500.
     */
    public static function configuration(): MailConfiguration
    {
        try {
            $stored = SettingsServices::settings()->all();
        } catch (\Throwable $exception) {
            error_log(
                '[mailer] mail settings unavailable, falling back to MAIL_* environment: '
                . $exception->getMessage(),
            );
            $stored = [];
        }

        return MailConfiguration::fromStored(is_array($stored) ? $stored : []);
    }
}
