<?php

declare(strict_types = 1);

namespace Modules\Comments\Interfaces\HTTP\Actions;

use Modules\Blog\Interfaces\HTTP\Actions\BlogArticleAction;
use Modules\Comments\Interfaces\HTTP\CommentsServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * Comment submit action — POST /blog/{slug}/comments.
 *
 * CSRF is enforced globally by CsrfMiddleware (the form carries its field).
 * Guest comments need guest_name + guest_email; signed-in users are
 * attributed from the session. New comments are always 'pending' — they only
 * become visible after an admin approves them in /mark/comments.
 *
 * PRG flow: on success redirect back to the article (#comments); on
 * validation failure re-render the article page with the form errors inline
 * (the CommentSection form keeps the old input).
 */
#[Route(path: '/blog/{slug}/comments', method: 'POST', name: 'blog.comments.submit')]
final class CommentSubmitAction
{
    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');

        if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
            return Response::redirect('/blog');
        }

        $user = CommentsServices::auth()->currentUser();
        $userId = null === $user ? null : $user->id();
        $userName = null === $user ? null : $user->name();

        $guestName = $this->stringOrNull($request->body('guest_name'));
        $guestEmail = $this->stringOrNull($request->body('guest_email'));
        $body = $this->stringOrNull($request->body('body')) ?? '';

        $result = CommentsServices::comments()->submit(
            $slug,
            $userId,
            $userName,
            $guestName,
            $guestEmail,
            $body,
            $request->getClientIp(),
        );

        if ($result['ok']) {
            return Response::redirect('/blog/' . $slug . '#comments');
        }

        // Re-render the article with errors inline — the errors and old
        // input are passed through a request attribute to BlogArticleAction.
        $request->setPathInfo('/blog/' . $slug);
        $request->setAttribute('comment_errors', $result['errors']);
        $request->setAttribute('comment_old', [
            'guest_name'  => $guestName ?? '',
            'guest_email' => $guestEmail ?? '',
            'body'        => $body,
        ]);

        return (new BlogArticleAction())->handle($request);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
