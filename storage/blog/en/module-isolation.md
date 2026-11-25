---
title: "Clean code for kids ... part 6: module isolation ... who can talk to whom (C-pattern boundaries)"
date: "2026-08-11"
version: "1.0.0"
tags: ["clean-code", "php", "architecture", "modules", "boundaries"]
excerpt: "The final part of the series. Clean code doesn't end at methods ... it ends at module boundaries. Part 6 explains why Domain doesn't know about the database, how ModuleIsolationTest guards imports and why Core + Database are the starter kit's 'libc'."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "modulova-izolacia-pre-deti"
published: true
---

> This is part 6 ... the **final part** of the **Clean code for kids**
> series. Previous parts: [1 ... what is clean code](/en/blog/clean-code-part-1),
> [2 ... names are documentation](/en/blog/names-are-documentation),
> [3 ... one thing (SRP)](/en/blog/one-thing-srp),
> [4 ... DRY vs premature abstraction](/en/blog/dry-vs-abstraction)
> and [5 ... smoke tests](/en/blog/smoke-tests). All the rules we
> learned live inside one file or one class. Today we take a step back
> and look at a **bigger scale**: the boundaries between modules.
> Because clean code doesn't end at a method ... it ends at **who can
> talk to whom.**

## The apartment block: every flat has its own pipes

Imagine an apartment block. Every flat has its own pipes, its own
electricity, its own walls. When your bathroom leaks, you don't break
into the neighbor's bathroom ... you call your plumber and they fix
**your** flat. The neighbor doesn't help by running pipes through your
living room.

Modules in code are flats in an apartment block. Every module (e.g.
`Users`, `Settings`, `Locales`) has its own layers ... and those layers
have precisely defined what they may do. Boundaries aren't bureaucracy.
They're **points where the world changes**: when you replace the pipes
in one flat, the other flats shouldn't feel it.

## Three layers of a module: what each does

Every feature module in this repo has three layers. Look at `Users`:

```
src/Modules/Users/
├── Domain/          # pure logic ... rules, entities, "what it means to be a user"
├── Application/     # use cases ... orchestration, services
└── Infrastructure/  # database, repositories, concrete technologies
    └── Interfaces/  # HTTP ... actions, middleware ("Interfaces" = interface with the world)
```

And now the most important thing ... **which layer may import what**:

| Layer | What it may use | What it must NOT |
|---|---|---|
| `Domain` | only itself (pure PHP, no imports) | **Infrastructure and Interfaces ... its own or others'** |
| `Application` | its own Domain + the shared kernel (Core + Database) | other modules' Infrastructure/Interfaces |
| `Infrastructure`/`Interfaces` | anything (it's the module's "outside world") | ... |

Notice the first row. **`Domain` imports nothing at all.** That's the
strictest rule ... and the most important.

## Real example: the `User` entity doesn't know about the database

Look at the `User` entity in `src/Modules/Users/Domain/Entities/` ... the
whole file, including the header:

```php
<?php

declare(strict_types = 1);

namespace Modules\Users\Domain\Entities;

/**
 * User domain entity.
 */
final class User
{
    /** @var list<string> role names */
    private array $roles = [];

    // …

    public function __construct(private array $data)
    {
        $active = $data['is_active'] ?? 1;
        $this->isActive = 1 === (is_numeric($active) ? (int) $active : 0);
    }
}
```

Notice what's **missing**: no `use` statement. No
`DatabaseConnectionManager`, no `PDO`, no SQL. `User` receives a plain
array and can turn it into its logic. How does it know it's active?
From the array, not from the database.

Why is this good? **Because user logic doesn't change when the storage
changes.** Whether the data is in SQLite, a JSON file or the cloud ...
the rule "a user is active when `is_active` is 1" stays the same. If
`User` knew the database, every storage change would force you to touch
the logic. A boundary = a point where the world changes ... and we put it
exactly where it belongs.

So where does the database live? In the `Infrastructure` layer ... in
`UserRepository`:

```php
// src/Modules/Users/Infrastructure/Persistence/Repositories/UserRepository.php
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }
    // SELECT * FROM users WHERE … → new User($row)
}
```

`UserRepository` knows about `PDO` and SQL. `User` knows about nothing.
Two classes, two worlds ... and between them exactly one boundary.

## How the test guards boundaries

Of course, this repo enforces it with a test ... we learned that in
[part 5](/en/blog/smoke-tests). The test is called
`ModuleIsolationTest` and does exactly what an inspector in the
apartment block would do: it walks all the modules and looks at every
`use` statement.

```php
// tests/Architecture/ModuleIsolationTest.php ... simplified
foreach ($files as $file) {
    // The check only concerns Domain and Application layers
    $isDomainOrApp = str_contains($relativePath, '/Domain/')
        || str_contains($relativePath, '/Application/');
    if (!$isDomainOrApp) {
        continue;
    }

    foreach ($lines as $line) {
        if (preg_match('/use\s+(Modules\\\\(.+)\\\\(Infrastructure|Interfaces))/', $line, $matches)) {
            $importedModule = explode('\\', $matches[1])[1] ?? '';

            // Application may use the shared kernel (Core + Database)…
            if (('Core' === $importedModule || 'Database' === $importedModule) && !$isDomainLayer) {
                continue;
            }

            // …but never another module, and Domain never anything from Infrastructure
            if ($importedModule !== $moduleName) {
                $violations[] = …;
            }
        }
    }
}
```

Let's break it down:

