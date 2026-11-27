<?php

declare(strict_types = 1);

namespace Modules\Settings\Application\Mail;

/**
 * MailTransport — the only owner of the words a mail transport may be called.
 *
 * `Log` is resolved, never stored: it is the development transport `auto`
 * chooses, and offering it in the form would let a production operator write
 * message bodies to disk. `storable()` is what the form and the save
 * validation read, so a new case cannot be added to one and forgotten in the
 * other.
 */
enum MailTransport: string
{
    case Auto = 'auto';
    case Sendmail = 'sendmail';
    case Http = 'http';
    case Disabled = 'disabled';

    /** Resolved only; never accepted from a stored value. */
    case Log = 'log';

    /**
     * A stored value, or `Auto` for a missing or unknown one.
     *
     * An unknown row is a corrupt database or a hand edit, not an operator
     * choice, so it degrades to the behaviour an upgrade already had instead of
     * throwing on every request.
     */
    public static function fromStored(mixed $value): self
    {
        if (!is_string($value)) {
            return self::Auto;
        }

        return match (strtolower(trim($value))) {
            self::Sendmail->value => self::Sendmail,
            self::Http->value     => self::Http,
            self::Disabled->value => self::Disabled,
            default               => self::Auto,
        };
    }

    /**
     * The cases an operator may store, in form order.
     *
     * @return list<self>
     */
    public static function storable(): array
    {
        return [self::Auto, self::Sendmail, self::Http, self::Disabled];
    }

    public function label(): string
    {
        return match ($this) {
            self::Auto     => 'Automatic (current behaviour)',
            self::Sendmail => 'Sendmail (local MTA)',
            self::Http     => 'HTTP API',
            self::Disabled => 'Disabled',
            self::Log      => 'Development log',
        };
    }
}
