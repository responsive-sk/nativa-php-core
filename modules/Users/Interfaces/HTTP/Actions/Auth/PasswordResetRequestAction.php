<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP\Actions\Auth;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Users\Application\Services\PasswordResetService;
use Modules\Users\Interfaces\HTTP\AuthServices;
use Modules\Users\Interfaces\HTTP\AuthTemplate;

/**
 * Password reset request — GET renders the "forgot password" form,
 * POST generates a token and logs it via the dev mailer.
 */
#[Route(path: '/forgot-password', method: 'GET', name: 'auth.forgot')]
#[Route(path: '/forgot-password', method: 'POST', name: 'auth.forgot.submit', handlerMethod: 'submit')]
final class PasswordResetRequestAction
{
    public function handle(Request $request): Response
    {
        return AuthTemplate::render(
            'Reset password',
            '/forgot-password',
            'Send reset link',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autofocus' => true],
            ],
            [],
            '<a href="/login">Back to sign in</a>',
        );
    }

    public function submit(Request $request): Response
    {
        $email = $request->body('email');

        // Always generate a token (and log it) regardless of whether the
        // account exists — avoids user enumeration. If no account exists,
        // we still show the success message.
        /** @var PasswordResetService $resets */
        $resets = AuthServices::resets();
        $resets->requestReset(is_string($email) ? $email : '');

        return AuthTemplate::render(
            'Check your email',
            '/forgot-password',
            'Send another link',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $email ?? ''],
            ],
            [],
            '<a href="/login">Back to sign in</a>',
            'If an account exists for that email, a reset link has been logged by the dev mailer (storage/logs/app.log).',
        );
    }
}
