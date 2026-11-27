<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Users\Domain\Contracts\Mailer;
use Modules\Users\Domain\Contracts\PasswordResetMailer;

/**
 * MailerPasswordResetMailer — a reset link carried by the generic `Mailer`.
 *
 * Sendmail is a message transport, not a reset feature. This adapter builds
 * the one reset message and hands it to the transport the operator configured,
 * so the sendmail and HTTP paths share a subject, a link and an expiry
 * sentence.
 *
 * The port never throws. A refused message is logged with the address and
 * never with the token; the flow keeps answering the same neutral page either
 * way.
 */
final readonly class MailerPasswordResetMailer implements PasswordResetMailer
{
    public function __construct(
        private Mailer $mailer,
        private string $baseUrl,
    ) {
    }

    public function sendResetLink(
        string $email,
        #[\SensitiveParameter] string $token,
        int $ttlHours,
    ): void {
        $link = PasswordResetMessage::link($this->baseUrl, $token);
        $sent = $this->mailer->send(
            $email,
            PasswordResetMessage::SUBJECT,
            PasswordResetMessage::text($link, $ttlHours),
        );

        if (!$sent) {
            error_log(sprintf(
                '[mailer] reset link for %s was refused by the configured transport',
                $email,
            ));
        }
    }
}
