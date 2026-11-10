<?php

declare(strict_types = 1);

namespace Modules\Comments\Interfaces\HTTP;

use Modules\Comments\Application\Services\CommentsService;
use Modules\Comments\Infrastructure\Persistence\Repositories\CommentRepository;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * CommentsServices — lazy cached service factories for the Comments module.
 *
 * Same pattern as LocalesServices/CardboardServices: the Kernel instantiates
 * action classes without dependency injection, so shared services are
 * resolved through these factories.
 */
final class CommentsServices
{
    private static ?CommentsService $comments = null;

    private static ?AuthService $auth = null;

    public static function comments(): CommentsService
    {
        return self::$comments ??= new CommentsService(
            new CommentRepository(
                DatabaseConnectionManager::instance()->connect('comments'),
            ),
            new RateLimiter(
                DatabaseConnectionManager::instance()->connect('users'),
            ),
        );
    }

    /**
     * AuthService over users.db — current user attribution for comments.
     */
    public static function auth(): AuthService
    {
        if (null === self::$auth) {
            // One routed connection for the whole graph: the service, its
            // repository and the limiter all read and write users.db, so a
            // write any of them makes is visible to the others.
            $db = DatabaseConnectionManager::instance()->connect('users');

            self::$auth = new AuthService(
                $db,
                new UserRepository($db),
                new PasswordHasher(),
                new RateLimiter($db),
            );
        }

        return self::$auth;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$comments = null;
        self::$auth = null;
    }
}
