---
title: "Evolúcia DI ... ako Thesis DIC ovplyvňuje Nativa bez závislostí"
date: "2026-08-23"
version: "1.0.3"
tags: ["php", "architecture", "dependency-injection", "thesis", "nativa"]
excerpt: "Ako prevziať dependency injection patterny z Thesis DIC pre Nativa Minimal ... tags, module interface, autoconfiguration ... bez pridania jedinej závislosti."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "di-evolution"
published: true
---

Thesis DIC je malý, názorový dependency injection container pre PHP 8.4.
My máme Nativa Minimal ... zero-dependency runtime bez žiadneho frameworku.
A predsa sa dá z Thesis niečo naučiť a preniesť do nášho stacku.

## Súčasný Nativa pattern

Naše služby používajú „lazy cached service factories“:

```php
final class CardboardServices
{ private static ?UserRepository $users = null; public static function users(): UserRepository { return self::$users ??= new UserRepository( DatabaseConnectionManager::instance()->connection('users'), ); }
}
```

**Výhody:**

- Žiadna závislosť
- Lazy loading (??=)
- Test isolation (reset()) **Problémy:**
- Manuálna registrácia
- Žiadna auto-discovery
- Žiadne tags
- Žiadny formalizovaný module contract

## Čo nás učí Thesis DIC

Thesis má tri kľúčové koncepty:

### 1. Tags ... zoskupovanie služieb

```php
// Thesis
$dic->object(RedisCache::class)->tag(new CacheTag());
$dic->object(ApcuCache::class)->tag(new CacheTag());
$caches = $dic->taggedList(CacheTag::class); // Ref<list<Cache>>
```

### 2. Module ... formálny contract

```php
// Thesis
interface Module<T>
{ public function configure(Dic $dic): mixed;
}
```

### 3. Autoconfiguration ... konvencia namiesto konfigurácie

```php
// Thesis
$dic->onObject(static function (ObjectAutoconfig $object): void { if ($object->attributes->has(AsCommand::class)) { $object->tag(new CommandTag()); }
});
```

## Evolúcia pre Nativa

### 1. Tags → Theme Registry (UŽ MÁME)

Práve sme postavili `ThemeRegistry` ... presne Thesis tag pattern:

```php
// Nativa
final class ThemeRegistry
{ public static function all(): array { // auto-discovery via glob $manifestFiles = glob($base . '/*/Manifest.php'); // ... }
}
```

**Generalizácia:** Tento pattern sa dá použiť pre middleware, commands, migrátory.

### 2. Module Interface → Lightweight Contract

Thesis má `Module<T>`. Nativa má implicitné moduly. **Navrhovaný interface:**

```php
interface Module
{ /** Unique module identifier */ public function name(): string; /** Register middleware, routes, services */ public function register(Kernel $kernel): void; /** Module-specific migrations */ public function migrations(): array;
}
```

**Príklad implementácie:**

```php
final class UsersModule implements Module
{ public function name(): string { return 'users'; } public function register(Kernel $kernel): void { $kernel->addMiddleware(new AuthMiddleware()); } public function migrations(): array { return [ CreateUsersTable::class, CreateRolesTable::class, ]; }
}
```

**Výhoda:** Štandardizácia, auto-discovery, testovateľnosť.

### 3. Attribute-based Registration

Thesis má `onObject()` / `onFunction()`. Nativa má `#[Route]`. **Evolúcia ... ďalšie atribúty:**

```php
// Už máme
#[Route(path: '/blog', method: 'GET')]
class BlogIndexAction { } // Mohli by sme pridať
#[AsMiddleware(priority: 10)]
class SessionMiddleware implements MiddlewareInterface { } #[AsCommand(name: 'migrate')]
class MigrateCommand { } #[AsMigrator(module: 'users', table: 'users')]
class CreateUsersTable implements MigratorInterface { }
```

**Auto-discovery:**

```php
final class AttributeRegistry
{ public static function discover(string $attribute): array { // Scan src/Modules/*/ for classes with $attribute // Return instances }
}
```

