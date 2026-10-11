<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP;

use Modules\Blog\Application\Services\BlogCrudService;
use Modules\Cardboard\Application\Services\AdminNavService;
use Modules\Core\Application\Services\PageCacheService;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Settings\Infrastructure\Persistence\Repositories\MenuRepository;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * CardboardServices — lazy cached service factories for admin actions.
 *
 * The Kernel instantiates action classes without dependency injection, so
 * actions resolve shared services through these factories (same pattern as
 * AuthServices). Connections are cached by DatabaseConnectionManager.
 */
final class CardboardServices
{
    private static ?UserRepository $users = null;

    private static ?RoleRepository $roles = null;

    private static ?SettingsRepository $settings = null;

    private static ?MenuRepository $menu = null;

    private static ?AuthService $auth = null;

    private static ?AdminNavService $adminNav = null;

    private static ?PageCacheService $pageCache = null;

    private static ?PageCrudService $pageCrud = null;

    private static ?BlogCrudService $blogCrud = null;

    public static function users(): UserRepository
    {
        return self::$users ??= new UserRepository(
            DatabaseConnectionManager::instance()->connection('users'),
        );
    }

    public static function roles(): RoleRepository
    {
        return self::$roles ??= new RoleRepository(
            DatabaseConnectionManager::instance()->connection('users'),
        );
    }

    public static function settings(): SettingsRepository
    {
        return self::$settings ??= new SettingsRepository(
            DatabaseConnectionManager::instance()->connection('config'),
        );
    }

    public static function menu(): MenuRepository
    {
        return self::$menu ??= new MenuRepository(
            DatabaseConnectionManager::instance()->connection('config'),
        );
    }

    public static function auth(): AuthService
    {
        return self::$auth ??= new AuthService(
            DatabaseConnectionManager::instance()->connection('users'),
            self::users(),
            new PasswordHasher(),
            new RateLimiter(
                DatabaseConnectionManager::instance()->connection('users'),
            ),
        );
    }

    public static function adminNav(): AdminNavService
    {
        return self::$adminNav ??= new AdminNavService(
            DatabaseConnectionManager::instance()->connection('config'),
        );
    }

    public static function pageCache(): PageCacheService
    {
        return self::$pageCache ??= new PageCacheService(
            APP_BASE_PATH . '/' . PageCacheService::DEFAULT_DIR,
        );
    }

    public static function pageCrud(): PageCrudService
    {
        return self::$pageCrud ??= new PageCrudService();
    }

    public static function blogCrud(): BlogCrudService
    {
        return self::$blogCrud ??= new BlogCrudService();
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$users = null;
        self::$roles = null;
        self::$settings = null;
        self::$menu = null;
        self::$auth = null;
        self::$adminNav = null;
        self::$pageCache = null;
        self::$pageCrud = null;
        self::$blogCrud = null;
    }
}
