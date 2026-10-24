<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

/**
 * AdminNotificationHandler — the consumer behind "tell the operator".
 *
 * One handler, not one per module: the payload carries which KIND of event it
 * is, so Comments and Questions push a job name they do not own and the wording
 * of the mail lives in exactly one place. A third event is a `match` arm, not a
 * second handler and a second registration.
 *
 * **It deliberately does not import Queue.** The natural shape — a static
 * `register(HandlerRegistry)` like `Blog\CoverRenderHandler` — would make Users
 * depend on Queue, and Queue already reaches Users through Audit
 * (`QueueAdminAction` writes an audit row; `Audit\AuditServices` authenticates
 * through this module). That is a cycle, and `ModuleCatalogueShapeTest` reports
 * it as "these modules can never be installed". Registration therefore lives
 * where every other queue pairing lives: `wire_queue_handlers()` in
 * `src/composition.php`, which names both sides inline and is the one list every
 * DELIVERY process wires.
 *
 * The handler returns a boolean; the worker discards it. That is deliberate: a
 * notification that could not be delivered is NOT retried, because a duplicate
 * email to an operator is worse than a missing one that is parked in
 * `failed_jobs` with its error. The producers push with `maxAttempts: 1` for
 * the same reason.
 */
final readonly class AdminNotificationHandler
{
    /**
     * The name producers push and the registry looks up.
     */
    public const string HANDLER = 'notify.admin';

    /**
     * Characters of user text in the message. Long enough to recognise the
     * comment, short enough that a pasted code block does not turn the mail
     * into the page it was copied from — the panel link is the full text.
     */
    private const int EXCERPT_LIMIT = 400;

    public function __construct(private AdminNotifier $notifier)
    {
    }

    /**
     * @param array<string, mixed> $args the payload `NotificationPayload` built
     *
     * @return bool true when the transport accepted the message
     */
    public function handle(array $args): bool
    {
        $kind = is_string($args['kind'] ?? null) ? $args['kind'] : '';
        $author = $this->oneLine((string) ($args['author'] ?? ''), 'a reader');
        $target = $this->oneLine((string) ($args['target'] ?? ''), 'unknown');
        $excerpt = $this->excerpt((string) ($args['excerpt'] ?? ''));

        // The panel path travels in the payload instead of being spelled here.
        // A producer already owns its own moderation surface — Questions has
        // `QuestionsBadge::NAV_HREF` — and a second spelling in this class would
        // be a second owner for the same route, invisible to the module that
        // changes it.
        $panel = is_string($args['panel'] ?? null) ? $args['panel'] : '';
        $moderate = '' === $panel ? '' : "\n\n" . 'Moderate: ' . $this->notifier->link($panel);

        return match ($kind) {
            'comment' => $this->notifier->send(
                'New comment awaiting moderation',
                "A reader posted a comment that is waiting for you.\n\n"
                . "Article: {$target}\n"
                . "From: {$author}\n\n"
                . $excerpt . $moderate,
            ),
            'question' => $this->notifier->send(
                'New question awaiting moderation',
                "A reader asked a question that is waiting for you.\n\n"
                . "About: {$target}\n"
                . "From: {$author}\n\n"
                . $excerpt . $moderate,
            ),
            default => $this->unknown($kind),
        };
    }

    /**
     * A payload naming a kind this version does not know is a producer and a
     * consumer from different deployments — logged by name, never a fatal, and
     * never an email with a half-built body.
     */
    private function unknown(string $kind): bool
    {
        error_log(sprintf(
            '[notifications] unknown notification kind "%s" — nothing was sent (is the consumer older than its producer?)',
            '' === $kind ? '(empty)' : $kind,
        ));

        return false;
    }

    /**
     * User text arrives with the line breaks a textarea produced; the mail is a
     * paragraph, so runs of whitespace collapse to one space.
     */
    private function oneLine(string $value, string $fallback): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $collapsed ? $fallback : $collapsed;
    }

    private function excerpt(string $text): string
    {
        $collapsed = $this->oneLine($text, '(no text)');

        if (mb_strlen($collapsed) <= self::EXCERPT_LIMIT) {
            return $collapsed;
        }

        return mb_substr($collapsed, 0, self::EXCERPT_LIMIT - 1) . '…';
    }
}