1. **It scans only Domain and Application** ... Infrastructure and
   Interfaces are the module's "outside world", it may import what it
   needs. The check is exactly where the boundary hurts.
2. **The regex looks for `use Modules\…\Infrastructure` and
   `…\Interfaces`** ... not just any import. The point isn't "a module
   must not know another module" (that would be unrealistic), but "a
   module must not reach **inside** another module".
3. **Core and Database are the exception for Application** ... that's the
   shared kernel, the starter kit's "libc". Every module may use
   `AppPaths`, `DatabaseConnectionManager`, `FrontendLayout`…
4. **Domain has no exception** ... `$isDomainLayer` switches off even
   Core/Database. Domain is strictly pure. Full stop.

And when the test finds a violation, it says exactly who and how:

```
Module "Locales" violates DDD boundaries by importing
Modules\Cardboard\Interfaces in Application/Services/SomeService.php
```

A message you can't mishear: "module X reached inside module Y in this
class". The fix is always the same ... move the dependency where it
belongs, or resolve it through the shared kernel.

## Why `Core` is "libc"

Let's get back to "Core + Database are the shared kernel". What does
that mean in practice? Look at `BlogArticlesService` from the previous
parts ... it's in `src/Modules/Core/Application/Services/` and imports:

```php
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
```

All three imports are **from its own module, Core**. That's allowed ... a
module can use itself. But `BlogArticlesService` is also used by other
parts of the project: `BlogIndexAction`, `AuthorProfileAction`… And
they don't need any special permission to use it, because **Core is the
common language of the whole application** ... like the standard library
in C (hence "libc").

`AppPaths` (file paths), `DatabaseConnectionManager` (database
connections), `FrontendLayout` (HTML layout) ... these are things
**every** module needs. If each module did them its own way, we'd have
seven different ways of finding a file path ... and that's exactly what
we fought against in [part 4](/en/blog/dry-vs-abstraction). The
shared kernel is the one source of truth for cross-cutting concerns.

## Why a test, not trust

And now the question we asked in every part: why enforce it with a
test? Because boundaries are **silent**. When someone in Application of
`Locales` imports `Cardboard\Interfaces` to save three lines ... the code
keeps running, the tests pass, nobody notices anything. Only six months
later, when `Cardboard` changes its internals, `Locales` breaks ... a
foreign module that has nothing to do with it.

A module is a flat: its pipes should be fixed by its plumber. When a
neighbor taps into someone else's pipes, nothing leaks today ... but it
bursts tomorrow, in a flat that knows nothing about it. The test is
insurance that it won't happen: boundaries aren't a recommendation,
they're a red test (part 5).

## Map of the whole series

And with that we're at the end. Let's put the whole series into one
table ... six principles, six measures, six tests that enforce them:

| Part | Principle | Measure | Test that enforces it |
|---|---|---|---|
| [1](/en/blog/clean-code-part-1) | Code is read more than written; story over detail | all of `src/` | `CorePatternTest` |
| [2](/en/blog/names-are-documentation) | Names are documentation for free | names of methods, variables, classes | readability (chunky test) |
| [3](/en/blog/one-thing-srp) | One method, one class ... one thing | reason to change | chunky rule + `handle()` |
| [4](/en/blog/dry-vs-abstraction) | DRY = one place of truth, no interfaces in reserve | number of copies, implementations | no-speculative-interface, baseline 0 |
| [5](/en/blog/smoke-tests) | Rules enforced by a test = smoke tests | tokens, not text | detectors in `CorePatternTest` |
| [6](/en/blog/module-isolation) | Who can talk to whom | imports between modules | `ModuleIsolationTest` |

Notice how the measure grows: parts 1–2 talked about lines and names,
parts 3–4 about methods and classes, part 5 about the tests that guard
it all, and part 6 about the boundaries between modules. Clean code is
**layered**: it starts at a single line and ends at how whole modules
talk to each other.

And if you also read the [types series](/en/blog/chunky-rule),
you'll recognize the same across both: there we typed data, here we
shape code. Both series meet at one point ... **everything can be
enforced by a test**, and when a rule is automatic, you don't have to
discuss it.

## Series conclusion

Six parts, six principles, one philosophy:

1. **Code is read ten times more often than it's written** ... you write
   it for the reader, not for the compiler ([part 1](/en/blog/clean-code-part-1)).
2. **Names are documentation for free** ... a good name frees you from
   reading the body ([part 2](/en/blog/names-are-documentation)).
3. **One thing in one place** ... method, class, module: every measure
   has one reason to change ([part 3](/en/blog/one-thing-srp)).
4. **DRY yes, premature abstraction no** ... one place of truth,
   interfaces only when the second implementation arrives ([part 4](/en/blog/dry-vs-abstraction)).
5. **A rule without a test isn't followed** ... detectors guard the rules
   for you, token by token ([part 5](/en/blog/smoke-tests)).
6. **Boundaries are points where the world changes** ... Domain doesn't
   know about the database, modules don't reach into each other's pipes
   ([part 6](/en/blog/module-isolation)).

And the most important thing at the end: **clean code isn't aesthetics.
It's respect for the reader.** Every name, every boundary, every test
in this repo exists for one person ... for the one who comes after you in
six months and tries to understand what you meant. Clean heads, clean
data, clean boundaries.

→ [Smoke tests](/en/blog/smoke-tests): rules enforced by a test = warning smoke signals
→ [DRY vs abstraction](/en/blog/dry-vs-abstraction): one source of truth
→ [Clean code for kids](/en/blog/clean-code-part-1): respect for the reader

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
