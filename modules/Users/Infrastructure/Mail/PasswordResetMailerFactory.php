<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Users\Domain\Contracts\PasswordResetMailer;

/**
 * PasswordResetMailerFactory — the ONE place that decides how a reset link
 * travels, asked once by the composition root (`AuthServices::resets()`).
 *
 * Two implementations, one rule. The rule is a WHITELIST of environments that
 * may write the token to a log, not "everything that is not production":
 * `staging`, `qa` or a typo'd `prodction` reach the null mailer too, and only
 * the environments that are development by name keep the dev transport. Adding
 * a third environment is a deliberate edit here rather than a consequence of
 * how someone spelled a variable.
 *
 * The default when `APP_ENV` is unset is `development`, which is this tree's
 * existing reading of an unset `APP_ENV` (see `SecurityHeadersMiddleware`'s
 * HSTS gate) and what makes a fresh checkout work without a `.env`. A
 * deployment states its environment — `.env.example` ships `APP_ENV` for exactly
 * that — and only an explicit development entry in `$DEV_ENVIRONMENTS` writes a
 * credential to disk.
 *
 * Same shape as `StorageProviderFactory` in Media: one resolution point, the
 * callers ask for the interface and never for a class name.
 */
final class PasswordResetMailerFactory
{
    /**
     * Environments whose missing mail transport is covered by the dev log.
     *
     * Not the negation of `production` on purpose: an unrecognised name is an
     * environment nobody classified, and an unclassified environment must not
     * be the one that keeps a plaintext token on disk.
     */
    private const array DEV_ENVIRONMENTS = ['development', 'local', 'testing', 'test', 'dev'];

    /**
     * The mailer this environment delivers with.
     */
    public static function create(): PasswordResetMailer
    {
        if (self::writesToLog()) {
            return new DevLogPasswordResetMailer(AppPaths::instance()->logs('app.log'));
        }

        return new NullPasswordResetMailer();
    }

    /**
     * Whether `create()` returns the dev-log implementation.
     *
     * Read by the surfaces that have to tell a developer where the link went —
     * the success message on `/forgot-password` names `storage/logs/app.log`
     * only where that file is where the link actually is. It is never a
     * security decision: `create()` is what gates delivery, and it asks the
     * same method.
     */
    public static function writesToLog(): bool
    {
        return in_array(
            strtolower(Env::get('APP_ENV', 'development') ?? 'development'),
            self::DEV_ENVIRONMENTS,
            true,
        );
    }
}
