---
title: "Clean code for kids ... part 5: smoke tests ... detectors that guard the rules for you"
date: "2026-08-11"
version: "1.0.0"
tags: ["clean-code", "php", "architecture", "testing"]
excerpt: "A rule without a test isn't followed. Part 5 explains how CorePatternTest scans all of src/ via tokens (not text regex), why it can't be fooled by a comment and how a red test ended the debate about a 65-line handle() method."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "smoke-test-pre-deti"
published: true
---

> This is part 5 of the **Clean code for kids** series. In [part 1](/en/blog/clean-code-part-1)
> we said that code is read more than it's written, in [part 2](/en/blog/names-are-documentation),
> why names are documentation for free, in [part 3](/en/blog/one-thing-srp),
> why a method and a class do only one thing, and in [part 4](/en/blog/dry-vs-abstraction),
> why premature abstraction is worse than duplication. All those rules
> have one thing in common: **this repo enforces them with a test, not
> a recommendation.** Today we show how those tests work ... and why
> they're called smoke tests.

## Smoke signal, but automatic

In [part 3](/en/blog/one-thing-srp) we introduced the term **smoke
signal** ... a warning sign that something is going on in the code: "this
method does A, B and C", "this name lies", "these two copies are
already drifting apart". It's useful eye training ... but it has one
weakness: **a human has to notice it.** At code review, at midnight,
after the third cup of coffee.

And here comes the point of this repo: **why let a human look for
smoke when you can build a detector that smells it for you ... always, on
every commit, without exception?**

That's what we call a **smoke test** ... a test that reports a warning
smoke signal automatically. It isn't written to verify that a function
computes correctly (those are classic tests). It's written to **guard a
rule**: "walk all of `src/` and tell me if smoke appeared anywhere".

## A rule without a test isn't followed

Remember the scenario from [part 1](/en/blog/clean-code-part-1) ...
code review without an automatic rule:

- Reviewer: "That method is 120 lines, but…"
- Author: "But it's logical, everything's related!"
- Reviewer: "I think it could be split…"
- Author: "I'll leave it for the refactor, I have a deadline."

And that's the end. Everyone has an argument, nobody's right. But when
the rule is a **test**, the discussion ends before it starts. Not
because people are bad ... but because **nobody can remember six rules on
every commit.** A detector can. It's exactly like a smoke alarm: it
doesn't wake you because you slept carelessly, but because it smells
smoke even when you don't.

## Five detectors in `CorePatternTest`

All the rules of the series are guarded by one file ...
`tests/Architecture/CorePatternTest.php` ... and in it live **five
detectors**. Each has its own method, its own way of searching and its
own message:

| Detector | What it guards | How it looks |
|---|---|---|
| `testNoOversizedMethods()` | chunky rule ... methods over 60 lines | token scanner: counts `{` and `}` |
| `testNoDynamicMagicMethods()` | magic methods `__call`/`__get`/… | regex on `function __x(` declarations |
| `testNoDynamicCodeExecution()` | `eval()` and `create_function()` | regex on calls |
| `testNoReflectionOutsideCoreModule()` | reflection outside `Modules\Core` | regex on `Reflection*` |
| `testNoServiceLocatorCalls()` | `Container::get()` / `->make()` / `->resolve()` | **code scanner without comments** |

And with them belongs a sixth ...
`testNoSpeculativeSingleImplementationInterfaces()` from [part 4](/en/blog/dry-vs-abstraction) ...
which counts interfaces with one implementation via a token analyzer
(baseline 0).

Today we look at what they have in common: **why they read tokens, not
text.**

## Why tokens, not "look for text"

When you want to know whether `eval()` is in the code, the simplest way
is to say "look for the string `eval(`". But that's a trap ... and this
is exactly where this repo's detectors differ from naive ones. Look at
the service-locator detector:

```php
public function testNoServiceLocatorCalls(): void
{
    // …
    $content = file_get_contents($file);
    // …
    // Scan code only ... docblocks and comments must not trip the tripwire.
    $codeOnly = $this->codeOnly($content);

    if (preg_match('/(?:ServiceContainer|Container)(?:::|->)\s*(?:get|make|resolve|instance)\(/', $codeOnly)
        || preg_match('/->(?:make|resolve)\(/', $codeOnly)
    ) {
        $violations[] = $this->relative($file);
    }
}
```

Notice the line `$codeOnly = $this->codeOnly($content);` ... that's the
key. The method `codeOnly()` runs the file through the PHP tokenizer
and **throws away all comments and docblocks**:

```php
private function codeOnly(string $content): string
{
    $code = '';

    foreach (token_get_all($content) as $token) {
        if (\is_array($token)) {
            if (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                continue;
            }
            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}
```

Why does this matter? Because **the PHP tokenizer knows what is code
and what is a comment.** A plain text grep doesn't. And from that come
two kinds of erroneous behavior that a token scanner avoids:

