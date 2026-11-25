---
title: "PHP history ... from one person's tool to the Zend Engine 4"
series: "Why PHP is fast"
series-part: "2"
date: "2026-08-16"
version: "1.0.0"
tags: ["php", "history", "zend", "engine"]
excerpt: "A PHP timeline from PHP/FI (1994) through the birth of the Zend engine to today's PHP 8.5 ... and why every big speed jump meant a new engine written in C. Part two of the PHP C chunks series."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "historia-php-od-fi-po-8"
published: true
---

In the first part of the series we saw that PHP is the Zend engine ... a
compiler and virtual machine written in C. Now let's look at how PHP got
there. The history of PHP is the story of one repeating pattern:
**every time PHP needed to be faster, the engine was rewritten in C.** No
extra layer, no middleware ... new C code.

PHP didn't get faster by adding abstractions. It got faster by rewriting the engine.

The problem with most languages: performance degrades as layers accumulate.

## Timeline

| Year | Version | Engine | What it brought |
|---|---|---|---|
| 1994 | PHP/FI | ... | Rasmus Lerdorf writes a tool for his personal homepage (Personal Home Page Tools) |
| 1995 | PHP/FI 2 | ... | First version named PHP ... form processing, database queries |
| 1997 | PHP 3 | Zend Engine 1 | Zeev Suraski and Andi Gutmans rewrite the parser ... birth of the Zend engine (Zend = **Ze**ev + **A**nd**i**), named PHP: Hypertext Preprocessor |
| 2000 | PHP 4 | Zend Engine 1 | More mature runtime, session support, significantly faster |
| 2004 | PHP 5 | Zend Engine 2 | New object model, exceptions, PDO, MySQLi |
| 2015 | PHP 7 | Zend Engine 3 (PHPNG) | Twice the speed, half the memory ... the PHPNG project |
| 2020 | PHP 8.0 | Zend Engine 4 | JIT, union types, match, named arguments |
| 2025 | PHP 8.5 | Zend Engine 4 | Current version ... our stack runs on it too |

## From a "tool for a personal page" to Zend

Rasmus Lerdorf didn't originally plan PHP as a language. He just wanted
simple tools for his personal homepage: a visit counter, a form, a few
database queries. That's how PHP/FI was born ... a collection of C scripts
that later became an interpreter.

The turning point came in 1997, when two students ... Zeev Suraski and Andi
Gutmans ... needed PHP for a university project and ran into the limits of
the original parser. Instead of complaining, they **rewrote it**. The
result: PHP 3 and a brand new engine they named Zend. The rest, as they
say, is history ... today the Zend engine powers more than three quarters of
the web.

## Every jump = new C code

Notice the pattern in the table:

- **PHP 5** (Zend Engine 2) ... a new object model in C, because the old one
  couldn't carry the objects the language needed.
- **PHP 7** (Zend Engine 3) ... the PHPNG (PHP Next Generation) project
  rewrote the data structures and the execution loop. Result: twice the
  speed and half the memory. No syntax change, no new layer ... new C code
  under the hood.
- **PHP 8.0** (Zend Engine 4) ... JIT arrived, compiling hot paths all the
  way down to native machine code. Again: C.

PHP never got faster by adding abstraction. It always got faster by moving
more work into C. That's exactly the pattern we talk about throughout this
series: "Why PHP is fast"
confirms it at every step.

## What you won't find in the history

For completeness: PHP 6 never shipped. It was supposed to bring native
Unicode support, but the project was never finished and the number 6 was
skipped. PHP 7 came instead, with PHPNG ... which is itself a metaphor for
the whole history: when a big idea fails, PHP doesn't slow down, it
rewrites the engine.

## Summary

- PHP started as **PHP/FI** (1994) ... Rasmus Lerdorf's tool for his
  personal page
- The **Zend engine** was born in 1997 when Zeev Suraski and Andi Gutmans
  rewrote the parser (Zend = Ze**e**v + **A**nd**i**)
- Every engine generation (**ZE1 → ZE2 → ZE3 → ZE4**) was new C code
  under the hood
- The biggest jump: **PHP 7 / PHPNG** ... twice the speed, half the memory,
  no syntax change
- **PHP 8** added JIT ... compilation to native machine code
- The pattern of history: PHP doesn't speed up by adding layers, but by
  moving work into C ... exactly the philosophy our stack is built on

The Zend engine remains the foundation of PHP. Four generations, same philosophy: speed comes from C, not from frameworks.

---

Every big speed jump in PHP meant a new engine written in C. That pattern hasn't changed.

---

A language's history is its architecture decisions.

---

→ [PHP C extensions API](/blog/php-c-extensions-api): how extensions work at the C level
→ [OPcache and JIT](/blog/opcache-and-jit-precompilation): precompilation and native machine code

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [Web.dev, Performance](https://web.dev/performance): Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/): Lighthouse tool
