---
title: "Clean code for kids ... part 2: names are documentation (code that reads without reading)"
date: "2026-08-11"
version: "1.0.0"
tags: ["clean-code", "php", "architecture", "naming"]
excerpt: "The cheapest documentation you have is a good name. Part 2: why `tagChips($meta)` says more than a comment, what a bad name is and how to tell when a name is lying to you ... on real examples from this repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "nazvy-su-dokumentacia-pre-deti"
published: true
---

Have you ever been reading code and stopped at a line you didn't understand? Not because it was complex. But because it was called `$t = $this->getDataBits($m)` and you didn't know what `t` was, what `bits` were, or what `m` was. Three letters, three questions, zero answers.

## A bad name is a hole in the story

In [part 1](/en/blog/clean-code-part-1) we said that code tells a story. `handle()` in `BlogArticleAction` answers the question "what happens when someone visits /blog/{slug}?" and the details live in named helpers.

But what happens when a helper is named badly?

```php
$t = $this->getDataBits($m);        // what is t? what are bits? what is m?
$tagChips = $this->tagChips($meta); // clear without thinking
```

The first line is **a hole in the story**. The reader stops, has to open the method body, decode what's happening, then come back. The second line flows. The difference is one thing ... **who pays the extra time**. With the first, every reader pays; with the second, nobody.

A good name is documentation that cost you nothing. You don't write it into a separate document, you don't maintain it ... it's right in the code and can't drift apart from it.

## A name answers "what?", not "how?"

Look at the helper names in `BlogArticleAction`:

| Call | What it answers |
|---|---|
| `$this->metaLine($meta, $authorHtml)` | "what's in the meta line?" |
| `$this->tagChips($meta)` | "which tags to render?" |
| `$this->coverImage($slug, $meta)` | "which cover image to use?" |
| `$this->authorHtml($meta, $resolver, $avatar)` | "what does the author line look like?" |

Notice that none of the names says **how** the thing is done. They're not called `concatMetaWithSeparator()`, `loopTagsAndEscape()` or `getImgFromPool()`. They say **what** the result is. Because when you read `handle()` ... the story from part 1 ... you want to know what's happening, not how.

The convention is simple:

- **Methods that do something are verbs**: `renderCards()`, `discover()`, `resolveCover()`.
- **Methods that return truth/falsehood are questions**: `isActive()`, `isAllowed()`, `hasPermission()`.

You read `$user->isActive()` like a sentence: "is the user active?" ... and the answer is yes/no. If the method were called `active()`, you'd have to guess whether it returns a bool, an object or a string. The question form `is…`/`has…` kills that ambiguity.

## A name from the domain, not from the technology

This is subtler, but important. Look at the variables in `BlogArticlesService`:

```php
$metaBits = array_values(array_filter([ ... ]));
```

`$metaBits` ... that's a **domain concept** (pieces of meta information about an article). If it were called `$array1`, `$parts` or `$data`, it would say what kind of data type it is, not **what that data is**.

| Bad name | Why it's bad | Good name |
|---|---|---|
| `$array1` | says the type, not the meaning | `$metaBits` |
| `$data` | could be anything ... says nothing | `$article` |
| `$str` | type abbreviation, nothing about content | `$excerpt` |
| `$m` | one letter = nothing | `$meta` |

The rule: **a variable is named after what it represents in the domain, not after its type.** `$metaBits` tells you "what it is", `$array1` only tells you "it's an array" ... and you can already see that from the code.

## When a name lies, a comment won't save it

Here's the rule that sounds exactly the opposite of what you'd expect:

> **When a name requires a comment, it's a bad name. Fix the name, not the comment.**

Because a comment is a **second version of the truth**, which can drift apart from the code. A name can't drift apart from the code ... it's right in it. A comment can go stale and lie; a name either holds or it doesn't.

Look at `LocalStorageProvider`:

```php
public function isAllowed(string $relativePath): bool
{
    if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
        return false;
    }
    // ...
}
```

`isAllowed()` ... no comment needed. The name itself says: "is this path allowed?" And if the method were called `check()`? You'd have to write a comment "returns whether the path is allowed" ... and that comment would be an admission that the name failed.

Comments aren't forbidden. In this repo you'll find comments that explain **why** (a decision, a trap, an invariant) ... never **what** (because the name says that):

```php
// 'admin' role bypasses permission checks (super-user).
if (in_array('admin', $this->roles, true)) {
```

The comment explains **why** admin bypasses the checks ... you can't read that from the name.

## A name that survives your absence

The best test of all: **read the call out loud.**

"`$this->tagChips($meta)` ... clear."
"`$this->getDataBits($m)` ... what?"

If it stops you while reading, the name failed ... no matter how correctly the body is written.

A good name isn't about making code look pretty. It's about **surviving your absence**. When you leave the project, when a new member joins, when you come back to your own code six months later. A name is respect for the person who hasn't arrived yet.

→ [One thing (SRP)](/en/blog/one-thing-srp): a method and a class do only one thing
→ [DRY vs abstraction](/en/blog/dry-vs-abstraction): one source of truth, no interfaces for the sake of it
→ [Smoke tests](/en/blog/smoke-tests): rules enforced by a test = warning smoke signals

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
