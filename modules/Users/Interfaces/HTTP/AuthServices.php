<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Application\Services\PasswordResetService;
use Modules\Users\Infrastructure\Mail\PasswordResetMailerFactory;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * AuthServices — lazy service factories for the HTTP layer.
 *
 * The Kernel instantiates action classes without dependency injection, so
 * actions resolve the shared services through these factories. Connections
 * are cached by DatabaseConnectionManager, so repeated construction is cheap.
 */
final class AuthServices
{
    private static ?AuthService $auth = null;

    private static ?PasswordResetService $resets = null;

    public static function auth(): AuthService
    {
        if (null === self::$auth) {
            // One routed connection for the service and its repository, so a
            // write in the service is visible to the repository's next read.
            // The rate limiter is Security's and still takes the raw handle.
            $db = DatabaseConnectionManager::instance()->connect('users');

            self::$auth = new AuthService(
                $db,
                new UserRepository($db),
                new PasswordHasher(),
                new RateLimiter(
                    DatabaseConnectionManager::instance()->connection('users'),
                ),
            );
        }

        return self::$auth;
    }

    public static function resets(): PasswordResetService
    {
        if (null === self::$resets) {
            $db = DatabaseConnectionManager::instance()->connect('users');

            // The mailer is resolved here, once: which transport carries a
            // reset link is an environment fact, and this is where the service
            // graph is composed (see PasswordResetMailerFactory).
            self::$resets = new PasswordResetService(
                $db,
                new UserRepository($db),
                new PasswordHasher(),
                PasswordResetMailerFactory::create(),
                new RateLimiter(
                    DatabaseConnectionManager::instance()->connection('users'),
                ),
            );
        }

        return self::$resets;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$auth = null;
        self::$resets = null;
    }
}
