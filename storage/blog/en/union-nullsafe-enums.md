---
title: "PHP types for kids ... part 5: union types, nullsafe and enums (when one value isn't just one)"
date: "2026-08-09"
version: "1.0.0"
tags: ["php", "types", "enums", "clean-code", "phpstan"]
excerpt: "What if a value can be several things at once? Part 5 of the series: union types (Locale|string), nullsafe ?-> and enums ... on real examples from the starter kit, and why there are no enums there yet."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "union-nullsafe-enumy-pre-deti"
published: true
---

> This is part 5 of the **PHP types for kids** series. Previous parts:
> [part 1 ... what's behind a variable](/en/blog/php-types-part-1),
> [part 2 ... scalar casts](/en/blog/scalar-casts),
> [part 3 ... type strictly before iterating](/en/blog/type-before-iterating)
> and [part 4 ... array vs list, DTO and mixed](/en/blog/array-list-dto).
> Today we'll talk about what happens when one value **can be several
> things at once** ... and how to make order of it. In [part 6](/en/blog/chunky-rule)
> we close the series with the chunky rule.

## Last time we said…

In [part 4](/en/blog/array-list-dto) we found that `mixed` = "I
don't know what it is" ... and level 9 forces you to narrow it. But what
if you **know exactly** that it can be one of two (or three) concrete
things? That's a **union type** ... and it's much better news than
`mixed`.

## Union types ... "either, or, but I know exactly which"

A union type says: **this value is EITHER type A, OR type B** ... and
nothing else. In PHP it's written with `|`:

```php
function setLocale(Locale | string $locale): void { … }
// $locale is EITHER a Locale object, OR a string ... and nothing else
```

In the starter kit it's live in `LocaleService::set()`:

```php
public function set(Locale | string $locale): void
{
    $this->currentLocale = is_string($locale) ? Locale::fromString($locale) : $locale;
}
```

Notice two things:

1. **The contract is precise** ... `Locale | string` is much stronger
   than `mixed`. PHPStan knows it's not `int`, `array` or `null`.
2. **Narrowing is unambiguous** ... one `is_string()` is enough and
   PHPStan already knows that in the `else` branch it's a `Locale`. No
   further checks.

Another real example ... migrators accept `CliOutput | OutputInterface`
(their own output or Symfony):

```php
public function run(CliOutput | OutputInterface $output): void { … }
```

That's nice: **two different types that share a `writeln()` method** ...
a union type expresses it without needless abstraction (no interface
for the sake of two classes!).

## Union types and level 9 ... the rules of the game

| Union | What it means | Watch out for |
|---|---|---|
| `Locale \| string` | a Locale object or a string | narrowing via `is_string()` / `instanceof` |
| `int \| float` | "a number" (whole or decimal) | `is_numeric()` isn't enough for both |
| `string \| null` | "maybe a string" (= `?string`) | `??`, `is_string()` |
| `self \| null` | "maybe an object" | `instanceof`, `?->` |

How do you work with a union? **Narrowing** ... making it more specific
to one member:

```php
// Bad: guess what it is, via mixed
function log(mixed $value): void {
    echo (string) $value;  // could be anything
}

// Good: say exactly what you accept
function log(string | int | float $value): void {
    echo (string) $value;  // level 9: I know it's a scalar
}
```

And what about `int | float`? PHP can compare them directly ... but
careful, **an `(int)` cast on a float** chops the decimal part
(part 2!). Sometimes it's better to just say what you want:

```php
// They're all "numbers" ... PHPStan lets you compare
function bigger(int | float $a, int | float $b): int | float
{
    return $a > $b ? $a : $b;
}
```

## Nullsafe `?->` ... navigating "maybe nothing"

`string | null` is so common it has its own shorthand: `?string`. And
when you have a chain of objects where each can be `null`, a pyramid of
`if` checks awaits you:

```php
// Without nullsafe ... a pyramid of null checks
$city = null;
if (null !== $user) {
    if (null !== $user->address()) {
        $city = $user->address()->city();
    }
}

// With nullsafe ... breaks at the first null
$city = $user?->address()?->city();
```

`?->` means: **"if the left side is null, the whole thing is null ...
otherwise continue"**. No error, no `if`.

> **But careful** ... in the starter kit you **won't find `?->` on
> purpose**. Why? The C-pattern rule: **explicit is better than
> magical**. `?->` silently swallows a null and you don't know where
> the chain broke. Moreover at level 9, `$user?->address()?->city()`
> is of type `?string` ... and you have to handle it anyway. The golden
> middle path: nullsafe **for reading deep structures**, explicit `if`
> where null is **meaningful** (not just "unset").

## Enums ... when "string" means only a few values

And now the best part. The union `string | null` handles "maybe
nothing". But what if a string has **only 5 allowed values**? For
example a theme color: `'dark'`, `'light'` ... and nothing else. A string
can't say that:

```php
function setTheme(string $theme): void { … }

setTheme('dark');     // OK
setTheme('neon');     // OK?? ... and this is the problem
```

An **enum** (since PHP 8.1) = **a closed list of values**. The compiler
and PHPStan both know what exists:

```php
enum Theme
{
    case Dark;
    case Light;

    public function label(): string
    {
        return match ($this) {
            self::Dark  => 'Dark',
            self::Light => 'Light',
        };
    }
}

function setTheme(Theme $theme): void { … }

setTheme(Theme::Dark);
setTheme('neon');        // TypeError! ... a string doesn't belong here
```

## Why there are no enums in the starter kit yet

Interesting: the codebase has **zero enums**. Not because they're bad ...
but because the patterns enums solve haven't "grown up" here yet:

| Place | Today | Would an enum fit? |
|---|---|---|
| `Locale` VO | `final readonly` class with an `AVAILABLE` map | basically already enum-like: `sk_SK`, `en_US` ... but it needs format validation and `__toString`, which a VO does better |
| Theme (`dark`/`light`) | a string in `data-theme` | an exact candidate for a `Theme` enum |
| `MenuService` (`header`/`footer`) | a string location | candidate for a `MenuLocation` enum |
| Settings `value_type` | string `'boolean'`/`'integer'`/`'json'`/`'string'` | candidate for a `SettingType` enum |

So why not do it right away? **YAGNI** ... the rule "don't add an
abstraction until it's needed". An enum makes sense when:

1. The values are **few and closed** (not user data)
2. The values **travel** between layers (not a local string)
3. **Logic depends** on the value (`match ($this)`)

When `Theme` or `MenuLocation` start leaking into more than two files ...
that's the moment for an enum. Until then, a `'dark'` string is
perfectly fine, as long as it's validated **at the boundary**
(part 2!).

## What `never` means

One more small thing ... the `never` return type (PHP 8.1): **a function
that never returns a value, because it always throws an exception or
ends the program**:

```php
function abort(string $message): never
{
    throw new \RuntimeException($message);
}

function findUser(int $id): User
{
    $user = $this->repo->find($id);
    if (null === $user) {
        abort("User $id not found");  // never ... PHPStan knows it won't continue
    }

    return $user;  // PHPStan knows $user is NOT null here
}
```

`never` is a gift to PHPStan: after calling `abort()` the code **never
executes**, so the analysis knows `$user` is definitely a `User` here.
It's not in the starter kit yet ... but if a central error guard ever
comes, this is its type.

## Summary of part 5

1. **Union types** (`Locale | string`) ... "either A, or B, I know
   exactly which"; stronger than `mixed`, narrowing via `is_*`/`instanceof`
2. **Nullsafe `?->`** ... breaks at the first null; fine for reading
   deep structures, but deliberately explicit in the starter kit
3. **Enums** ... a closed list of values (`Theme::Dark`); deploy when
   values travel between layers and logic depends on them ... not before
   (YAGNI)
4. **`never`** ... "I'll never come back here"; helps PHPStan narrow
   types after calling abort functions

And with that the series is complete ... from "a variable has no type"
([part 1](/en/blog/php-types-part-1)) through casts ([part 2](/en/blog/scalar-casts)),
typing before iterating ([part 3](/en/blog/type-before-iterating)), arrays
and DTOs ([part 4](/en/blog/array-list-dto)) up to union types,
nullsafe and enums. The series continues in [part 6](/en/blog/chunky-rule)
... why 60 lines. Clean heads, clean data ... and clean types.

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
