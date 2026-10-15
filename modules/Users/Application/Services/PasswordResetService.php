<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

use Modules\Security\Application\Services\PasswordHasher;
use Modules\Users\Domain\Contracts\PasswordResetMailer;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Validation\Validation\Validator;

/**
 * PasswordResetService — request token → reset flow.
 *
 * Delivery is not this class's business: it stores the token (hashed), asks
 * the injected `PasswordResetMailer` to carry the link, and answers the same
 * thing whether the address exists or not. WHERE the link may travel is an
 * environment fact resolved once at wiring time — before this port existed the
 * service appended the plaintext token to `storage/logs/app.log` itself, in
 * every environment, so a production deployment without a mail transport kept
 * a working credential for a live account on disk (audit finding #1).
 */
final readonly class PasswordResetService
{
    private const int TOKEN_TTL_HOURS = 1;

    public function __construct(
        private \PDO $pdo,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private PasswordResetMailer $mailer,
    ) {
    }

    /**
     * Create a reset token for an email (always succeeds to avoid user
     * enumeration) and hand the link to the configured mailer.
     *
     * The raw token leaves this method only through the port — it is not
     * returned, so no caller can decide to record it somewhere. A credential
     * that is only ever handed to a transport cannot end up in a second place
     * by accident, which is exactly how it ended up in the log before.
     */
    public function requestReset(string $email): void
    {
        $email = strtolower(trim($email));

        $token = bin2hex(random_bytes(32));

        // Invalidate previous unused tokens for this email.
        $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE email = :email AND used_at IS NULL')
            ->execute([':email' => $email]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (id, email, token, expires_at, created_at)
             VALUES (:id, :email, :token, datetime(\'now\', :ttl), CURRENT_TIMESTAMP)',
        );
        $stmt->execute([
            ':id'    => bin2hex(random_bytes(16)),
            ':email' => $email,
            ':token' => hash('sha256', $token),
            ':ttl'   => '+' . self::TOKEN_TTL_HOURS . ' hours',
        ]);

        // The mailer decides what happens to the link: the dev transport appends
        // it to storage/logs/app.log, the null transport drops it. Neither path
        // changes the response, and neither throws — see the port's docblock.
        $this->mailer->sendResetLink($email, $token, self::TOKEN_TTL_HOURS);
    }

    /**
     * Validate + consume a reset token and update the password.
     *
     * @return list<string> errors (empty on success)
     */
    public function reset(string $token, string $password, string $confirmation): array
    {
        // Same rule shape as AuthService::register() (minus the name/email
        // fields) and the same two legacy strings — an empty password
        // reports the min message via `required`, exactly as before.
        $errors = (new Validator())->validate(
            [
                'password'              => $password,
                'password_confirmation' => $confirmation,
            ],
            ['password' => 'required|min:8|confirmed'],
            [
                'password.required'  => 'Password must be at least 8 characters.',
                'password.min'       => 'Password must be at least 8 characters.',
                'password.confirmed' => 'Password confirmation does not match.',
            ],
        )->all();

        if ([] !== $errors) {
            return $errors;
        }

        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'SELECT id, email FROM password_reset_tokens
             WHERE token = :hash AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP LIMIT 1',
        );
        $stmt->execute([':hash' => $hash]);
        /** @var array{id: string, email: string}|false $row */
        $row = $stmt->fetch();

        if (false === $row) {
            return ['This reset link is invalid or has expired.'];
        }

        $user = $this->users->findByEmail($row['email']);

        if (null === $user) {
            return ['This reset link is invalid or has expired.'];
        }

        $this->users->updatePassword($user->id(), $this->hasher->hash($password));

        $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => $row['id']]);

        return [];
    }
}
