---
title: "PHP types for kids ... part 6: why 60 lines? (chunky rule ... one method, one thing)"
date: "2026-08-09"
version: "1.0.0"
tags: ["php", "clean-code", "architecture", "refactoring"]
excerpt: "One method = one thing, named. The final part of the series about why the starter kit forbids methods longer than 60 lines ... and what happens when you break it (a real story from the codebase)."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "chunky-rule-pre-deti"
published: true
---

> This is part 6 of the **PHP types for kids** series. Previous parts:
> [part 1 ... what's behind a variable](/en/blog/php-types-part-1),
> [part 2 ... scalar casts](/en/blog/scalar-casts),
> [part 3 ... type strictly before iterating](/en/blog/type-before-iterating),
> [part 4 ... array vs list, DTO and mixed](/en/blog/array-list-dto)
> and [part 5 ... union types, nullsafe and enums](/en/blog/union-nullsafe-enums).
> Today we close it all with the rule that the starter kit **enforces**
> with an architecture test.

## The rule that won't let you write long methods

The starter kit has an architecture test
(`tests/Architecture/CorePatternTest.php`) that scans **all of `src/`**
looking for methods longer than **60 lines**:

```php
private const MAX_METHOD_LINES = 60;

// ...
if (null !== $closeLine && $closeLine - $openLine > self::MAX_METHOD_LINES) {
    $violations[] = sprintf('%s::%s (%d lines)', …);
}
```

When the test finds a violation, it fails with:

```
Methods longer than 60 lines are forbidden (chunky: one screen per function):
src/Modules/Core/Interfaces/HTTP/Actions/BlogArticleAction.php::handle (65 lines)
```

It's not a "recommendation" or a code review note ... it's a **red test
you must fix for the build to pass**. And that's exactly the point:
rules that aren't enforced aren't followed.

## Why "one screen"

The name says it all ... **chunky** (from "chunk"): a method should be
small enough to **fit entirely on one screen**. Why this precisely?

When a method outgrows the screen, you stop "seeing it whole". Reading
80 lines requires:

- scrolling up and down
- holding context in your head ("what was on line 10 when I'm on line
  70?")
- searching for where things change

And that's exactly what matters most for kids ... clear heads: **a short
method with a good name can be understood without reading it all.**
You read the name and you already know what it does.

## A real story from the codebase ... what it looks like when you break it

This article has a **living example right from this project**. While
refactoring `BlogArticleAction`, I added tag normalization at the
boundary (part 3!) directly into the `handle()` method. And what
happened? The test failed:

```
BlogArticleAction.php::handle (65 lines)
```

Exactly 5 lines over the limit. The fix was simple and it's **the same
pattern the whole series teaches**: resolve the problem at the boundary
and split into named helpers.

Before ... everything crammed into `handle()` (abbreviated):

```php
public function handle(Request $request): Response
{
    // … slug validation, file reading, front matter …

    $metaBits = array_values(array_filter([ … ]));   // 5 lines of logic
    $tags = is_array($meta['tags'] ?? null)          // tag normalization
        ? array_values(array_filter(array_map(…)))   // …
        : [];
    $tagChips = '';
    if ([] !== $tags) { … }                          // tag rendering
    // … article render …
}
```

After ... `handle()` just **tells the story**, details live in named
methods:

```php
public function handle(Request $request): Response
{
    // … slug validation, file reading, front matter …

    $title = is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug);
    $excerpt = is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '';

    $metaBits = $this->metaBits($meta);   // what's in the meta line?
    $tagChips = $this->tagChips($meta);   // which tags to render?

    // … article render with $metaBits and $tagChips …
}

/** Date/category/author meta line, non-empty values only. */
private function metaBits(array $meta): array { … }

/** Render tag chips footer ... tags normalized at the boundary. */
private function tagChips(array $meta): string { … }
```

Notice what happened:

1. **`handle()` answers "what happens?"** ... the story: load → normalize
   → render
2. **The helpers answer "how?"** ... `tagChips()` knows how tags are
   made
3. **Names are documentation for free** ... you read
   `$this->tagChips($meta)`, you don't have to read the body

## Why this relates to types (yes, this is a series about types!)

The whole series led to this rule. Look at how the parts connect:

| Part | Lesson | How it keeps methods short |
|---|---|---|
| [Part 1](/en/blog/php-types-part-1) | A variable has no type | Type checks in long methods = chaos |
| [Part 2](/en/blog/scalar-casts) | Cast-at-boundary | Decide the type once at the boundary → fewer branches in the body |
| [Part 3](/en/blog/type-before-iterating) | Type before iterating | Clean iteration = a short loop |
| [Part 4](/en/blog/array-list-dto) | Shape/DTO | When a method grows, data wants a DTO, not another branch |
| [Part 5](/en/blog/union-nullsafe-enums) | Union/never | Precise types → fewer defensive checks |

And now the chunky rule: **when a method outgrows 60 lines, it's a
symptom** ... not the cause. A symptom that more than one thing is
happening in it. And the solution is always the same:

1. **Normalize at the boundary** (parts 2+3) ... resolve raw data early
2. **Split into named helpers** (this part) ... one method = one thing
3. **If it still grows ... a DTO** (part 4) ... the data wants a class

## What the limit is NOT

An important note ... 61 lines isn't a "crime". The detector is a
**smoke test** (a warning signal), not a quality meter:

- **It's not about the number** ... it's about "does it fit on a
  screen?"
- **It's not dogmatic** ... there are legitimate long methods (e.g. a big
  `match` or a SQL builder). The C-pattern handles it elegantly:
  `match` expressions and declarative data can be pulled out into
  constants/private methods
- **It's enforced so there's nothing to discuss** ... when a rule is
  automatic, you don't have to argue at code review

And one more thing: **`handle()` is the perfect example of why this
works.** When a request arrives at `/blog/hello-world`, `handle()`
answers "what happens" in ~40 lines ... and every part is named. Kids
(and everyone else) read the flow without scrolling.

## Practically ... how to practice it

Next time you write a method and feel it growing:

1. **Count what it does** ... if you can say "this does A, B and C" →
   that's three methods
2. **Name those three things** ... `normalize()`, `validate()`, `render()`
3. **Pull the details into helpers** ... `handle()` tells, helpers do
4. **Let the test guard you** ... `composer test` will tell you when it
   outgrows

And remember: the limit isn't your enemy. It's a **detector that warns
you before you notice yourself** that a method stopped being one thing.

A short method with a good name is respect, respect for the next person who reads it, respect for your future self, respect for the idea that code is read more than it's written.

---

→ [Part 1, what's behind a variable](/blog/php-types-part-1): the foundation
→ [Part 5, union types, nullsafe and enums](/blog/union-nullsafe-enums): precision types
→ [Chunky rule SK](/blog/chunky-rule-pre-deti): slovenská verzia

## Sources

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definition
- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
