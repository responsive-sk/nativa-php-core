<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\NotificationsAction;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Notifications\Infrastructure\Persistence\Migrations\NotificationsDatabaseMigrator;
use Modules\Notifications\Interfaces\HTTP\NotificationsServices;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NotificationsPageTest — the inbox page shows one person's rows and clears
 * only those.
 *
 * Three failures are worth a test each, because all three look like working
 * software from the outside:
 *
 * 1. **The page renders another user's rows.** The session is the only thing
 *    that decides whose inbox is shown, so the list is asserted to contain the
 *    signed-in user's notification and to be free of the other one.
 * 2. **A form clears a row it does not own.** The id comes from the page, so the
 *    POST is driven with the OTHER user's id: nothing may change.
 * 3. **"Mark all as read" sweeps everyone.** The other inbox is asserted to keep
 *    its unread count after the sweep.
 *
 * The page is rendered through the real `AdminLayout` rather than a fragment, so
 * the seeded Notifications nav row and the CSRF field are exercised too — the
 * two things a stubbed layout would hide. Both databases are throwaway files
 * through `DB_NOTIFICATIONS` / `DB_CONFIG`, and the session is saved and
 * restored, so nothing here touches the checkout it runs in.
 */
final class NotificationsPageTest extends TestCase
{
    private string $tmpDir = '';

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Cardboard', 'Notifications', 'Settings'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->session = $_SESSION ?? [];

        $this->tmpDir = sys_get_temp_dir() . '/nativa-inbox-page-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_NOTIFICATIONS=' . $this->tmpDir . '/notifications.db');
        putenv('DB_CONFIG=' . $this->tmpDir . '/config.db');
        DatabaseConnectionManager::resetInstance();
        NotificationsServices::reset();
        CardboardServices::reset();

        (new NotificationsDatabaseMigrator(new \PDO('sqlite:' . $this->tmpDir . '/notifications.db')))
            ->run($this->quietOutput());
        // The layout reads the site name and the admin nav from config.db; a
        // missing table would make the shell silently drop its navigation and
        // hide the row this page is meant to be reachable from.
        (new SettingsDatabaseMigrator(new \PDO('sqlite:' . $this->tmpDir . '/config.db')))
            ->run($this->quietOutput());
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/Notifications')) {
            putenv('DB_NOTIFICATIONS');
            putenv('DB_CONFIG');
            DatabaseConnectionManager::resetInstance();
            NotificationsServices::reset();
            CardboardServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        $_SESSION = $this->session;

        parent::tearDown();
    }

    #[Test]
    public function itListsTheSignedInUsersInboxAndReachesItFromTheSidebar(): void
    {
        $this->signIn('user-a');
        $this->push('user-a', 'New comment awaiting moderation', 'Article: why-a-port');
        $this->push('user-b', 'New question awaiting moderation', 'About: article/why-a-port');

        $response = $this->action()->handle($this->request('GET', '/mark/notifications'));

        self::assertSame(200, $response->getStatusCode());

        $html = $response->getContent();

        self::assertStringContainsString('New comment awaiting moderation', $html);
        self::assertStringNotContainsString(
            'New question awaiting moderation',
            $html,
            'another user\'s inbox row must not be rendered',
        );
        self::assertStringContainsString('1 unread · 1 total', $html);
        self::assertStringContainsString('Mark all as read', $html);
        self::assertStringContainsString('Article: why-a-port', $html);
        self::assertStringContainsString(
            '/mark/notifications',
            $html,
            'the sidebar must offer the inbox the seeded nav row names',
        );
    }

    #[Test]
    public function aReadInboxSaysSoInsteadOfOfferingTheSweep(): void
    {
        $this->signIn('user-a');

        $html = $this->action()->handle($this->request('GET', '/mark/notifications'))->getContent();

        self::assertStringContainsString('0 unread · 0 total', $html);
        self::assertStringContainsString('No notifications', $html);
        self::assertStringNotContainsString('Mark all as read', $html, 'a sweep with nothing to clear is a lie');
    }

    #[Test]
    public function markingOneRowReadClearsOnlyThatRow(): void
    {
        $this->signIn('user-a');
        $this->push('user-a', 'First', 'Body');
        $this->push('user-a', 'Second', 'Body');

        $row = NotificationsServices::inbox()->recent('user-a')[0];

        $response = $this->read($row->id());

        self::assertSame(302, $response->getStatusCode(), 'a POST answers with a redirect back to the list');
        self::assertSame(1, NotificationsServices::inbox()->unreadCount('user-a'));
    }

    /**
     * The id is form input. Posting somebody else's must change nothing — the
     * scoping lives in the UPDATE, and this is the assertion that keeps it there.
     */
    #[Test]
    public function anotherUsersRowCannotBeClearedFromTheForm(): void
    {
        $this->signIn('user-a');
        $this->push('user-b', 'Theirs', 'Body');

        $theirRow = NotificationsServices::inbox()->recent('user-b')[0];

        $this->read($theirRow->id());

        self::assertSame(1, NotificationsServices::inbox()->unreadCount('user-b'));
    }

    #[Test]
    public function markAllAsReadClearsOnlyTheSignedInUsersInbox(): void
    {
        $this->signIn('user-a');
        $this->push('user-a', 'Mine', 'Body');
        $this->push('user-b', 'Theirs', 'Body');

        $response = $this->action()->readAll($this->request('POST', '/mark/notifications/read-all'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, NotificationsServices::inbox()->unreadCount('user-a'));
        self::assertSame(1, NotificationsServices::inbox()->unreadCount('user-b'));
    }

    private function action(): NotificationsAction
    {
        $router = Router::fromEntries([], [
            // The layout links its own brand to the dashboard, so the shell
            // resolves this name on every admin page.
            ['path' => '/mark', 'method' => 'GET', 'name' => 'mark.dashboard', 'handlerMethod' => null, 'action' => NotificationsAction::class],
            ['path' => '/mark/notifications', 'method' => 'GET', 'name' => 'mark.notifications', 'handlerMethod' => null, 'action' => NotificationsAction::class],
            ['path' => '/mark/notifications/read-all', 'method' => 'POST', 'name' => 'mark.notifications.read-all', 'handlerMethod' => 'readAll', 'action' => NotificationsAction::class],
            ['path' => '/mark/notifications/{id}/read', 'method' => 'POST', 'name' => 'mark.notifications.read', 'handlerMethod' => 'read', 'action' => NotificationsAction::class],
        ]);

        return new NotificationsAction($router);
    }

    /**
     * @return \Modules\Core\Infrastructure\Http\Response
     */
    private function read(string $id)
    {
        $request = $this->request('POST', '/mark/notifications/' . $id . '/read');
        $request->setRouteParams(['id' => $id]);

        return $this->action()->read($request);
    }

    private function request(string $method, string $path): Request
    {
        return Request::create($method, $path);
    }

    private function push(string $notifiableId, string $title, string $body): void
    {
        NotificationsServices::inbox()->push([$notifiableId], 'comment', $title, $body, '/mark/comments');
    }

    private function signIn(string $userId): void
    {
        $_SESSION['auth']['user_id'] = $userId;
    }

    private function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
