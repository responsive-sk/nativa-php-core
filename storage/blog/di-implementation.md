---
title: "Učíme sa z Thesis, implementujeme ... DI patterny v Nativa"
date: "2026-08-24"
version: "1.0.3"
tags: ["php", "architecture", "dependency-injection", "middleware", "disposal"]
excerpt: "Ako sme implementovali Module interface, MiddlewareRegistry a DisposalRegistry inšpirované Thesis DIC ... bez jedinej závislosti."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "di-implementation"
published: true
---

V minulom článku sme písali o filozofii evolúcie DI patternov z Thesis
DIC pre Nativa Minimal. Teraz sme to implementovali.

## Čo sme implementovali

Tri nové stavebné kúsky:

| Subor | Účel |
|---|---|
| `#[AsMiddleware]` | Atribút pre auto-discovery middleware |
| `MiddlewareRegistry` | Auto-discovery `#[AsMiddleware]` tried |
| `ModuleInterface` | Ľahký contract pre moduly |
| `DisposalRegistry` | Cleanup callbacks (best-effort) |

## 1. #[AsMiddleware] ... atribút

Presne ako `#[AsMigrator]` ktorý už máme:

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AsMiddleware
{ public function __construct( public int $priority = 100, ) {}
}
```

**Použitie:**

```php
#[AsMiddleware(priority: 0)] // prvý v pipeline
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{ // ...
} #[AsMiddleware(priority: 20)]
final class CsrfMiddleware implements MiddlewareInterface
{ // ...
} #[AsMiddleware(priority: 999)] // posledný v pipeline
final readonly class DebugbarMiddleware implements MiddlewareInterface
{ // ...
}
```

**Priority:** nižšie číslo = skôr v pipeline. Security headers sú prvé,
debugbar je posledný.

## 2. MiddlewareRegistry ... auto-discovery

Presne ako `ThemeRegistry` ... scanuje súbory a objavuje triedy:

```php
final class MiddlewareRegistry
{ public static function all(): array { // Scan src/Modules/*/Interfaces/HTTP/Middleware/ $dirs = glob($base . '/*/Interfaces/HTTP/Middleware'); foreach ($dirs as $dir) { $files = glob($dir . '/*.php'); foreach ($files as $file) { // Check for #[AsMiddleware] attribute // Instantiate if no required constructor args } } // Sort by priority uasort($middlewares, fn($a, $b) => $a[0] <=> $b[0]); return $middlewares; }
}
```

**Test:**

```php
$middlewares = MiddlewareRegistry::all();
// Returns: [SecurityHeaders(0), Csrf(20), Debugbar(999)]
```

**Automaticky objavené:** 3 middleware (bez required constructor args).

**Manuálne pridané:** 3 middleware (s required constructor args):

- `RateLimitMiddleware` ... potrebuje `Closure: RateLimiter`
- `AuthMiddleware` ... potrebuje `Closure: AuthService`
- `LocaleMiddleware` ... potrebuje `LocaleService`

## 3. ModuleInterface ... ľahký contract

Thesis má `Module<T>`. My máme `ModuleInterface`:

```php
interface ModuleInterface
{ /** Unique module identifier */ public function name(): string; /** Register middleware, routes, services */ public function register(Kernel $kernel): void; /** Module-specific migrations */ public function migrations(): array;
}
```

**Príklad:**

```php
final class UsersModule implements ModuleInterface
{ public function name(): string { return 'users'; } public function register(Kernel $kernel): void { $kernel->addMiddleware(new AuthMiddleware( fn() => CardboardServices::auth(), )); } public function migrations(): array { return [CreateUsersTable::class]; }
}
```

**Výhody:**

- Štandardizácia ... každý modul má rovnaký contract
- Testovateľnosť ... ľahko otestuješ register() a migrations()
- Auto-discovery pripravený ... scanujeme `ModuleInterface` implementácie

## 4. DisposalRegistry ... cleanup callbacks

Thesis má `disposer()`. My máme `DisposalRegistry`:

```php
final class DisposalRegistry
{ public static function register(string $id, callable $disposer): void { self::$disposers[$id] = $disposer; } public static function disposeAll(?\Throwable $error = null): void { foreach (self::$disposers as $id => $disposer) { try { $disposer($error); } catch (\Throwable $e) { error_log(„[DISPOSAL] {$id}: {$e->getMessage()}“); } } self::$disposers = []; }
}
```

**Použitie:**

```php
// Registrovať cleanup
DisposalRegistry::register('db', fn() => $pdo->close());
DisposalRegistry::register('cache', fn() => $cache->flush()); // Na konci requestu
DisposalRegistry::disposeAll(); // Pri chybe
try { $kernel->handle($request);
} catch (\Throwable $e) { DisposalRegistry::disposeAll($e);
}
```

**Best-effort:** Všetky dispobery sa spustia aj keď jeden hodí chybu.
Chyby sa logujú ale nerethrowujú.

## 5. UnitOfWork ... transakčná konzistencia

Thesis nemá UnitOfWork ... ale my áno. Jednoduchý a efektívny:

```php
final class UnitOfWork
{ public function transaction(callable $fn): mixed { $this->pdo->beginTransaction(); try { $result = $fn($this->pdo); $this->pdo->commit(); return $result; } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; } }
}
```

**Použitie v repository:**

```php
// Pred ... 5 samostatných DELETEs
public function delete(string $id): void
{ $this->pdo->prepare('DELETE FROM user_roles ...')->execute([...]); $this->pdo->prepare('DELETE FROM password_reset_tokens ...')->execute([...]); $this->pdo->prepare('DELETE FROM remember_tokens ...')->execute([...]); $this->pdo->prepare('DELETE FROM sessions ...')->execute([...]); $this->pdo->prepare('DELETE FROM users ...')->execute([...]); // Ak jedno zlyhá ... orphan data!
} // Po ... všetko v jednej transakcii
public function delete(string $id): void
{ $uow = new UnitOfWork($this->pdo); $uow->transaction(function (\PDO $pdo) use ($id): void { $pdo->prepare('DELETE FROM user_roles ...')->execute([...]); $pdo->prepare('DELETE FROM password_reset_tokens ...')->execute([...]); $pdo->prepare('DELETE FROM remember_tokens ...')->execute([...]); $pdo->prepare('DELETE FROM sessions ...')->execute([...]); $pdo->prepare('DELETE FROM users ...')->execute([...]); });
}
```

**Integrované v:**

- `UserRepository::delete()` ... 5 DELETEs atomic
- `RoleRepository::deleteRole()` ... 3 DELETEs atomic
- `RoleRepository::syncPermissions()` ... diff + apply atomic

## 6. syncPermissions ... diff + apply

Namiesto manuálneho foreach cez current/wanted:

```php
// Pred ... non-atomic diff
foreach ($current as $pid) { if (!in_array($pid, $wanted, true)) { $roles->removePermission($id, $pid); }
}
foreach ($wanted as $pid) { if (!in_array($pid, $current, true)) { $roles->assignPermission($id, $pid); }
} // Po ... atomic sync
$roles->syncPermissions($id, $wanted);
```

## Ako to zapadá do existujúceho stacku

### Pred implementáciou

```
Middleware: manuálna registrácia v public/index.php
Migrátory: #[AsMigrator] + auto-discovery
Témy: ThemeRegistry + auto-discovery
Module: implicit (namespace-based)
Disposal: žiadny
UnitOfWork: žiadny
```

### Po implementácii

```
Middleware: #[AsMiddleware] + MiddlewareRegistry (auto-discovery)
Migrátory: #[AsMigrator] + DatabaseMigrator (auto-discovery)
Témy: ThemeRegistry + auto-discovery
Module: ModuleInterface (formálny contract)
Disposal: DisposalRegistry (cleanup callbacks)
UnitOfWork: transakčná konzistencia (user/role deletes, permission sync)
Testy: 443 testov, 1343 asercií
```

### Integrácia do Kernelu

```php
// Kernel constructor - auto-discovers #[AsMiddleware]
public function __construct( private readonly Router $router, bool $autoDiscoverMiddleware = true,
) { if ($autoDiscoverMiddleware) { foreach (MiddlewareRegistry::all() as $middleware) { $this->middleware[] = $middleware; } } else { $this->middleware[] = new SecurityHeadersMiddleware(); }
}
```

### Integrácia do request lifecycle

```php
// public/index.php - na konci requestu
$response->send();
DisposalRegistry::disposeAll();
```

## Philosophy

> **Učíme sa z Thesis, implementujeme.**
> Žiadne závislosti, len patterny.

Thesis DIC je skvelý container. My ho nepotrebujeme. Ale jeho **patterny** sú univerzálne:

| Pattern | Thesis | Nativa |
|---|---|---|
| Tags | `Tag<T>` + `taggedList()` | `ThemeRegistry` + `MiddlewareRegistry` |
| Module | `Module<T>` interface | `ModuleInterface` (ľahký) |
| Autoconfiguration | `onObject()` callback | `#[As*]` atribúty + discovery |
| Disposal | `disposer()` callback | `DisposalRegistry` | **Kľúčový insight:** Nepotrebujeme container, ktorý všetko riadi.
Potrebujeme **patterny**, ktoré fungujú bez containeru.

