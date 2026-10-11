<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * Router — scans action classes for #[Route] attributes and matches requests.
 *
 * Features:
 * - #[Route] attribute scanning over configured action directories
 * - path, method, name, handlerMethod support
 * - {param} and {param*} placeholders
 * - route-name → URL resolver for templates
 * - route cache (serialized) for production
 */
final class Router
{
    /** @var list<array{route: Route, action: class-string}> */
    private array $routes = [];

    /** @var list<string> Directories to scan for action classes. */
    private readonly array $actionDirs;

    /** @var array<string, array{route: Route, action: class-string}> Cache of route-name → entry. */
    private array $nameIndex = [];

    /**
     * @param list<string> $actionDirs
     */
    public function __construct(
        array $actionDirs,
        private readonly bool $useCache = false,
        private readonly string $cacheFile = '',
    ) {
        $this->actionDirs = array_values(array_filter($actionDirs, is_dir(...)));
        $this->discover();
    }

    /**
     * Register a route manually (used for testing or explicit registration).
     */
    /**
     * @param class-string $actionClass
     */
    public function register(Route $route, string $actionClass): void
    {
        $entry = ['route' => $route, 'action' => $actionClass];
        $this->routes[] = $entry;

        if (null !== $route->name) {
            $this->nameIndex[$route->name] = $entry;
        }
    }

    /**
     * Match a request to a route.
     *
     * @return array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string}|null
     */
    public function match(Request $request): ?array
    {
        // Literal (placeholder-free) paths win over parameterized patterns:
        // /blog/feed.atom must match the feed route, not /blog/{slug}. Two
        // passes over the routes — first static paths, then patterns.
        $match = $this->matchPass($request, true)
            ?? $this->matchPass($request, false);

        if (null !== $match) {
            return $match;
        }

        // Trailing-slash canonicalization: /blog/ is the same resource as
        // /blog, so a missed literal path ending in '/' (except the bare
        // root) is retried with the slash stripped. On a hit the match
        // carries a 'redirect' marker — the Kernel answers with a 301 to
        // the canonical URL (query string preserved).
        $path = $request->getPathInfo();

        if ('/' === $path || !str_ends_with($path, '/')) {
            return null;
        }

        $canonical = rtrim($path, '/');
        $request->setPathInfo($canonical);
        $match = $this->matchPass($request, true)
            ?? $this->matchPass($request, false);
        $request->setPathInfo($path);

        if (null === $match) {
            return null;
        }

        $query = http_build_query($request->getQueryParams());
        $match['redirect'] = '' === $query ? $canonical : $canonical . '?' . $query;

        return $match;
    }

    /**
     * Match a request against one class of routes (static or parameterized).
     *
     * @return array{route: Route, action: class-string, params: array<string, string>, methodNotAllowed?: bool, redirect?: string}|null
     */
    private function matchPass(Request $request, bool $staticOnly): ?array
    {
        $path = $request->getPathInfo();
        $method = $request->getMethod();

        $pathMatch = null;
        $methodMismatch = false;

        foreach ($this->routes as $entry) {
            $isStatic = !str_contains($entry['route']->path, '{');
            if ($isStatic !== $staticOnly) {
                continue;
            }

            $params = $this->matchPath($entry['route']->path, $path);
            if (null === $params) {
                continue;
            }

            if (in_array($method, explode('|', $entry['route']->method), true)) {
                return [
                    'route'   => $entry['route'],
                    'action'  => $entry['action'],
                    'params'  => $params,
                ];
            }

            // Path matched but method didn't — remember for 405
            $pathMatch ??= $entry;
            $methodMismatch = true;
        }

        if ($methodMismatch && null !== $pathMatch) {
            // Return special marker for 405
            return [
                'route'   => $pathMatch['route'],
                'action'  => $pathMatch['action'],
                'params'  => [],
                'methodNotAllowed' => true,
            ];
        }

        return null;
    }

    /**
     * Resolve a route name to a URL, substituting {param} placeholders.
     *
     * @param array<string, string> $params
     */
    public function url(string $name, array $params = []): string
    {
        $entry = $this->nameIndex[$name] ?? null;
        if (null === $entry) {
            throw new \RuntimeException("Unknown route name: {$name}");
        }

        $url = $entry['route']->path;

        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', (string) $value, $url);
            $url = str_replace('{' . $key . '*}', (string) $value, $url);
        }

        // Remove any remaining unfilled placeholders
        return preg_replace('#\{[^}]+\}#', '', $url) ?? $url;
    }
    public function hasRouteName(string $name): bool
    {
        return isset($this->nameIndex[$name]);
    }

    private function discover(): void
    {
        if ($this->useCache && '' !== $this->cacheFile && is_file($this->cacheFile)) {
            $cached = include $this->cacheFile;
            if (is_array($cached)) {
                /** @var list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}> $cached */
                $this->hydrateFromCache($cached);

                return;
            }
        }

        foreach ($this->actionDirs as $dir) {
            $this->scanDirectory($dir);
        }

        if ($this->useCache && '' !== $this->cacheFile) {
            $this->writeCache();
        }
    }

    private function scanDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $class = $this->pathToClass($file->getPathname());
            if (null === $class || !class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(Route::class);

            foreach ($attributes as $attribute) {
                /** @var Route $route */
                $route = $attribute->newInstance();
                $this->register($route, $class);
            }
        }
    }

    private function pathToClass(string $filePath): ?string
    {
        $relative = null;

        foreach ($this->actionDirs as $dir) {
            $dirReal = realpath($dir);
            $fileReal = realpath($filePath);

            if (false !== $dirReal && false !== $fileReal && str_starts_with($fileReal, $dirReal)) {
                $relative = substr($fileReal, strlen($dirReal) + 1);

                break;
            }
        }

        if (null === $relative) {
            return null;
        }

        $classPath = str_replace(['/', '.php'], ['\\', ''], $relative);

        return 'Modules\\' . $classPath;
    }

    /**
     * Match a route path pattern against a request path.
     *
     * @return array<string, string>|null
     */
    private function matchPath(string $pattern, string $path): ?array
    {
        if ($path === $pattern) {
            return [];
        }

        if (!str_contains($pattern, '{')) {
            return null;
        }

        $regex = preg_replace_callback('#\{(\w+)(\*)?\}#', static fn (array $m): string => ($m[2] ?? '') === '*' ? '(?P<' . $m[1] . '>.+)' : '(?P<' . $m[1] . '>[^/]+)', $pattern);

        if (null === $regex) {
            return null;
        }

        if (1 !== preg_match('#^' . $regex . '$#', $path, $matches)) {
            return null;
        }

        return array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param list<array{path: string, method: string, name: ?string, handlerMethod: ?string, action: class-string}> $entries
     */
    private function hydrateFromCache(array $entries): void
    {
        foreach ($entries as $entry) {
            $route = new Route(
                $entry['path'],
                $entry['method'],
                $entry['name'],
                $entry['handlerMethod'],
            );
            $this->register($route, $entry['action']);
        }
    }

    private function writeCache(): void
    {
        $data = array_map(static function (array $entry): array {
            $route = $entry['route'];

            return [
                'path'          => $route->path,
                'method'        => $route->method,
                'name'          => $route->name,
                'handlerMethod' => $route->handlerMethod,
                'action'        => $entry['action'],
            ];
        }, $this->routes);

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $export = var_export($data, true);
        file_put_contents(
            $this->cacheFile,
            "<?php\n// Route cache — generated by Router. Do not edit manually.\nreturn {$export};\n",
        );
    }
}
