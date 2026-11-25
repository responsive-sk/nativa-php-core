---
title: "PHP types for kids ... part 1: what's actually behind a variable"
date: "2026-08-09"
version: "1.0.0"
tags: ["php", "types", "clean-code"]
excerpt: "PHP is a lax language ... a variable has no type, a value does. The first part of the types series: scalar types, dynamic typing and the traps of type juggling that quietly eat CPU and nerves."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "php-typy-pre-deti"
published: true
---

## Who this series is for

If you've ever written `$x = "5";` and then `$x + 1` and **didn't know
exactly** what would happen ... this series is for you. We call it "for
kids" ... for clear heads, untainted by old habits and patterns. No
prejudice, no "that's not how you do it", just a fresh look at why PHP
behaves the way it behaves.

The series has six parts:

1. **This part** ... what scalar types are and why PHP "weaves" between
   them so easily
2. [Part 2 ... scalar casts](/en/blog/scalar-casts) ... how to tell
   PHP exactly what you want, and `strict_types`
3. [Part 3 ... type strictly and cast before iterating](/en/blog/type-before-iterating) ... how it all saves CPU in loops
4. [Part 4 ... array vs list, DTO and mixed](/en/blog/array-list-dto) ... what happens when data isn't scalar
5. [Part 5 ... union types, nullsafe and enums](/en/blog/union-nullsafe-enums) ... when one value can be several things
6. [Part 6 ... why 60 lines? (chunky rule)](/en/blog/chunky-rule) ... one method, one thing

## A variable has no type. A value has a type.

This is **the most important sentence of the whole series**. In PHP:

```php
$value = "5";    // string
$value = 5;      // now it's an int ... the same variable!
$value = 5.0;    // now float
$value = true;   // and now bool
```

A variable is just a **container**. The type belongs to the **value**
currently sitting in it. In languages like C or Java you "type" a
variable once and that's it ... in PHP the type changes depending on what
you assign. That's called **dynamic typing**, and it's the main source
of all the "magical" bugs.

## Four scalar types

A scalar type = one simple value. PHP has four of them:

| Type | Examples | In plain words |
|---|---|---|
| `int` | `42`, `-7`, `0` | a whole number |
| `float` | `3.14`, `-0.5`, `2.0` | a decimal number |
| `string` | `"hello"`, `''`, `"5"` | a string of characters |
| `bool` | `true`, `false` | truth/falsehood |

Plus `null` (no value) and compound types (`array`, `object`) ... we'll
leave those for another time. The important thing: **`"5"` is not `5`**.
One is a string, the other a number. They look the same, they behave
differently.

## Type juggling ... when PHP "guesses"

Because PHP is lax, when you give it mixed types it **decides on its
own** how to combine them. That's called **type juggling**:

```php
echo "5" + 3;       // 8  ... the string "5" turned into the number 5
echo "5" . 3;       // "53" ... the number 3 turned into the string "3"
echo "abc" + 3;     // 3  + warning ... "abc" can't be read as a number, so 0
echo "5abc" + 3;    // 8  ... PHP reads the 5, throws away the rest (warning since PHP 8)
echo 1 == "1";      // true  ... loose comparison compares values
echo 1 === "1";     // false ... strict comparison checks the type too
```

Notice the last two lines: `==` (loose) and `===` (strict). Loose
comparison is a **jungle**:

```php
var_dump(0 == "a");      // false in PHP 8 (it was true in PHP 7!)
var_dump(0 == "");       // false in PHP 8
var_dump("0" == "");     // false
var_dump("1" == "01");   // true ... both are read as numbers
var_dump("abc" == 0);    // false in PHP 8
```

PHP 8 fixed a lot of this, but **the rule stays**: when comparing,
always use `===` and `!==` unless you really know what you're doing.

## Why it costs you CPU

Every type juggling is extra work PHP has to do **on every access**. And
now the best part ... when you have a loop:

```php
// Bad: every iteration re-discovers what $row['count'] actually is
foreach ($rows as $row) {
    if (is_string($row['count']) && is_numeric($row['count'])) {
        $total += (int) $row['count'];
    } elseif (is_int($row['count'])) {
        $total += $row['count'];
    }
}
```

PHP **re-examines the type on every loop pass** ... for every row, for
every column. With 10,000 rows that's 10,000 times the same decision.
We can avoid that ... and that's exactly what parts 2 and 3 are about.

## Summary of part 1

1. **A variable has no type** ... a value does; PHP is dynamically typed
2. There are 4 scalar types: `int`, `float`, `string`, `bool`
3. **`"5"` is not `5`** ... same look, different behavior
4. PHP **juggles types** automatically (type juggling) ... sometimes
   useful, often a betrayal
5. When comparing, use **`===` / `!==`**, not `==` / `!=`

In [part 2](/en/blog/scalar-casts) we'll show how to tell PHP
exactly what you want ... explicit casts and
`declare(strict_types = 1)`. In [part 3](/en/blog/type-before-iterating)
we'll tie it all into a rule that saves processor cycles. In
[part 4](/en/blog/array-list-dto) we'll look at arrays, `mixed`
and DTOs. In [part 5](/en/blog/union-nullsafe-enums) at union
types, nullsafe and enums ... and in [part 6](/en/blog/chunky-rule)
at why methods must not stretch past 60 lines.

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse tool