## Ďalšie kroky

| Fáza | Čo | Stav |
|---|---|---|
| 1 | `#[AsMiddleware]` + `MiddlewareRegistry` | Hotové + testy |
| 2 | `ModuleInterface` | Hotové |
| 3 | `DisposalRegistry` | Hotové + testy |
| 4 | `UnitOfWork` + `syncPermissions` | Hotové + testy |
| 5 | `#[As*]` atribúty (commands, events) | Navrhnuté |
| 6 | `RequestScope` ... explicit scoping | Navrhnuté | --- 

→ [DI evolúcia](/blog/di-evolution): filozofia za patternami
→ [Module isolation](/blog/modulova-izolacia-pre-deti): kto s kým môže rozprávať
→ [Flow a ADR](/blog/flow-a-adr-starter-kitu): mindset za starter kitom

## Zdroje

- [PHP-FIG, PSR-15 HTTP Server Request Handlers](https://www.php-fig.org/psr/psr-15/): štandardizovaný middleware interface
- [PHP Manual, SPLAutoLoader](https://www.php.net/manual/en/function.spl-autoload-register.php): PSR-4 autoloading
- [PHPUnit, Test Isolation](https://phpunit.de/documentation.html): disposal a test cleanup patterny
- [PHPStan, Rule Customization](https://phpstan.org/developing-extensions/rule-customization): static analysis pre module boundaries
- [Nativa Minimal, Adding Modules](/docs/adding-modules): oficiálny návod na pridávanie modulov