### 4. Disposal ... Cleanup Callbacks

Thesis má `disposer()`. Nativa nemá. **Navrhované:**

```php
final class DisposalRegistry
{ private static array $disposers = []; public static function register(string $id, callable $disposer): void { self::$disposers[$id] = $disposer; } public static function disposeAll(?Throwable $error = null): void { foreach (self::$disposers as $id => $disposer) { try { $disposer($error); } catch (\Throwable $e) { error_log("[DISPOSAL] {$id}: {$e->getMessage()}"); } } self::$disposers = []; }
}
```

**Použitie:**

```php
// Na konci requestu
DisposalRegistry::disposeAll(); // Alebo pri chybe
try { $kernel->handle($request);
} catch (\Throwable $e) { DisposalRegistry::disposeAll($e);
}
```

### 5. Scoped Lifetimes ... Explicit Request Scoping

Thesis má `defaultScoped()`. Nativa má implicitný scoping. **Navrhované:**

```php
final class RequestScope
{ private static array $services = []; public static function get(string $id): mixed { return self::$services[$id] ?? null; } public static function set(string $id, mixed $service): void { self::$services[$id] = $service; } public static function run(callable $fn): mixed { self::$services = []; try { return $fn(); } finally { self::$services = []; } }
}
```

## Filozofia: Menej závislostí, viac patternov

Thesis DIC je skvelý container. My ho nepotrebujeme. Ale jeho **patterny** sú univerzálne:

| Pattern | Thesis | Nativa (navrhované) |
|---|---|---|
| Tags | `Tag<T>` + `taggedList()` | `ThemeRegistry` + `MiddlewareRegistry` |
| Module | `Module<T>` interface | `Module` interface (ľahký) |
| Autoconfiguration | `onObject()` callback | `#[As*]` atribúty + discovery |
| Disposal | `disposer()` callback | `DisposalRegistry` |
| Scoped | `defaultScoped()` | `RequestScope` | **Kľúčový insight:** Nepotrebujeme container ktorý všetko riadi.
Potrebujeme **patterny** ktoré fungujú bez containeru.

## Roadmap

| Fáza | Čo | Prečo |
|---|---|---|
| 1 | `TagRegistry` ... generalizácia ThemeRegistry | Middleware, commands, migrátory |
| 2 | `Module` interface | Štandardizácia modulov |
| 3 | `#[As*]` atribúty | Auto-discovery, menej boilerplate |
| 4 | `DisposalRegistry` | Cleanup, test isolation |
| 5 | `RequestScope` | Explicit scoping |

## Zhrnutie

- Thesis DIC nás učí **patterny**, nie závislosti
- ThemeRegistry už je Thesis tag pattern v Nativa štýle
- Module interface štandardizuje ako sa moduly registrujú
- Atribúty znižujú boilerplate
- Disposal a scoping zlepšujú test isolation
- **Všetko bez pridania jedinej závislosti** To je Nativa filozofia: učiť sa z vhodných, implementovať. --- 

→ [DI implementation](/blog/di-implementation): Module interface, MiddlewareRegistry
→ [Capability seams](/blog/capability-seams): ako DSH oddeľuje schopnosti
→ [Flow a ADR](/blog/flow-a-adr-starter-kitu): mindset za starter kitom

## Zdroje

- [Thesis DIC Documentation](https://thesis.coding.lang/): oficiálna dokumentácia Thesis dependency injection container
- [PHP-FIG, PSR-11 Container Interface](https://www.php-fig.org/psr/psr-11/): štandardizovaný container interface
- [PHP Manual, Attributes](https://www.php.net/manual/en/language.attributes.php): PHP 8 natívne atribúty
- [Martin Fowler, Inversion of Control](https://martinfowler.com/articles/injection.html): klasický článok o IoC a DI patternoch
- [PHPStan, Extension Points](https://phpstan.org/developing-extensions/phpstan-basics): static analysis pre DI patterny
