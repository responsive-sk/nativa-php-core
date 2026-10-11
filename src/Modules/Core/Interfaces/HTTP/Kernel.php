<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareInterface;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareRegistry;
use Modules\Core\Interfaces\HTTP\Middleware\SecurityHeadersMiddleware;

/**
 * HTTP Kernel — middleware pipeline + route dispatch.
 *
 * Pipeline order: security headers → session → CSRF → rate limiting → auth → dispatch.
 * Security headers are registered here; the remaining middleware is added by
 * their owning modules (Security/Users) via addMiddleware() during wiring.
 */
final class Kernel
{
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    /**
     * @param bool $autoDiscoverMiddleware When true, auto-discovers #[AsMiddleware] classes
     */
    public function __construct(
        private readonly Router $router,
        bool $autoDiscoverMiddleware = true,
    ) {
        if ($autoDiscoverMiddleware) {
            // Auto-discover #[AsMiddleware] classes (SecurityHeaders, Csrf, Debugbar)
            foreach (MiddlewareRegistry::all() as $middleware) {
                $this->middleware[] = $middleware;
            }
        } else {
            // Manual mode: only SecurityHeaders (for tests)
            $this->middleware[] = new SecurityHeadersMiddleware();
        }
    }

    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function handle(Request $request): Response
    {
        $pipeline = $this->buildPipeline();

        return $pipeline($request);
    }

    /**
     * Build the middleware chain as nested closures (onion model).
     *
     * @return callable(Request): Response
     */
    private function buildPipeline(): callable
    {
        $next = fn (Request $request): Response => $this->dispatch($request);

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = static fn (Request $request): Response => $middleware->handle($request, $next);
        }

        return $next;
    }

    private function dispatch(Request $request): Response
    {
        try {
            return $this->dispatchMatched($request);
        } catch (\Throwable $e) {
            return $this->renderThrowable($e);
        }
    }

    /**
     * Route the request and run the matched action — 404 on no match, then
     * trailing-slash redirect, method-not-allowed, and finally the action.
     */
    private function dispatchMatched(Request $request): Response
    {
        $match = $this->router->match($request);

        if (null === $match) {
            return $this->renderError(404, 'Not Found', 'The requested page could not be found.');
        }

        $redirect = $this->resolveRedirect($match);
        if (null !== $redirect) {
            return $redirect;
        }

        $methodError = $this->resolveMethodNotAllowed($match);
        if (null !== $methodError) {
            return $methodError;
        }

        $request->setRouteParams($match['params']);

        return $this->runAction($request, $match);
    }

    /**
     * Instantiate the matched action, invoke its handler and normalize the
     * result to a Response.
     *
     * @param array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string} $match
     */
    private function runAction(Request $request, array $match): Response
    {
        $actionClass = $match['action'];
        $action = new $actionClass();

        $handlerMethod = $match['route']->handlerMethod ?? 'handle';
        if (!method_exists($action, $handlerMethod)) {
            throw new \RuntimeException(sprintf(
                'Action %s has no %s() method for route %s',
                $actionClass,
                $handlerMethod,
                $match['route']->path,
            ));
        }

        return $this->normalizeResult($action->{$handlerMethod}($request));
    }

    /**
     * Actions may return a Response, a JSON-serializable array or a scalar
     * rendered as HTML. Anything else is a programming error.
     */
    private function normalizeResult(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if (is_scalar($result)) {
            return Response::html((string) $result);
        }

        throw new \RuntimeException(sprintf(
            'Action handler returned an unsupported type: %s',
            get_debug_type($result),
        ));
    }

    /**
     * 500 path — debug mode leaks the message + trace; production logs the
     * exception and answers with a generic error page.
     */
    private function renderThrowable(\Throwable $e): Response
    {
        if (Env::bool('APP_DEBUG', false)) {
            return $this->renderError(500, 'Server Error', $e->getMessage() . "\n\n" . $e->getTraceAsString());
        }

        error_log(sprintf(
            '[KERNEL] %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        return $this->renderError(500, 'Server Error', 'An unexpected error occurred.');
    }

    /**
     * Trailing-slash canonicalization — the router matched the slash-stripped
     * path and marked the hit with a 'redirect' marker (301, query preserved).
     *
     * @param array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string} $match
     */
    private function resolveRedirect(array $match): ?Response
    {
        if (isset($match['redirect'])) {
            return Response::redirect($match['redirect'], 301);
        }

        return null;
    }

    /**
     * Method mismatch — the path matched a route but with the wrong HTTP
     * method. Answer with 405 + an Allow header naming the route's method.
     *
     * @param array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string} $match
     */
    private function resolveMethodNotAllowed(array $match): ?Response
    {
        if (isset($match['methodNotAllowed']) && true === $match['methodNotAllowed']) {
            $response = $this->renderError(405, 'Method Not Allowed', 'This method is not allowed for the requested resource.');
            $response->setHeader('Allow', $match['route']->method);

            return $response;
        }

        return null;
    }

    private function renderError(int $status, string $title, string $detail): Response
    {
        return ErrorPageRenderer::response($status, $title, $detail);
    }
}
