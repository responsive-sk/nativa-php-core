---
title: "PHP types for kids ... part 3: type strictly and cast before iterating"
date: "2026-08-09"
version: "1.2.0"
tags: ["php", "types", "clean-code", "patterns"]
excerpt: "Normalize data at the boundary ... not inside the loop. The series' core rule: why it pays to cast a collection once, so you don't have to probe the behavior of every child node."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "typuj-pred-iteraciou"
published: true
---

> This is part 3 of the **PHP types for kids** series. Previous parts:
> [part 1 ... what's behind a variable](/en/blog/php-types-part-1) and
> [part 2 ... scalar casts](/en/blog/scalar-casts). Today we tie it
> all into one rule that saves CPU in loops. In [part 4](/en/blog/array-list-dto)
> we'll then look at arrays, `mixed` and DTOs, in [part 5](/en/blog/union-nullsafe-enums)
> at union types, nullsafe and enums ... and in [part 6](/en/blog/chunky-rule)
> at the chunky rule.

## A lesson that became a rule

Every project eventually pulls its recurring patterns into rules. One
of the lessons that became an official rule in the minimal starter kit
(`docs(rules)` ... commit `c519199`) reads:

> **Type strictly and cast data BEFORE iterating** ... normalize
> collections (typed arrays, DTOs, scalar casts) at the boundary, so a
> loop never has to re-discover the type or behavior of every child
> node.

In practice: `is_*()` / `instanceof` / null-check **once when building
the structure**, not **for every element in a `foreach`**.

## Why it came about

When porting the blog index (`/blog`), the front matter files brought
**unguaranteed data shapes**. YAML in `storage/blog/*.md` may have
`title`, or may not; `tags` may be an array, but could equally be a
string or nothing. Two strategies:

- **Bad:** in the card-rendering loop, check for every article
  `isset($meta['title'])`, `is_array($meta['tags'])`,
  `is_scalar($tag)` … ... the loop fills up with branches and every child
  node gets "probed" over and over.
- **Good:** normalize everything **once** in the `articles()` method,
  where front matter enters the system, and from then on work only with
  typed rows.

## Example from the codebase

`BlogIndexAction::articles()` first builds a **typed list** ... every
row has exactly known types (`string` / `list<string>`):

```php
$meta = $renderer->extractFrontMatter($source)['meta'];

$articles[] = [
    'slug'     => $slug,
    'title'    => is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug),
    'date'     => is_string($meta['date'] ?? null) ? $meta['date'] : '',
    'excerpt'  => is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '',
    'category' => is_string($meta['category'] ?? null) ? $meta['category'] : '',
    'author'   => is_string($meta['author'] ?? null) ? $meta['author'] : '',
    'tags'     => is_array($meta['tags'] ?? null)
        ? array_values(array_filter(array_map(
            static fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
            $meta['tags'],
        ), static fn (string $tag): bool => '' !== $tag))
        : [],
];
```

Notice the pattern: **`?? null` + one `is_*` + fallback** for every
field, and an `array_map` with a `(string)` cast for the tags ...
everything **at the boundary**. The output is declared by a docblock:

```php
/**
 * @return list<array{
 *     slug: string, title: string, date: string, excerpt: string,
 *     category: string, author: string, tags: list<string>
 * }>
 */
```

Then `renderCards()` → `renderCard()` iterate **without a single type
check** ... the values are already `string`, so they just need escaping
and printing:

```php
private function renderCard(array $article): string
{
    $href = '/blog/' . $article['slug'];
    $metaBits = array_values(array_filter([
        $article['date'],
        $article['category'],
        $article['author'],
    ], static fn (string $bit): bool => '' !== $bit));
    // ... escape and render, no is_* in the loop body
}
```

## What it gives you

| | Without normalization | With boundary normalization |
|---|---|---|
| Type checks | `N × M` (for every node × every property) | `1×` when building the structure |
| Loop readability | branches, fallbacks, deep `isset` | clean render, only escaping |
| PHPStan level 9 | endless `mixed` problems | clear shapes in `@return` |
| Chunky 60-line rule | the loop grows over the limit | methods stay short |

This is exactly the spirit of the starter kit's C-pattern ... explicit
and transparent. Type decisions are made **in one place**, not scattered
across the codebase.

## How the lesson spreads further

The new convention doesn't just live in people's heads ... it has two
layers:

1. **Project rule** ... `.ai-factory/rules/base.md` (PHP section), loaded
   automatically via `/aif-implement`
2. **Skill context** ... `.ai-factory/skill-context/aif-implement/SKILL.md`
   (commit `5df160b`), with a link to the source and an example from
   the codebase, so future AI sessions know it including the reasoning

This is how every recurring pattern turns from a silent experience into
an **enforced convention** ... and the next iteration doesn't start from
zero.

## Practically

Next time you write a `foreach` over data that didn't come from your
own typed code (YAML, JSON, DB rows, DOM nodes):

1. **Cast once at the boundary** ... when parsing, not inside the loop
2. **Declare the shape** ... a `@return` with a shape or DTO, so PHPStan
   knows what's coming
3. **Iterate cleanly** ... in the loop body just use the values

The loop then answers the question "what to do with it" ... not "what
is it, actually".

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
