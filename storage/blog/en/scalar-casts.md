---
title: "PHP types for kids ... part 2: tell PHP exactly what you want (scalar casts)"
date: "2026-08-09"
version: "1.0.0"
tags: ["php", "types", "casts", "clean-code"]
excerpt: "Let PHP guess, and it will guess wrong. Part 2 of the series: explicit casts (int), (string), (float), (bool), declare(strict_types=1) and the cast-at-boundary pattern that saves CPU in loops."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "scalar-casty-pre-deti"
published: true
---

## Last time we said…

In [part 1](/en/blog/php-types-part-1) we found that **a variable has no
type ... a value does**, and PHP juggles types on its own (type
juggling). Today we learn to **tell PHP exactly what we want**, so it
doesn't have to guess. Guessing = losing CPU and nerves.

## Explicit casts ... throw it into shape

Casting is a **deliberate type change** ... "I want this value as a
string". The syntax is `(type) $value`:

| Cast | Example | Result |
|---|---|---|
| `(int)` | `(int) "42"` | `42` |
| `(int)` | `(int) "42.9"` | `42` ... doesn't round, chops the decimal part |
| `(int)` | `(int) "abc"` | `0` ... careful, silently! |
| `(int)` | `(int) "5abc"` | `5` ... reads the start, throws away the rest |
| `(float)` | `(float) "3.14"` | `3.14` |
| `(string)` | `(string) 42` | `"42"` |
| `(string)` | `(string) true` | `"1"` ... bool true becomes "1" |
| `(bool)` | `(bool) "false"` | `true` ... **careful!** Any non-empty string is true |
| `(bool)` | `(bool) 0` | `false` |
| `(bool)` | `(bool) "0"` | `false` ... this is the special case |

The two biggest traps in the table:

```php
$n = (int) "abc";        // 0 ... no error, just a silent 0
$b = (bool) "false";     // true ... the string "false" IS non-empty, so true!
```

The string `"false"` is **truthy** to PHP, because it's non-empty. The
only strings that are `false` are `""` and `"0"`. When you parse a form
or JSON and expect a bool, **check the string's content, not its
"boolness"**:

```php
// Bad: "false" turns into true
$active = (bool) ($data['active'] ?? false);

// Good: compare the string itself
$active = ($data['active'] ?? '') === 'true' || $data['active'] === true;
```

## Instead of a cast ... functions

Sometimes a function with **more precise rules** is more readable:

```php
intval("42.9");     // 42
floatval("3.14");   // 3.14
strval(42);         // "42"
boolval(1);         // true
is_numeric("42");   // true ... checks whether it CAN be read as a number
```

The difference between `(int)` and `intval()` is in the detail:
`intval()` also respects notation like `intval("0x1A", 16)`. For
everyday work they're equivalent ... pick one style and stick to it.

## `declare(strict_types = 1)` ... don't let PHP dodge

In the starter kit **every** file has this on the first line:

```php
<?php

declare(strict_types = 1);

function add(int $a, int $b): int
{
    return $a + $b;
}

add(1, 2);     // OK
add("1", 2);   // TypeError! ... the string will no longer silently become an int
```

Without `strict_types`, `add("1", 2)` would silently flip `"1"` to `1`
and continue. With `strict_types` PHP **throws an error** ... and that's
good! An error forces you to fix it where it originates, not ten calls
down the line.

> **Important:** `strict_types` applies **per file** ... not globally.
> The file where the function is **called** decides (i.e. where the
> call is written), not where it's defined. That's why it goes at the
> start of every file.

## The cast-at-boundary pattern ... cast at the boundary, not in the loop

And now the part that saves CPU. A **boundary** = the place where data
enters your code: a form, JSON, YAML front matter, a row from the
database, an API response. At the boundary the data is "raw" ... it could
be anything. In a loop it should already be **precisely typed**.

```php
// Bad ... the loop guesses at every element (repeats the work N times)
$total = 0;
foreach ($rows as $row) {
    if (is_string($row['price'])) {
        $total += (float) $row['price'];
    } elseif (is_float($row['price'])) {
        $total += $row['price'];
    } elseif (is_int($row['price'])) {
        $total += (float) $row['price'];
    }
}

// Good ... resolve the type ONCE at the boundary, the loop just computes
$prices = array_map(static fn (mixed $p): float => (float) $p, $rows);
$total = array_sum($prices);
```

In the second case the type decision is made **once per row** (in
`array_map`), and the loop (`array_sum`) just sums numbers. With 10,000
rows the difference in the number of type checks is striking ... and the
code is also shorter and more readable.

## Summary of part 2

1. **`(int)`, `(float)`, `(string)`, `(bool)`** ... a deliberate type
   change; watch out for `(int) "abc"` → `0` and `(bool) "false"` →
   `true`
2. **`declare(strict_types = 1)`** ... every file, no silent type
   flipping in function calls
3. **Cast-at-boundary** ... resolve the type at the boundary (input),
   not in the loop; the loop then just works
4. For a bool from a form/JSON **compare the string**, don't cast
   blindly

In [part 3](/en/blog/type-before-iterating) we'll tie it all into one rule
with a real example from `BlogIndexAction` ... and show how typed data
keeps loops short and methods within the 60-line limit. In
[part 4](/en/blog/array-list-dto) we'll talk about what happens
when data isn't scalar ... `array` vs `list`, `mixed` and DTOs. In
[part 5](/en/blog/union-nullsafe-enums) we'll look at union
types, nullsafe and enums ... and in [part 6](/en/blog/chunky-rule)
at why 60 lines.

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
