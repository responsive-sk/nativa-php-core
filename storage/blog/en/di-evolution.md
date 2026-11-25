---
title: "DI Evolution ... how Thesis DIC influences Nativa without dependencies"
date: "2026-08-23"
version: "1.0.0"
tags: ["php", "architecture", "dependency-injection", "thesis", "nativa"]
excerpt: "How to evolve dependency injection patterns from Thesis DIC for Nativa Minimal ... tags, module interface, autoconfiguration ... without adding a single dependency."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "di-evolution"
published: true
---

Thesis DIC is a small, opinionated dependency injection container for PHP 8.4.
We have Nativa Minimal ... a zero-dependency runtime without any framework.
And yet, there's something to learn from Thesis and evolve into our stack.

## The current Nativa pattern

Our services use "lazy cached service factories":

```php
final class CardboardServices
{
    private static ?UserRepository $users = null;
    
    public static function users(): UserRepository
    {
        return self::$users ??= new UserRepository(
            DatabaseConnectionManager::instance()->connection('users'),
        );
    }
}
```

**Pros:**
- Zero dependencies
- Lazy loading (??=)
- Test isolation (reset())

**Cons:**
- Manual registration
- No auto-discovery
- No tags
- No formalized module contract

## What Thesis DIC teaches us

Thesis has three key concepts:

### 1. Tags ... grouping services

```php
// Thesis
$dic->object(RedisCache::class)->tag(new CacheTag());
$dic->object(ApcuCache::class)->tag(new CacheTag());
$caches = $dic->taggedList(CacheTag::class); // Ref<list<Cache>>
```

### 2. Module ... formal contract

```php
// Thesis
interface Module<T>
{
    public function configure(Dic $dic): mixed;
}
```

### 3. Autoconfiguration ... convention over configuration

```php
// Thesis
$dic->onObject(static function (ObjectAutoconfig $object): void {
    if ($object->attributes->has(AsCommand::class)) {
        $object->tag(new CommandTag());
    }
});
```

## Evolution for Nativa

### 1.  Tags → Theme Registry (ALREADY DONE)

We just built `ThemeRegistry` ... exactly the Thesis tag pattern:

```php
// Nativa
final class ThemeRegistry
{
    public static function all(): array
    {
        // auto-discovery via glob
        $manifestFiles = glob($base . '/*/Manifest.php');
        // ...
    }
}
```

**Generalization:** This pattern can be used for middleware, commands, migrations.

### 2.  Module Interface → Lightweight Contract

Thesis has `Module<T>`. Nativa has implicit modules.

**Proposed interface:**

```php
interface Module
{
    /** Unique module identifier */
    public function name(): string;
    
    /** Register middleware, routes, services */
    public function register(Kernel $kernel): void;
    
    /** Module-specific migrations */
    public function migrations(): array;
}
```

**Implementation example:**

```php
final class UsersModule implements Module
{
    public function name(): string
    {
        return 'users';
    }
    
    public function register(Kernel $kernel): void
    {
        $kernel->addMiddleware(new AuthMiddleware());
    }
    
    public function migrations(): array
    {
        return [
            CreateUsersTable::class,
            CreateRolesTable::class,
        ];
    }
}
```

**Benefit:** Standardization, auto-discovery, testability.

### 3.  Attribute-based Registration

Thesis has `onObject()` / `onFunction()`. Nativa has `#[Route]`.

**Evolution ... more attributes:**

```php
// Already have
#[Route(path: '/blog', method: 'GET')]
class BlogIndexAction { }

// Could add
#[AsMiddleware(priority: 10)]
class SessionMiddleware implements MiddlewareInterface { }

#[AsCommand(name: 'migrate')]
class MigrateCommand { }

#[AsMigrator(module: 'users', table: 'users')]
class CreateUsersTable implements MigratorInterface { }
```

**Auto-discovery:**

```php
final class AttributeRegistry
{
    public static function discover(string $attribute): array
    {
        // Scan src/Modules/*/ for classes with $attribute
        // Return instances
    }
}
```

### 4.  Disposal ... Cleanup Callbacks

Thesis has `disposer()`. Nativa doesn't.

**Proposed:**

```php
final class DisposalRegistry
{
    private static array $disposers = [];
    
    public static function register(string $id, callable $disposer): void
    {
        self::$disposers[$id] = $disposer;
    }
    
    public static function disposeAll(?Throwable $error = null): void
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
// At end of request
DisposalRegistry::disposeAll();

// Or on error
try {
    $kernel->handle($request);
} catch (\Throwable $e) {
    DisposalRegistry::disposeAll($e);
}
```

### 5.  Scoped Lifetimes ... Explicit Request Scoping

Thesis has `defaultScoped()`. Nativa has implicit.

**Proposed:**

```php
final class RequestScope
{
    private static array $services = [];
    
    public static function get(string $id): mixed
    {
        return self::$services[$id] ?? null;
    }
    
    public static function set(string $id, mixed $service): void
    {
        self::$services[$id] = $service;
    }
    
    public static function run(callable $fn): mixed
    {
        self::$services = [];
        try {
            return $fn();
        } finally {
            self::$services = [];
        }
    }
}
```

## Philosophy: fewer dependencies, more patterns

Thesis DIC is a great container. We don't need it.

But its **patterns** are universal:

| Pattern | Thesis | Nativa (proposed) |
|---|---|---|
| Tags | `Tag<T>` + `taggedList()` | `ThemeRegistry` + `MiddlewareRegistry` |
| Module | `Module<T>` interface | `Module` interface (lightweight) |
| Autoconfiguration | `onObject()` callback | `#[As*]` attributes + discovery |
| Disposal | `disposer()` callback | `DisposalRegistry` |
| Scoped | `defaultScoped()` | `RequestScope` |

**Key insight:** We don't need a container that controls everything.
We need **patterns** that work without a container.

## Roadmap

| Phase | What | Why |
|---|---|---|
| 1 | `TagRegistry` ... generalize ThemeRegistry | Middleware, commands, migrations |
| 2 | `Module` interface | Standardize modules |
| 3 | `#[As*]` attributes | Auto-discovery, less boilerplate |
| 4 | `DisposalRegistry` | Cleanup, test isolation |
| 5 | `RequestScope` | Explicit scoping |

## Summary

- Thesis DIC teaches us **patterns**, not dependencies
- ThemeRegistry is already the Thesis tag pattern in Nativa style
- Module interface standardizes how modules register
- Attributes reduce boilerplate
- Disposal and scoping improve test isolation
- **All without adding a single dependency**

That's the Nativa philosophy: learn from the best, implement simply.

---

→ [Theme Architecture](/blog/theme-architecture): auto-discovery in practice
→ [Theme Comparison](/blog/theme-comparison): three themes, one pattern

## Sources

- [Thesis DIC Documentation](https://thesis.coding.lang/): official Thesis dependency injection container documentation
- [PHP-FIG, PSR-11 Container Interface](https://www.php-fig.org/psr/psr-11/): standardized container interface
- [PHP Manual, Attributes](https://www.php.net/manual/en/language.attributes.php): PHP 8 native attributes
- [Martin Fowler, Inversion of Control](https://martinfowler.com/articles/injection.html): classic article on IoC and DI patterns
- [PHPStan, Extension Points](https://phpstan.org/developing-extensions/phpstan-basics): static analysis for DI patterns
