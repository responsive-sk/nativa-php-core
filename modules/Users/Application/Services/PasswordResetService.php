<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * PasswordResetService — request token → reset flow.
 *
 * Development mailer: tokens are logged to storage/logs/app.log instead of
 * being sent via SMTP (no external service in the starter kit).
 */
final readonly class PasswordResetService
{
    private const int TOKEN_TTL_HOURS = 1;

    public function __construct(
        private \PDO $pdo,
        private UserRepository $users,
        private PasswordHasher $hasher,
    ) {
    }

    /**
     * Create a reset token for an email (always succeeds to avoid
     * user enumeration). Returns the raw token (for the dev log).
     */
    public function requestReset(string $email): string
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

        // Dev mailer — log the plain token so the flow is usable without SMTP.
        $logsDir = AppPaths::instance()->logs();
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0775, true);
        }
        $line = sprintf(
            "[%s] [mailer:dev] Password reset for %s → token: %s (valid %d h)\n",
            date('Y-m-d H:i:s'),
            $email,
            $token,
            self::TOKEN_TTL_HOURS,
        );
        file_put_contents($logsDir . '/app.log', $line, FILE_APPEND);

        return $token;
    }

    /**
     * Validate + consume a reset token and update the password.
     *
     * @return list<string> errors (empty on success)
     */
    public function reset(string $token, string $password, string $confirmation): array
    {
        $errors = [];

        if (mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($password !== $confirmation) {
            $errors[] = 'Password confirmation does not match.';
        }

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
