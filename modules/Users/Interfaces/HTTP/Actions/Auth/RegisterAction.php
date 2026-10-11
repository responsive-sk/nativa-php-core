<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP\Actions\Auth;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Interfaces\HTTP\AuthServices;
use Modules\Users\Interfaces\HTTP\AuthTemplate;

/**
 * Registration action — GET renders the form, POST registers.
 */
#[Route(path: '/register', method: 'GET', name: 'auth.register')]
#[Route(path: '/register', method: 'POST', name: 'auth.register.submit', handlerMethod: 'submit')]
final class RegisterAction
{
    public function handle(Request $request): Response
    {
        $returnTo = AuthTemplate::returnTo($request);

        return AuthTemplate::render(
            'Create account',
            '/register',
            'Register',
            [
                ['name' => 'name', 'label' => 'Name', 'autofocus' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
                ['name' => 'password', 'label' => 'Password', 'type' => 'password'],
                ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password'],
            ],
            [],
            'Already have an account? <a href="/login">Sign in</a>',
            returnTo: $returnTo,
        );
    }

    public function submit(Request $request): Response
    {
        $name = $request->body('name');
        $email = $request->body('email');
        $password = $request->body('password');
        $confirmation = $request->body('password_confirmation');

        /** @var AuthService $auth */
        $auth = AuthServices::auth();
        $result = $auth->register(
            is_string($name) ? $name : '',
            is_string($email) ? $email : '',
            is_string($password) ? $password : '',
            is_string($confirmation) ? $confirmation : '',
        );

        if ([] !== $result['errors']) {
            return AuthTemplate::render(
                'Create account',
                '/register',
                'Register',
                [
                    ['name' => 'name', 'label' => 'Name', 'value' => $name ?? ''],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $email ?? ''],
                    ['name' => 'password', 'label' => 'Password', 'type' => 'password'],
                    ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password'],
                ],
                $result['errors'],
                returnTo: AuthTemplate::returnTo($request),
            );
        }

        // Auto-login after successful registration.
        $auth->establishSession($result['user'], $request->getClientIp());

        return Response::redirect(AuthTemplate::returnTo($request) ?? '/mark');
    }
}
