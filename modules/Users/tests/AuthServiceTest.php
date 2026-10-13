<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AuthServiceTest — register() still speaks its legacy error strings after
 * the Validation-module refactor, in the same order.
 *
 * The refactor moved the field rules into `Modules\Validation` but kept the
 * messages (custom `field.rule` strings) and the position of the
 * email-taken error (between the email-format and password errors — the
 * uniqueness check needs the database, so it is not a validation rule and is
 * spliced in afterwards). These assertions pin that contract: identical
 * input must produce byte-identical error lists.
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class AuthServiceTest extends TestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users', 'Validation', 'Security'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Same shape as UsersDatabaseMigrator::createUsersTable().
        $pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id VARCHAR(32) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                avatar VARCHAR(255),
                is_active BOOLEAN DEFAULT 1,
                last_login_at DATETIME,
                last_login_ip VARCHAR(45),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
        // UserRepository::hydrate() joins roles/permissions — the columns
        // below are the only ones it touches.
        $pdo->exec('CREATE TABLE roles (id VARCHAR(32) PRIMARY KEY, name VARCHAR(50) UNIQUE NOT NULL)');
        $pdo->exec('CREATE TABLE user_roles (user_id VARCHAR(32) NOT NULL, role_id VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE permissions (id VARCHAR(32) PRIMARY KEY, name VARCHAR(50) UNIQUE NOT NULL)');
        $pdo->exec('CREATE TABLE role_permissions (role_id VARCHAR(32) NOT NULL, permission_id VARCHAR(32) NOT NULL, PRIMARY KEY (role_id, permission_id))');

        $this->auth = new AuthService($pdo, new UserRepository($pdo), new PasswordHasher(), new RateLimiter($pdo));
    }

    #[Test]
    public function invalidInputReportsLegacyMessagesInOrder(): void
    {
        $result = $this->auth->register('', 'not-an-email', 'short', 'different');

        $this->assertSame(
            [
                'Name is required (max 255 characters).',
                'A valid email address is required.',
                'Password must be at least 8 characters.',
                'Password confirmation does not match.',
            ],
            $result['errors'],
        );
    }

    #[Test]
    public function duplicateEmailKeepsItsPositionBeforePasswordErrors(): void
    {
        $this->assertSame([], $this->auth->register('Eva', 'eva@example.test', 'correct-horse', 'correct-horse')['errors']);

        $result = $this->auth->register('Bob', 'eva@example.test', 'short', 'short');

        $this->assertSame(
            [
                'An account with this email already exists.',
                'Password must be at least 8 characters.',
            ],
            $result['errors'],
        );
    }

    #[Test]
    public function validInputRegistersWithoutErrors(): void
    {
        $result = $this->auth->register('  Eva  ', 'EVA@EXAMPLE.TEST', 'correct-horse', 'correct-horse');

        $this->assertSame([], $result['errors']);
        $this->assertSame('Eva', $result['user']->name());
        $this->assertSame('eva@example.test', $result['user']->email());
    }
}
