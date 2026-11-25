---
title: "Learning from Thesis, implementing simply ... DI patterns in Nativa"
date: "2026-08-24"
version: "1.0.0"
tags: ["php", "architecture", "dependency-injection", "middleware", "disposal"]
excerpt: "How we implemented Module interface, MiddlewareRegistry and DisposalRegistry inspired by Thesis DIC ... without a single dependency."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "di-implementation"
published: true
---

In the previous article we wrote about the philosophy of evolving DI
patterns from Thesis DIC for Nativa Minimal. Now we've implemented it.

Three files. Zero dependencies. No container.

## What we implemented

Three new building blocks:

| File | Purpose |
|---|---|
| `#[AsMiddleware]` | Attribute for auto-discovery |
| `MiddlewareRegistry` | Auto-discovery of `#[AsMiddleware]` classes |
| `ModuleInterface` | Lightweight contract for modules |
| `DisposalRegistry` | Cleanup callbacks (best-effort) |

## 1. #[AsMiddleware] ... the attribute

Exactly like `#[AsMigrator]` we already have:

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AsMiddleware
{
    public function __construct(
        public int $priority = 100,
    ) {}
}
```

**Usage:**

```php
#[AsMiddleware(priority: 0)]   // first in pipeline
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    // ...
}

#[AsMiddleware(priority: 20)]
final class CsrfMiddleware implements MiddlewareInterface
{
    // ...
}

#[AsMiddleware(priority: 999)] // last in pipeline
final readonly class DebugbarMiddleware implements MiddlewareInterface
{
    // ...
}
```

**Priority:** lower number = earlier in pipeline. Security headers are
first, debugbar is last.

## 2. MiddlewareRegistry ... auto-discovery

Exactly like `ThemeRegistry` ... scans files and discovers classes:

```php
final class MiddlewareRegistry
{
    public static function all(): array
    {
        // Scan src/Modules/*/Interfaces/HTTP/Middleware/
        $dirs = glob($base . '/*/Interfaces/HTTP/Middleware');
        
        foreach ($dirs as $dir) {
            $files = glob($dir . '/*.php');
            foreach ($files as $file) {
                // Check for #[AsMiddleware] attribute
                // Instantiate if no required constructor args
            }
        }
        
        // Sort by priority
        uasort($middlewares, fn($a, $b) => $a[0] <=> $b[0]);
        
        return $middlewares;
    }
}
```

**Test:**

```php
$middlewares = MiddlewareRegistry::all();
// Returns: [SecurityHeaders(0), Csrf(20), Debugbar(999)]
```

**Auto-discovered:** 3 middleware (no required constructor args).

**Manually added:** 3 middleware (with required constructor args):
- `RateLimitMiddleware` ... needs `Closure: RateLimiter`
- `AuthMiddleware` ... needs `Closure: AuthService`
- `LocaleMiddleware` ... needs `LocaleService`

## 3. ModuleInterface ... lightweight contract

Thesis has `Module<T>`. We have `ModuleInterface`:

```php
interface ModuleInterface
{
    /** Unique module identifier */
    public function name(): string;
    
    /** Register middleware, routes, services */
    public function register(Kernel $kernel): void;
    
    /** Module-specific migrations */
    public function migrations(): array;
}
```

**Example:**

```php
final class UsersModule implements ModuleInterface
{
    public function name(): string
    {
        return 'users';
    }
    
    public function register(Kernel $kernel): void
    {
        $kernel->addMiddleware(new AuthMiddleware(
            fn() => CardboardServices::auth(),
        ));
    }
    
    public function migrations(): array
    {
        return [CreateUsersTable::class];
    }
}
```

**Benefits:**
- Standardization ... every module has the same contract
- Testability ... easy to test register() and migrations()
- Auto-discovery ready ... scan for ModuleInterface implementations

## 4. DisposalRegistry ... cleanup callbacks

Thesis has `disposer()`. We have `DisposalRegistry`:

```php
final class DisposalRegistry
{
    public static function register(string $id, callable $disposer): void
    {
        self::$disposers[$id] = $disposer;
    }
    
    public static function disposeAll(?\Throwable $error = null): void
    {
        foreach (self::$disposers as $id => $disposer) {
            try {
                $disposer($error);
            } catch (\Throwable $e) {
                error_log("[DISPOSAL] {$id}: {$e->getMessage()}");
            }
        }
        self::$disposers = [];
    }
}
```

**Usage:**

```php
// Register cleanup
DisposalRegistry::register('db', fn() => $pdo->close());
DisposalRegistry::register('cache', fn() => $cache->flush());

// At end of request
DisposalRegistry::disposeAll();