**False alarm.** You write in a docblock "Warning: never call
`Container::get()` from this module" ... and a grep for `Container::get(`
trips, even though you did nothing wrong. The docblock punishes you for
being polite. The token scanner throws the docblock away and continues.

**Deception.** Conversely ... you write an excuse in a docblock "this is
the only place we call `Container::get()`" and hide the real call
between the lines under it. A text grep might miss it (or not); a
tokenizer never misses it, because the call is code.

The rule that follows is exactly what we learned in [part 4](/en/blog/dry-vs-abstraction):
**a rule can't be bypassed by accident or on purpose.** The detector
reads code, not excuses. A comment isn't code ... and never counts.

## The chunky detector: counts braces, not words

The most interesting detector is the chunky rule ... the one that
measures whether a method fits on one screen (60 lines). Here a text
grep would fail completely: you can't "look for text" when you need to
measure the **size of a method body**. The detector does it by tokens ...
it finds `function`, then the first `{` (that's the body start), and
from there counts depth: every `{` raises it, every `}` lowers it. When
the depth hits zero, the body ended. It compares lines:

```php
if (null !== $closeLine && $closeLine - $openLine > self::MAX_METHOD_LINES) {
    $violations[] = sprintf(
        '%s::%s (%d lines)',
        $this->relative($file),
        $name,
        $closeLine - $openLine
    );
}
```

And watch out for one subtle detail ... a method without a body is
skipped:

```php
// A ';' before any '{' means a bodyless declaration (interface or
// abstract method) ... return null so it is skipped.
if (';' === $t) {
    return null;
}
```

An interface has the declaration `public function foo(): int;` ... no
body, no `{`. The token scanner recognizes it by the semicolon and
doesn't count "zero lines". A text grep would drown in such edge cases;
the tokenizer handles them naturally, because **it understands the
structure of the language.**

## Real story: when the detector caught 65 lines

And now the best part ... this repo has a real story where the detector
caught a violation before a human noticed it. It's documented in the
[final part of the types series](/en/blog/chunky-rule), so here's
just the short version:

During a refactor of `BlogArticleAction`, tag normalization was added
to the `handle()` method. The test failed with:

```
Methods longer than 60 lines are forbidden (chunky: one screen per function):
src/Modules/Core/Interfaces/HTTP/Actions/BlogArticleAction.php::handle (65 lines)
```

**Five lines over the limit.** Nobody found it at code review. Nobody
broke it on purpose. The detector simply measured it ... and the fix was
exactly what the whole series teaches: `handle()` stayed the story and
the details moved into named methods (`metaBits()`, `tagChips()`).

Notice what happened in that moment: the test wasn't an enemy, it was
an **alarm**. It didn't argue with you, you didn't have to debate ... it
said "65 lines" and you knew exactly what to do. A rule that can't be
bypassed doesn't have to be defended.

## An automatic rule = end of discussion

This is the philosophy that unites all five detectors: **when a rule is
automatic, you don't have to discuss it.** Code review can focus on
what a detector can never measure ... on design, on the domain, on the
story. Whether a method is too long is no longer debated: the test
says so.

And that's also the answer to "isn't 60 lines too little?" ... maybe.
But the boundary isn't important for being 60 or 80. It's important for
**being.** Unambiguous, measurable, automatic. The vague rule "methods
should be short" isn't followed; the rule "60 lines, measured by
tokens, enforced by a test" is followed without thinking.

Smoke tests are one control layer. In the [OpenSpec series](/en/blog/spec-as-source-of-truth)
we showed that this project controls rules on **four layers** ... spec,
code, tests and QA ... and the architecture tests are the layer that
holds the rest together: a spec can say "no magic methods", but only
`CorePatternTest` guarantees it actually never happens.

## Summary of part 5

1. **Smoke signal ≠ smoke test** ... a signal needs a human to notice
   it, a test catches it automatically, on every commit.
2. **A rule without a test isn't followed** ... nobody can remember six
   rules; a smoke alarm can.
3. **Five detectors in `CorePatternTest`** ... chunky rule, magic
   methods, `eval()`, reflection outside Core, service locators (plus
   baseline 0 from part 4).
4. **Tokens, not text** ... `codeOnly()` throws away comments, so the
   detector can't be fooled by accident or on purpose; the chunky
   detector counts `{` and `}`, and recognizes a bodyless method by a
   semicolon.
5. **Real story** ... `handle()` failed at 65 lines (5 over the limit),
   the detector measured it before a human did, the split into helpers
   fixed it.
6. **Link to the next part** ... detectors guard rules within modules;
   but who guards the boundaries **between** modules? About how
   `ModuleIsolationTest` guards who can talk to whom is the final
   [part 6](/en/blog/module-isolation).

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
