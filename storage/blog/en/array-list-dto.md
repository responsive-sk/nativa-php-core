---
title: "PHP types for kids ... part 4: array vs list, DTO and mixed (as PHPStan level 9 sees it)"
date: "2026-08-09"
version: "1.0.0"
tags: ["php", "types", "phpstan", "clean-code", "dto"]
excerpt: "Not all arrays are the same. Part 4 of the series: why PHPStan distinguishes array and list, what mixed is and why level 9 hates it, and when to replace a shape array with a DTO ... on real examples from the starter kit."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "array-list-dto-pre-deti"
published: true
---

> This is part 4 of the **PHP types for kids** series. Previous parts:
> [part 1 ... what's behind a variable](/en/blog/php-types-part-1),
> [part 2 ... scalar casts](/en/blog/scalar-casts) and
> [part 3 ... type strictly before iterating](/en/blog/type-before-iterating).
> Today we look at what happens when data isn't scalar ... arrays,
> `mixed` and DTOs. In [part 5](/en/blog/union-nullsafe-enums)
> then union types, nullsafe and enums ... and in [part 6](/en/blog/chunky-rule)
> at why 60 lines.

## Last time we said…

In [part 3](/en/blog/type-before-iterating) we normalized front matter **at
the boundary** and worked with typed rows from then on. But notice one
detail ... the typed row was still an **array**
(`array{slug: string, tags: list<string>, …}`). Today we'll talk about
why "array" isn't "array", what PHPStan level 9 does about it, and when
to replace an array with a class.

## Not all arrays are the same

PHP has one `array` type ... but logically there are **two kinds**:

```php
// MAP (array) ... I pick the keys: strings, numbers, anything
$settings = ['theme' => 'dark', 'locale' => 'sk', 7 => 'seven'];

// LIST (list) ... keys are 0, 1, 2, … without holes, in order
$tags = ['php', 'types', 'dto'];
```

- **`array`** = a general map: keys can be anything, order is my
  business.
- **`list`** = a special case of `array` where keys are `0..n-1`
  exactly in order. It's a **narrower** subset ... every `list` is an
  `array`, but not every `array` is a `list`.

Why does it matter? Because with a `list` **you guarantee more** ... and
PHPStan level 9 can use that.

## PHPStan level 9: list is safer

Imagine you want the first tag of an article:

```php
/** @var array<string, list<string>> $article */
$first = $article['tags'][0];  // PHPStan level 9: hmm, could there be a hole?
```

PHPStan knows `$article['tags']` is a `list<string>` ... and a **`list`
guarantees that index `0` exists**. No hole, no warning.

But when tags arrive as an `array` with arbitrary keys, PHPStan shakes
its head:

```php
/** @var array<string, array<int, string>> $article */
$first = $article['tags'][0];  // PHPStan: array<int, string> may not have key 0!
```

The difference between `array<int, string>` and `list<string>` is
exactly this: **a `list` is an "array without holes"**, so indexing
`[0]`, `count()`, `foreach` in order ... all safe. An `array` is a
"maybe a map" ... you can't rely on `[0]`.

## How a list is born (and how you lose it)

The most common trap: `array_filter` and `array_map` **preserve keys**.
This is how a list becomes an array with holes:

```php
$tags = ['php', '', 'dto'];

// array_filter drops the empty tag, BUT the keys stay: [0 => 'php', 2 => 'dto']
$filtered = array_filter($tags);

var_dump($filtered[1]);  // Undefined array key 1! ... and PHPStan knows it
```

The solution? **`array_values()`** ... reindexes to `0..n-1` and returns
a list:

```php
$tags = array_values(array_filter($tags));  // list<string>: [0 => 'php', 1 => 'dto']
```

That's exactly what `BlogIndexAction` does for the front matter tags ...
`array_values(array_filter(array_map(…)))`, to get a guaranteed
`list<string>`:

```php
'tags' => is_array($meta['tags'] ?? null)
    ? array_values(array_filter(array_map(
        static fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
        $meta['tags'],
    ), static fn (string $tag): bool => '' !== $tag))
    : [],
```

The `array_map → array_filter → array_values` chain is a pattern you'll
see often in the starter kit: **transform → drop empties → reindex to a
list**.

## `mixed` ... the type you don't know

And now to `mixed`. In [part 2](/en/blog/scalar-casts) we
mentioned `array_map(static fn (mixed $p): float => …)`. What actually
is that `mixed`?

`mixed` = **"I don't know what it is"**. It's the widest type in PHP ...
it accepts anything. It appears everywhere data comes in from outside:

```php
$meta = $renderer->extractFrontMatter($source)['meta'];
// $meta['title'] is mixed ... YAML can contain anything
```

And here's the key: **PHPStan level 9 treats working with `mixed` as an
error unless you narrow the value** (make it more specific). This is
"level 9" in practice:

```php
$title = $meta['title'];              // mixed
echo strtoupper($title);              // PHPStan level 9: cannot call strtoupper() on mixed

$title = is_string($meta['title'])    // narrowing ... now I know it's a string
    ? $meta['title']
    : 'Untitled';
echo strtoupper($title);              // fine
```

Level 9 **forces** you to do what we said in part 3: an `is_*` check
**once at the boundary**, not in a loop. `mixed` isn't the enemy ... it's
an honest "I don't know" that forces you to ask before you do anything
with the value.

## When a shape array stops being enough ... DTO

A shape array (`array{slug: string, tags: list<string>}`) is fine for
small data. But as a row grows, several problems appear:

1. **Keys are just strings** ... nobody catches a typo in `'titel'`
2. **Types live only in a docblock** ... refactoring doesn't see them
3. **No methods** ... logic (validation, derived values) has nowhere to
   go

The solution: a **DTO** (Data Transfer Object) ... a small class that
holds data and its types together. The starter kit's pattern for it is
`MenuItemDTO`:

```php
final readonly class MenuItemDTO
{
    /**
     * @param list<MenuItemDTO> $children
     */
    public function __construct(
        private string $label,
        private string $href,
        private bool $active = false,
        private array $children = [],
        private bool $external = false,
    ) {
        $this->validate();
    }

    public function label(): string { return $this->label; }
    public function href(): string { return $this->href; }
    public function active(): bool { return $this->active; }
    public function external(): bool { return $this->external; }

    /** @return list<MenuItemDTO> */
    public function children(): array { return $this->children; }

    public function hasChildren(): bool { return [] !== $this->children; }
}
```

Notice what a DTO adds over a shape array:

- **Types are real** ... `string $label`, `bool $active`, and above all
  `list<MenuItemDTO> $children` (a list of DTOs, not just any array!)
- **`readonly`** ... the data can't change after creation, which is
  exactly what you want from a "transferred object"
- **Validation in the constructor** ... `validate()` throws an exception
  at birth, not five uses later
- **Methods** ... `hasChildren()` is more readable than
  `[] !== $item['children']`

## DTO vs shape array ... when to use what

| | Shape array | DTO |
|---|---|---|
| Notation | `array{slug: string}` | a class with typed properties |
| Types | only in a docblock | real, checked by PHP and PHPStan |
| Typo in a key | silent bug | fatal at compile/analysis |
| Validation | nowhere | in the constructor |
| Logic | none | methods (`hasChildren()`) |
| When to use | small, local data (1–4 fields) | data that travels between layers |

The starter kit's golden rule: **shape arrays for local boundary
normalization, DTOs for data crossing module boundaries** (repository →
service → action). `MenuItemDTO` and `TranslationDTO` are exactly that.

## So what does level 9 actually want

PHPStan level 9 is the strictest level ... and the starter kit runs on it
with **zero errors**. In short:

1. **No operations with `mixed`** ... every value must first be narrowed
   (`is_string`, `is_array`, …)
2. **No indexing of `array` without a guarantee** ... `list` is safer
   than `array<int, T>`
3. **No unverified accesses** ... `$row['key']` on `mixed` is an error;
   resolve it at the boundary
4. **Casts, not forces** ... exactly from [part 2](/en/blog/scalar-casts)

What does it all look like in practice? `BlogIndexAction` has at the
end of `articles()` a docblock that is the **contract** between
normalization and render:

```php
/**
 * @return list<array{
 *     slug: string, title: string, date: string, excerpt: string,
 *     category: string, author: string, tags: list<string>, image: string
 * }>
 */
```

`list<…>` on the outside (articles are ordered, no holes) + `list<string>`
for tags + nothing but `string` values = the render can be clean,
without a single `is_*` in the loop.

## Summary of part 4

1. **`array` vs `list`** ... a `list` is an array without holes
   (`0..n-1`); PHPStan level 9 knows it and `[0]` on a list is safe
2. **`array_filter`/`array_map` preserve keys** ... reindex with
   `array_values()` to get a `list`
3. **`mixed` = "I don't know"** ... level 9 forces you to narrow
   (`is_*`) at the boundary, not in a loop
4. **DTO** ... when data travels between layers, give it a class
   (`final readonly` + typed properties + validation); leave shape
   arrays for local data
5. **The contract in `@return`** ... `list<array{…}>` is a readable
   agreement between normalization and the consumer

With this we went from "a variable has no type" ([part 1](/en/blog/php-types-part-1))
through casts and `strict_types` ([part 2](/en/blog/scalar-casts))
and typing before iterating ([part 3](/en/blog/type-before-iterating)) up
to arrays, `mixed` and DTOs. The series continues in [part 5](/en/blog/union-nullsafe-enums)
... union types, nullsafe and enums ... and [part 6](/en/blog/chunky-rule)
closes it with the chunky rule. Clean heads, clean data, clean loops.

## Sources

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definition
- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
