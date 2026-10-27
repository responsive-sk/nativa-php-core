<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Notifications\Domain\Model\Notification;
use Modules\Notifications\Domain\SignedInUser;
use Modules\Notifications\Interfaces\HTTP\NotificationsServices;

/**
 * Notifications inbox — GET /mark/notifications, POST /mark/notifications/{id}/read
 * and POST /mark/notifications/read-all.
 *
 * The page lives here rather than in `modules/Notifications` because Cardboard
 * is this kit's admin app: the Users and Roles screens already live in this
 * directory, and the alternative — Notifications importing Cardboard for its
 * layout — would close a cycle, because Cardboard owns the shell and therefore
 * already reaches Users, and Users is what writes the inbox.
 *
 * Nothing here decides WHO may read an inbox: the `/mark` gate requires
 * `mark.access` for the path (see `AdminRoutePolicy`), and every query takes the
 * signed-in user id from the session. A notification id in a URL is not an
 * authorization: `markRead()` scopes on the notifiable, so another user's row
 * answers "nothing changed" rather than clearing.
 *
 * Read state changes are deliberately NOT audited. The audit log answers "who
 * did what to the site", and reading your own inbox is not one of those things —
 * a row per page view would bury the entries that matter.
 */
#[Route(path: '/mark/notifications', method: 'GET', name: 'mark.notifications')]
#[Route(path: '/mark/notifications/{id}/read', method: 'POST', name: 'mark.notifications.read', handlerMethod: 'read')]
#[Route(path: '/mark/notifications/read-all', method: 'POST', name: 'mark.notifications.read-all', handlerMethod: 'readAll')]
final class NotificationsAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        return Response::html($this->page());
    }

    public function read(Request $request): Response
    {
        $userId = SignedInUser::id();
        $id = $request->routeParam('id');

        if (null !== $userId && null !== $id && '' !== $id) {
            NotificationsServices::inbox()->markRead($id, $userId);
        }

        return Response::redirect($this->url('mark.notifications'));
    }

    public function readAll(Request $request): Response
    {
        $userId = SignedInUser::id();

        if (null !== $userId) {
            NotificationsServices::inbox()->markAllRead($userId);
        }

        return Response::redirect($this->url('mark.notifications'));
    }

    private function page(): string
    {
        $userId = SignedInUser::id();

        if (null === $userId) {
            // Behind AuthMiddleware this cannot happen; rendering an empty inbox
            // beats throwing on the one page whose job is to reassure.
            return AdminLayout::render(
                'Notifications',
                AdminLayout::emptyState('Nothing to show', 'Sign in to see your inbox.'),
                'notifications',
                router: $this->router(),
            );
        }

        // Rows per page. The list is the operator's recent history, not an
        // archive: twenty is more than a working day of moderation here and
        // keeps the page a page. Paging it through the shared paginator is
        // Phase F's job, which is why this is a local number and not a constant
        // the declaration ratchet would have to grow for.
        $pageSize = 20;

        try {
            $inbox = NotificationsServices::inbox();
            $notifications = $inbox->recent($userId, $pageSize);
            $unread = $inbox->unreadCount($userId);
            $total = $inbox->total($userId);
        } catch (\Throwable $e) {
            error_log('[notifications] inbox unavailable: ' . $e->getMessage());

            return AdminLayout::render(
                'Notifications',
                AdminLayout::emptyState(
                    'Inbox unavailable',
                    'The notifications database has not been created yet — run bin/cms migrate.',
                ),
                'notifications',
                router: $this->router(),
            );
        }

        return AdminLayout::render(
            'Notifications',
            $this->summary($unread, $total) . $this->listHtml($notifications),
            'notifications',
            router: $this->router(),
        );
    }

    private function summary(int $unread, int $total): string
    {
        $summary = '<p class="m-muted">' . $unread . ' unread · ' . $total . ' total</p>';

        if (0 === $unread) {
            return $summary;
        }

        return '<div class="m-card"><form class="m-form" method="POST" action="'
            . AdminLayout::e($this->url('mark.notifications.read-all')) . '">'
            . AdminLayout::csrfField()
            . '<button class="m-btn m-btn-primary" type="submit">Mark all as read</button>'
            . '</form></div>' . $summary;
    }

    /**
     * @param list<Notification> $notifications
     */
    private function listHtml(array $notifications): string
    {
        if ([] === $notifications) {
            return AdminLayout::emptyState(
                'No notifications',
                'Moderation alerts land here — a comment or a question that waits for you.',
            );
        }

        $rows = '';

        foreach ($notifications as $notification) {
            $rows .= $this->row($notification);
        }

        return '<table class="m-table"><thead><tr>'
            . '<th>Notification</th><th>When</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function row(Notification $notification): string
    {
        $body = '' === $notification->body()
            ? ''
            : '<div class="m-muted">' . nl2br(AdminLayout::e($notification->body()), false) . '</div>';

        $href = $notification->href();
        $open = '' === $href
            ? ''
            : ' <a class="m-btn m-btn-sm m-btn-ghost" href="' . AdminLayout::e($href) . '">Open</a>';

        $unreadBadge = $notification->isRead()
            ? ''
            : ' <span class="m-badge m-badge-success">unread</span>';

        $markRead = $notification->isRead()
            ? ''
            : '<form class="m-inline-form" method="POST" action="'
                . AdminLayout::e($this->url('mark.notifications.read', ['id' => $notification->id()])) . '">'
                . AdminLayout::csrfField()
                . '<button class="m-btn m-btn-ghost m-btn-sm" type="submit">Mark read</button>'
                . '</form>';

        return '<tr>'
            . '<td><strong>' . AdminLayout::e($notification->title()) . '</strong>' . $unreadBadge
            . '<div class="m-mono m-muted">' . AdminLayout::e($notification->kind()) . '</div>'
            . $body . '</td>'
            . '<td class="m-mono">' . AdminLayout::relativeTime($notification->createdAt()) . '</td>'
            . '<td>' . $open . $markRead . '</td>'
            . '</tr>';
    }
}
