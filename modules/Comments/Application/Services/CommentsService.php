<?php

declare(strict_types = 1);

namespace Modules\Comments\Application\Services;

use Modules\Comments\Domain\Entities\Comment;
use Modules\Comments\Infrastructure\Persistence\Repositories\CommentRepository;
use Modules\Security\Application\Services\RateLimiter;

/**
 * CommentsService — submit, list and moderate article comments.
 *
 * Submission is CSRF-protected at the middleware level (CsrfMiddleware) and
 * rate-limited per IP+slug here (loopback exempt so local dev is not
 * throttled). New comments always start as 'pending'; only approved comments
 * render publicly.
 */
final readonly class CommentsService
{
    private const int MAX_BODY_LENGTH = 4000;

    private const int MAX_NAME_LENGTH = 120;

    private const int MAX_EMAIL_LENGTH = 255;

    private const int RATE_LIMIT = 10;

    private const int RATE_WINDOW = 60;

    /** @var list<string> Loopback addresses exempt from the per-comment limit. */
    private const array LOOPBACK_IPS = ['127.0.0.1', '::1', 'localhost'];

    public function __construct(
        private CommentRepository $comments,
        private RateLimiter $limiter,
    ) {
    }

    /**
     * Submit a comment (pending by default).
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public function submit(
        string $slug,
        ?string $userId,
        ?string $userName,
        ?string $guestName,
        ?string $guestEmail,
        string $body,
        string $ip,
    ): array {
        $slug = trim($slug);
        $body = trim($body);
        $guestName = null === $guestName ? null : trim($guestName);
        $guestEmail = null === $guestEmail ? null : trim($guestEmail);

        // Rate limit first — invalid payloads must not bypass throttling.
        if (!$this->isAllowed($slug, $ip)) {
            return ['ok' => false, 'errors' => ['Too many comments — try again in a minute.']];
        }

        $errors = $this->validate($slug, $userId, $guestName, $guestEmail, $body);

        if ([] !== $errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Logged-in users carry their account name as the display name.
        $displayName = null !== $userId && null !== $userName ? $userName : $guestName;

        $this->comments->insert(Comment::create(
            $slug,
            $userId,
            $displayName,
            null !== $userId ? null : $guestEmail,
            $body,
            $this->loopback($ip) ? null : $ip,
        ));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Approved comments for an article, oldest first.
     *
     * @return list<Comment>
     */
    public function approved(string $slug): array
    {
        return $this->comments->findByArticle($slug);
    }

    /**
     * Pending comments (admin moderation queue).
     *
     * @return list<Comment>
     */
    public function pending(): array
    {
        return $this->comments->findByStatus(Comment::STATUS_PENDING);
    }

    /**
     * All comments, pending first (admin moderation).
     *
     * @return list<Comment>
     */
    public function all(): array
    {
        return $this->comments->all();
    }

    public function approve(string $id): bool
    {
        if (null === $this->comments->findById($id)) {
            return false;
        }

        $this->comments->setStatus($id, Comment::STATUS_APPROVED);

        return true;
    }

    public function reject(string $id): bool
    {
        if (null === $this->comments->findById($id)) {
            return false;
        }

        $this->comments->setStatus($id, Comment::STATUS_REJECTED);

        return true;
    }

    public function delete(string $id): bool
    {
        if (null === $this->comments->findById($id)) {
            return false;
        }

        $this->comments->delete($id);

        return true;
    }

    /**
     * @return list<string>
     */
    private function validate(string $slug, ?string $userId, ?string $guestName, ?string $guestEmail, string $body): array
    {
        $errors = [];

        if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
            $errors[] = 'Invalid article.';
        }

        if ('' === $body) {
            $errors[] = 'Comment body is required.';
        } elseif (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            $errors[] = 'Comment is too long (max ' . self::MAX_BODY_LENGTH . ' characters).';
        }

        if (null === $userId) {
            // Guests must provide a name + valid email.
            if (null === $guestName || '' === $guestName) {
                $errors[] = 'Name is required.';
            } elseif (mb_strlen($guestName) > self::MAX_NAME_LENGTH) {
                $errors[] = 'Name is too long (max ' . self::MAX_NAME_LENGTH . ' characters).';
            }

            if (null === $guestEmail || false === filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'A valid email address is required.';
            } elseif (mb_strlen($guestEmail) > self::MAX_EMAIL_LENGTH) {
                $errors[] = 'Email is too long (max ' . self::MAX_EMAIL_LENGTH . ' characters).';
            }
        }

        return $errors;
    }

    /**
     * Rate limit per IP+slug (loopback exempt).
     */
    private function isAllowed(string $slug, string $ip): bool
    {
        if ($this->loopback($ip)) {
            return true;
        }

        return $this->limiter->isAllowed(
            'comment:' . $slug . ':' . $ip,
            self::RATE_LIMIT,
            self::RATE_WINDOW,
        );
    }

    private function loopback(string $ip): bool
    {
        return in_array($ip, self::LOOPBACK_IPS, true);
    }
}
