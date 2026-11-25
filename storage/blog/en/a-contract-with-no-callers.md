---
title: "Themes for kids ... part 3: a contract with no callers"
series: "Themes for kids"
series-part: "3"
date: "2026-10-03"
version: "1.0.0"
published: true
tags: ["architecture", "themes", "refactoring", "tests"]
excerpt: "ThemeRenderer had two implementations and zero calls ... and no test noticed. Part 1 drew the boundary, part 2 split the frontend, part 3 finds code nobody calls, and the rule that will not find it again."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "kontrakt-bez-zakaznikov"
---

> [Part 1](/en/blog/themes-as-modules) drew the line between kernel and
> theme. [Part 2](/en/blog/a-package-per-theme) split the frontend into five
> packages. This part finds the code that stayed inside that line ... and that
> nobody called.

You are a PHP developer. You open an interface, you see two classes
implementing it, and you think: "this is used".

It is used.

It is not.

## The plan that did not happen

Part 1 built `ThemeModule`: a contract with one method per public surface.
I wrote a sentence in it about what would happen to the old one.

```php
// src/Themes/starter/StarterRenderer.php — the plan from ADR-0003
// "Renderer keeps the ThemeRenderer six public methods as
//  explicit one-line delegates until a later change folds them in."
```

That later change never came.

And what stayed in its place were not one-line delegates. They were **full
bodies**, two hundred lines of code someone wrote, someone read, and nobody
ever ran.

## Measuring instead of assuming

First I told myself "it is definitely used somewhere, I am just looking in the
wrong place".

So I checked. Not by estimating, by grepping.

| What I looked for | What I found |
|---|---|
| `implements ThemeRenderer` | 2,`StarterRenderer`, `erik\Renderer` |
| Type-hinted sites (`: ThemeRenderer`, `ThemeRenderer::`) | **0** |
| `$obj->blogIndex(`, `$obj->homepage(`, `$obj->blogArticle(` … | **0** |
| References outside `src/Themes/` | 0 (one stale docblock sentence) |

Every hit in the third row was `$module->` on a `ThemeModule`, not on a
renderer.

This is the part of the work that matters, and also the best part:
**measuring instead of assuming**. Until I grepped, `ThemeRenderer` was a
working contract in my head. After the grep it was dead.

| Without the audit | With the audit |
|---|---|
| "It is used, I just can't find it" | "It is not used at all" |
| "Two implementors = live usage" | "Two implementors = two copies of the same dead thing" |
| A refactor with guessing | a refactor with a fact |

## What the dead contract dragged with it

The lines are not the whole story. The most interesting part was what
disappeared **besides** the methods.

```php
// src/Themes/erik/Renderer.php — this went with the contract
$articles[] = new Article(
    slug: self::str($card, 'slug'),
    title: self::str($card, 'title'),
    date: self::str($card, 'date'),
    // … 15 more fields read out of an untyped array
);
```

That `toArticles()` mapped `array $articles`: an array of cards, onto the
`Article` VO. So there were **two shapes for one concept** at once: `Article`,
and "an array that happens to be called that".

And the kernel had its own shape: `PageContext` carries articles, names, counts,
pagination context, all typed.

So the dead contract was not a leftover from the refactor. It was a **deliberate
note about a style the contract contradicted**. Dead code usually says something
about what someone believed at the time.

From the result:

| File | Before | After | What stayed |
|---|---|---|---|
| `ThemeRenderer.php` | 92 | deleted |, |
| `StarterRenderer.php` | 443 | 120 | article page + TOC |
| `erik\Renderer.php` | 266 | 135 | 10 static methods |

**−573 lines of code** in total, none of which ever executed.

## The tests noticed: the wrong way

The best moment of the refactor: PHPStan level 9 was green **immediately** after
I deleted the methods.

Not "green after a while". Green on the first run.

Level 9 resolves every method call against its class. If anything had called
`ThemeRenderer::blogIndex(...)`, PHPStan would have said "no such method".

So level 9 was not green as a sufficiently-good test. It was green as
**evidence**. As a measurement, not as an absence.

And the other tests? Also green. 3608 tests, 21496 assertions, not one failing.

Because a test that calls `ThemeModule::blogIndex()` cannot know that an
interface nobody calls stands next to it. How would it? No test verifies "nobody
uses this".

## The rule that was missing

