<?php

declare(strict_types = 1);

namespace Modules\Core\Kernel\Contracts;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * RouterInterface — what the HTTP kernel needs from a router: match a request
 * to an action, or return null for a 404.
 */
interface RouterInterface
{
    /**
     * @return array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string}|null
     */
    public function match(Request $request): ?array;
}
