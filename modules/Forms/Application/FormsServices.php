<?php

declare(strict_types = 1);

namespace Modules\Forms\Application;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\Services\FormResultsService;
use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Forms\Infrastructure\Persistence\Repositories\FormRepository;
use Modules\Forms\Infrastructure\Persistence\Repositories\SubmissionRepository;
use Modules\Security\Application\Services\RateLimiter;

/**
 * FormsServices — lazy cached service factories for the Forms module.
 *
 * Same shape as `QuestionsServices`: the kernel instantiates action classes
 * with no dependency injection, so the shared services are resolved through
 * this factory and nothing is constructed until a surface needs it.
 *
 * Two connections, deliberately separate: definitions and submissions live on
 * `forms`, the rate limiter lives on `users` (where `rate_limits` is). SQLite
 * cannot join across databases, and a limiter pointed at `forms` would create
 * a second, empty table nobody migrates.
 */
final class FormsServices
{
    private static ?FormRepository $forms = null;

    private static ?SubmissionRepository $submissions = null;

    private static ?FormResultsService $results = null;

    private static ?RateLimiter $limiter = null;

    public static function forms(): FormRepository
    {
        return self::$forms ??= new FormRepository(
            DatabaseConnectionManager::instance()->connect('forms'),
        );
    }

    public static function submissions(): SubmissionRepository
    {
        return self::$submissions ??= new SubmissionRepository(
            DatabaseConnectionManager::instance()->connect('forms'),
        );
    }

    public static function results(): FormResultsService
    {
        return self::$results ??= new FormResultsService();
    }

    /**
     * The chrome strings the block renders in one locale.
     *
     * They live in `locales.db` (`forms.*`), not in this module: the submit
     * label, the thank-you line and the validation messages are reader-facing
     * words, and the module that owns translations is the one place an editor
     * can change them. The block and the submit action both ask here, so a
     * Slovak page and its English sibling say the same thing in their own
     * language. Without the Locales module the map is empty and every call
     * site keeps its English floor.
     *
     * @return array<string, string>
     */
    public static function copy(string $locale): array
    {
        return ShellContextProviderRegistry::copy('forms', $locale);
    }

    /**
     * The rate limiter, backed by users.db (where rate_limits lives).
     */
    public static function limiter(): RateLimiter
    {
        return self::$limiter ??= new RateLimiter(
            DatabaseConnectionManager::instance()->connect('users'),
        );
    }

    /**
     * Reset cached instances and the cached PDO connection (test isolation).
     *
     * The connection is closed too: pointing `DB_FORMS` at a new file only
     * takes effect on a connection that is opened after the change, and a
     * suite that forgot this would carry the previous test's rows into the
     * next one.
     */
    public static function reset(): void
    {
        self::$forms = null;
        self::$submissions = null;
        self::$results = null;
        self::$limiter = null;

        DatabaseConnectionManager::instance()->close('forms');
    }
}
