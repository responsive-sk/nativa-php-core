---
title: "C extensions ... how C code plugs into the Zend engine"
date: "2026-08-18"
version: "1.0.0"
tags: ["php", "c", "extensions", "zend"]
excerpt: "How a PHP extension is written in C, what the PHP-C API is and why SQLite in C is the fastest path to data ... part four of the series, where 'PHP C chunks' becomes literally true."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "c-rozsirenia-php-api"
published: true
---

So far we've said that PHP is C at its core ... the engine, the opcodes, the
JIT. Now let's show how C code gets **plugged in from the outside**:
through **extensions**. This is where the phrase "PHP C chunks" becomes
literally true ... when you call `json_encode` or open an SQLite database,
what runs is C code wired into PHP as a library.

## What a PHP extension is

An extension is a **shared library written in C** (a `.so` file) loaded
into PHP via `php.ini`:

```ini
extension=foo.so
```

Once loaded, it registers functions, classes and constants exactly like
everything else ... from PHP code's point of view there's no difference
between `str_replace` (which lives in the core) and a function from your
extension. Both are C functions.

## What an extension looks like

The foundation is the **PHP-C API** ... a set of C macros and structures
through which an extension connects to the Zend engine. The simplest
extension that adds a `hello()` function looks like this:

```c
/* ext/hello/hello.c */
PHP_FUNCTION(hello)
{
    RETURN_STRING("Hi from C!");
}

/* function registration for PHP */
const zend_function_entry hello_functions[] = {
    PHP_FE(hello, NULL)
    PHP_FE_END
};
```

- `PHP_FUNCTION(hello)` ... a macro that defines a C function callable
  from PHP
- `RETURN_STRING(...)` ... returns a value through `zval`, PHP's internal
  value representation in C
- `zend_function_entry` ... the table that tells the Zend engine: "these
  are the functions I offer"

A new skeleton is generated with a single command:

```bash
cd ext && ./ext_skel --extname=hello
```

And then just `phpize && ./configure && make` ... and you have C code
running as a native PHP function.

## zval and zend_string ... PHP represented in C

Under the hood, all PHP values are C structures:

- **zval** (Zend Value) ... the universal representation of a variable:
  integer, string, array, object…
- **zend_string** ... a string with its length and hash in the header, so
  lookups in associative arrays don't have to hash the string again

When your PHP code does `$user['name']`, the Zend engine searches a C
hash table using a `zend_string` ... and that's exactly why PHP arrays are
so fast: it's not an "interpreted array", it's a C hash table.

## SQLite in C ... the fastest path to data

And now the part that matters most for our stack. The whole of SQLite is
a **C library** ... a single `.c` file with hundreds of thousands of lines
of C code. PHP accesses it through thin bindings (`pdo_sqlite`,
`sqlite3`), which are also C.

When our code runs:

```
PHP code → pdo_sqlite (C) → SQLite engine (C) → .db file
```

There is no interpreted layer between our code and the data. No ORM, no
query builder, no "PHP server". Just direct C calls into a C library that
reads data from a file. That's why the per-module SQLite architecture of
our stack runs so fast: every query is just a few C calls.

## When to write an extension (and when not to)

A custom C extension makes sense when you have a truly critical
computation that interpreted PHP can't handle. For the vast majority of
websites ... including ours ... it's not needed: everything essential
(strings, regex, JSON, SQLite, hashing) is already C. Writing an
extension to "speed up" something that's already a C call is exactly the
kind of extra layer this whole series argues against.

## Summary

- **An extension** = a shared C library (`.so`) that registers functions
  and classes into PHP through the **PHP-C API**
- `PHP_FUNCTION()` + `zend_function_entry` ... how a C function becomes a
  PHP function; skeleton via `ext_skel`
- **zval** and **zend_string** ... PHP values are C structures under the
  hood; PHP arrays are C hash tables
- **SQLite is entirely in C** ... our queries are direct C calls, no
  interpreted layer between code and data
- Custom extensions are only worth writing for truly critical
  computations ... otherwise it's an extra layer

The final part of the series is about philosophy: why "fewer layers"
isn't just a preference, but the natural conclusion of PHP's whole
history ... and what that means for our stack.

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [Web.dev, Performance](https://web.dev/performance): Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/): Lighthouse tool
