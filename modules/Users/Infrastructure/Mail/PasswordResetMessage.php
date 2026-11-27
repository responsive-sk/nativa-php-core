<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

/**
 * PasswordResetMessage — the one copy of the reset subject, link and body.
 *
 * HTTP and sendmail reset mailers carry the same message; only the transport
 * differs. A second copy would let one transport's expiry sentence or path
 * drift from the other's, which is the kind of difference a reader notices
 * only when the link does not work.
 */
final class PasswordResetMessage
{
    public const string SUBJECT = 'Reset your password';

    /** The path `PasswordResetAction` serves. */
    public const string PATH = '/reset-password';

    /**
     * The clickable link, built from `APP_URL` and never from a request.
     *
     * The port carries no Request by contract, and a link built from a Host
     * header the caller controls is a phishing surface, not a convenience.
     */
    public static function link(string $baseUrl, #[\SensitiveParameter] string $token): string
    {
        return rtrim($baseUrl, '/') . self::PATH . '?token=' . $token;
    }

    public static function text(string $link, int $ttlHours): string
    {
        return sprintf(
            "Somebody asked to reset the password for this address.\n\n%s\n\n"
            . 'The link works once and expires in %d hour(s). If this was not you, ignore this message, nothing has changed.',
            $link,
            $ttlHours,
        );
    }
}