The project had a guard against speculative interfaces. It was correct, just
narrow.

`CorePatternTest` flags an interface when it has **exactly one** implementation
and no consumer outside its own module.

`ThemeRenderer` had **two**. The rule says "exactly one", so two passed.

The rule is not wrong because it is bad. It is wrong because it is **more
specific than the problem**. The problem was not "too few implementations". The
problem was "nobody uses this".

The new `UnconsumedInterfaceTest` guard has two arms:

| Arm | Assertion |
|---|---|
| `testEveryInterfaceHasAnImplementation` | every interface in `src/` has at least one implementation |
| `testEveryInterfaceHasAConsumerOutsideItsImplementors` | every interface outside the kernel has a consumer that is neither its own declaration nor one of its implementors |

Two decisions behind it, and both are hard-won mistakes.

### An anonymous class is an implementor

The first version of the scan required a class *name*. And immediately it
reported:

```
Interfaces with no implementation: CliOutput
```

I nearly deleted it.

`CliOutput` **is** implemented, in `Bootstrapper`, like this:

```php
// src/Modules/Core/Infrastructure/Bootstrap/Bootstrapper.php:65
$output = new class () implements CliOutput {
```

An anonymous class has no name. It has no name until you give it one. I fixed
the regex to match `class` with an optional name.

A rule that killed a live contract was wrong. Not because it was strict,
because it was incomplete.

### The kernel is exempt, but not blind

`Modules\Core` and `Modules\Database` are the shared kernel, its interfaces are
headers ("libc headers"). They do not need a consumer inside the project.

But they **do** need an implementation. A header nobody includes is not a
header. It is a note.

## The second dead thing, found by the same guard

The new test failed on its **first run**. Not because of `ThemeRenderer`: that
one was deleted. Because of something else:

```
Interfaces with no implementation:
  ModuleInterface (src/Modules/Core/Interfaces/HTTP/ModuleInterface.php)
```

No class implemented it. The only reference outside its own declaration was its
own declaration.

```php
// src/Modules/Core/Interfaces/HTTP/ModuleInterface.php — deleted (46 lines)
interface ModuleInterface
{
    public function name(): string;
    public function register(Kernel $kernel): void;
    public function migrations(): array;
}
```

It described module registration. And the kernel **replaced** module
registration: middleware is discovered through `#[AsMiddleware]`, migrations
through `#[AsMigrator]`. Attribute discovery. The interface stayed behind as an
empty box for something that had moved on.

Two dead contracts. I found the first by auditing. The **guard I wrote for the
first** found the second.

That is a measurable result: the rule that removed the first mistake found
the second while being written, not while being looked for.

## When this does not hold

Let us be honest, three things.

**The guard produces false positives, and that is fine.** The first version of
the implementor scan flagged `CliOutput` as a dead contract. That was a bug in
the scan, not in the code, and fixing it meant fixing the rule, not routing
around it. Had I added `CliOutput` to an exemption list, the guard would have
lost the ability to find the real problem.

**The guard cannot tell whether an interface is "deliberate".** Some interfaces
have zero implementations on purpose, to record a contract that arrives later.
The guard flags them. This project has none, and `ModuleInterface` was not one
(it described something that already worked a different way), so no exemption
was added.

**460 lines of dead code is not the harder case.** The harder case is dead
code that looks alive. `ThemeRenderer` had two implementations that called each
other through their own static methods, the call existed, it just was not the
call we were looking for. It was readable and entirely pointless. Someone
opening it would not learn from the method list that it was dead.

## What comes next

In part 4 we look at the other side of the same coin: what happens when a
**guard is too narrow**.

`CorePatternTest` flags "exactly one implementation". Two implementations pass.
I showed that case is dead. But what about an interface nobody **outside**
implements?

On why "no external consumer" is not always a mistake, and why the answer to
that is not an exemption list.

## Sources

- [PHP Manual, interfaces](https://www.php.net/manual/en/language.oop5.interfaces.php): the official `interface` documentation
- [PHPStan](https://phpstan.org/): static analysis at level 9
- [PHPUnit](https://phpunit.de/): the testing framework documentation
- [Refactoring Guru, Extract Interface](https://refactoring.guru/design-patterns/extract-interface): when to create an interface, and when not to
- [Nativa Minimal Documentation](/docs/getting-started): the kit's official guide