// On error
try {
    $kernel->handle($request);
} catch (\Throwable $e) {
    DisposalRegistry::disposeAll($e);
}
```

**Best-effort:** All disposers run even if one throws.
Errors are logged but not rethrown.

## 5. UnitOfWork ... transactional consistency

Thesis doesn't have UnitOfWork ... but we do. Simple and effective:

```php
final class UnitOfWork
{
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
```

**Repository integration:**

```php
// Before ... 5 separate DELETEs
public function delete(string $id): void
{
    $this->pdo->prepare('DELETE FROM user_roles ...')->execute([...]);
    $this->pdo->prepare('DELETE FROM password_reset_tokens ...')->execute([...]);
    $this->pdo->prepare('DELETE FROM remember_tokens ...')->execute([...]);
    $this->pdo->prepare('DELETE FROM sessions ...')->execute([...]);
    $this->pdo->prepare('DELETE FROM users ...')->execute([...]);
    // If one fails ... orphan data!
}

// After ... all in one transaction
public function delete(string $id): void
{
    $uow = new UnitOfWork($this->pdo);
    $uow->transaction(function (\PDO $pdo) use ($id): void {
        $pdo->prepare('DELETE FROM user_roles ...')->execute([...]);
        $pdo->prepare('DELETE FROM password_reset_tokens ...')->execute([...]);
        $pdo->prepare('DELETE FROM remember_tokens ...')->execute([...]);
        $pdo->prepare('DELETE FROM sessions ...')->execute([...]);
        $pdo->prepare('DELETE FROM users ...')->execute([...]);
    });
}
```

**Integrated in:**
- `UserRepository::delete()` ... 5 DELETEs atomic
- `RoleRepository::deleteRole()` ... 3 DELETEs atomic
- `RoleRepository::syncPermissions()` ... diff + apply atomic

## 6. syncPermissions ... diff + apply

Instead of manual foreach over current/wanted:

```php
// Before ... non-atomic diff
foreach ($current as $pid) {
    if (!in_array($pid, $wanted, true)) {
        $roles->removePermission($id, $pid);
    }
}
foreach ($wanted as $pid) {
    if (!in_array($pid, $current, true)) {
        $roles->assignPermission($id, $pid);
    }
}

// After ... atomic sync
$roles->syncPermissions($id, $wanted);
```

## How it fits the existing stack

### Before implementation

```
Middleware: manual registration in public/index.php
Migrators: #[AsMigrator] + auto-discovery
Themes: ThemeRegistry + auto-discovery
Module: implicit (namespace-based)
Disposal: none
UnitOfWork: none
```

### After implementation

```
Middleware: #[AsMiddleware] + MiddlewareRegistry (auto-discovery)
Migrators: #[AsMigrator] + DatabaseMigrator (auto-discovery)
Themes: ThemeRegistry + auto-discovery
Module: ModuleInterface (formal contract)
Disposal: DisposalRegistry (cleanup callbacks)
UnitOfWork: transactional consistency (user/role deletes, permission sync)
Tests: 443 tests, 1343 assertions
```

### Kernel integration

```php
// Kernel constructor - auto-discovers #[AsMiddleware]
public function __construct(
    private readonly Router $router,
    bool $autoDiscoverMiddleware = true,
) {
    if ($autoDiscoverMiddleware) {
        foreach (MiddlewareRegistry::all() as $middleware) {
            $this->middleware[] = $middleware;
        }
    } else {
        $this->middleware[] = new SecurityHeadersMiddleware();
    }
}
```

### Request lifecycle integration

```php
// public/index.php - at end of request
$response->send();
DisposalRegistry::disposeAll();
```

## Philosophy

> **Learning from Thesis, implementing simply.**
> No dependencies, just patterns.

Thesis DIC is a great container. We don't need it.

But its **patterns** are universal:

| Pattern | Thesis | Nativa |
|---|---|---|
| Tags | `Tag<T>` + `taggedList()` | `ThemeRegistry` + `MiddlewareRegistry` |
| Module | `Module<T>` interface | `ModuleInterface` (lightweight) |
| Autoconfiguration | `onObject()` callback | `#[As*]` attributes + discovery |
| Disposal | `disposer()` callback | `DisposalRegistry` |

**Key insight:** We don't need a container that controls everything.
We need **patterns** that work without a container.

## Next steps

| Phase | What | Status |
|---|---|---|
| 1 | `#[AsMiddleware]` + `MiddlewareRegistry` |  Done + tests |
| 2 | `ModuleInterface` |  Done |
| 3 | `DisposalRegistry` |  Done + tests |
| 4 | `UnitOfWork` + `syncPermissions` |  Done + tests |
| 5 | `#[As*]` attributes (commands, events) | Proposed |
| 6 | `RequestScope` ... explicit scoping | Proposed |

The patterns remain lightweight, not because we're against containers, but because the starter kit doesn't need one.

---

A container that controls everything controls nothing. Patterns that work without a container work everywhere.

---

→ [DI Evolution](/blog/di-evolution): the philosophy behind the patterns
→ [Theme Architecture](/blog/theme-architecture): auto-discovery in practice

## Sources

- [PHP-FIG, PSR-15 HTTP Server Request Handlers](https://www.php-fig.org/psr/psr-15/): standardized middleware interface
- [PHP Manual, SPLAutoLoader](https://www.php.net/manual/en/function.spl-autoload-register.php): PSR-4 autoloading
- [PHPUnit, Test Isolation](https://phpunit.de/documentation.html): disposal and test cleanup patterns
- [PHPStan, Rule Customization](https://phpstan.org/developing-extensions/rule-customization): static analysis for module boundaries
- [Nativa Minimal, Adding Modules](/docs/adding-modules): official guide for adding modules
