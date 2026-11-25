---
title: "OpenSpec for kids ... part 4: the spec as the source of truth ... four layers, one story"
date: "2026-08-09"
version: "1.0.0"
tags: ["openspec", "php", "workflow", "qa"]
excerpt: "A spec isn't a document, it's the source of truth. We show how the four layers ... spec, code, tests and QA ... check each other, on the live author-profile change example."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "spec-ako-zdroj-pravdy-pre-deti"
published: true
---

> This is part 4 of the **OpenSpec for kids** series. In [part 1](/en/blog/what-is-openspec)
> we found out what a change is. In [part 2](/en/blog/anatomy-of-a-change)
> we broke down its five files and in [part 3](/en/blog/lifecycle-of-a-change)
> the whole life cycle. Today we look at the most important thing: how
> **spec, code, tests and QA check each other** ... and why the spec is
> the source of truth, not just another document.

## What "source of truth" means

Imagine a game with four players. Each says something about the same
thing ... but in a different language:

| Player | Language | Says |
|---|---|---|
| **Spec** | GIVEN/WHEN/THEN | What must hold (requirements) |
| **Code** | PHP | How it actually works |
| **Tests** | PHPUnit | What is verified automatically |
| **QA** | JSON + verify.md | That all three of them actually connect |

The source of truth = the one with the last word in a dispute. In
OpenSpec that's the **spec**: code is written according to it, tests
verify it, and QA checks that the code and tests really talk about the
same spec, not about their own version of the story.

## Four layers on a live example

Let's take the change we've been following all along ...
`2026-08-09-author-profile` (author page + avatar). Its spec delta
contains four requirements. Let's walk one of them through all four
layers.

**1. Spec ... what must hold:**

```text
### Requirement: Self-hosted uploads serving

The starter kit MUST serve stored uploads at GET /storage/uploads/{path*}
read-only with hardened guards: dotfile basenames, .. segments, null bytes,
paths escaping storage/uploads/ and files whose server-detected MIME is not
in the upload whitelist MUST all return HTTP 404. Served files MUST use a
server-detected Content-Type and MUST never be executed.
```

Notice this isn't "we need to serve uploads". It's a **list of worlds
that must end in 404** and a few things that must hold. Every sentence
can become a test.

**2. Code ... how it actually works:**

```php
// ServeUploadedFileAction ... GET /storage/uploads/{path*}
if (!$this->isSafePath($relative)) {           // dotfile, .., null byte
    return Response::html('Not Found', 404);
}
$real = realpath($full);                        // symlink-safe containment
if (false === $real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
    return Response::html('Not Found', 404);
}
if (!$storage->isAllowed($relative)) {          // MIME whitelist
    return Response::html('Not Found', 404);
}
```

Three guards, three 404s ... exactly the worlds the spec listed. The code
is written so it **proves** the requirement, not just "resembles" it.

**3. Tests ... what is verified automatically:**

```php
// ServeUploadedFileTest
foreach (['../.env', 'a/../../b.png', '..%2f..%2f.env'] as $path) {
    self::assertSame(404, $this->handle($path)->getStatusCode(), $path);
}
self::assertSame(404, $this->handle('.env')->getStatusCode());
self::assertSame(404, $this->handle('2026/08/09/probe.php')->getStatusCode());
```

Every world from the spec has its test. And not just the direct one ...
e2e tests drive the request through the whole Kernel, so they also
verify URL decoding (`%2e%2e%2f` → `../`), which a direct action call
doesn't see.

**4. QA ... that they connect:**

And here comes the layer most projects don't have. When a change is
finished, QA (the `aif-verify` skill) creates a **coverage.json** ... a
matrix that links every requirement from the spec to specific
implementation files and specific tests:

```json
{
  "requirements": [
    {
      "id": "starter-kit.self-hosted-uploads-serving",
      "status": "covered",
      "implementation_evidence": [
        "src/Modules/Core/Interfaces/HTTP/Actions/ServeUploadedFileAction.php"
      ],
      "test_evidence": [
        "tests/Integration/ServeUploadedFileTest.php"
      ],
      "rules_gate": "pass"
    }
  ],
  "summary": { "covered": 4, "partial": 0, "missing": 0 }
}
```

This is **evidence**, not a promise. "Covered" doesn't mean "we think
it works" ... it means: here's the file that implements it, and here's
the test that verifies it.

## Why four layers and not two

If code and tests were enough, everything would be simpler. But each
layer catches a **different kind of lie**:

| Layer catches | Real-life example |
|---|---|
| Tests catch **regression** | Code stopped doing what it did yesterday |
| Code catches a **non-functional dream** | "Well, uploads already work" ... nothing was serving them |
| Spec catches a **bad assignment** | "serve uploads" without a list of the 404 worlds |
| QA catches a **gap between layers** | A requirement with no test ... "covered" without evidence |

The most interesting is the last row. On the author-profile change we
had 194 tests ... and still the review found three real problems
(identity-keyed grid, nonce substitution on binary responses, preview
fallback). Tests verify that the code does what it should. **QA and
review verify that what it should do is right.** That's why in part 3
the whole phase 5 is called "nobody writes perfect code on the first
try".

## Aif-verify ... the final checker

When a change is done, `aif-verify` doesn't just run the gates ... it
**attests** them into `verify.md`. Look at that document from the
previous change (`2026-08-08-mobile-nav-menu`):

```text
## 3. Quality gates
| Gate | Result |
| PHPUnit (unit + integration + css + architecture) | 160 tests |
| PHPStan level 9 (full project) | [OK] No errors |
| PHP-CS-Fixer dry-run | 0 files |
| Codeception acceptance | 13 tests |

## 4. Coverage
All 3 spec-delta requirements covered by tests + live verification

## Assessment
Verdict: PASS ... all quality gates green, OpenSpec validates with zero
issues, all 11 tasks complete, tasks ↔ spec requirements consistent
```

Notice the last sentence: **tasks ↔ spec requirements consistent**.
That's the whole point. QA doesn't just check "tests passed" ... it
checks that every requirement has a task, every task has a test and
every test relates to a requirement. Four layers form a **closed
circle** in which nothing can get lost.

## What happens when one layer lies

Let's imagine we didn't have the fourth layer. A person would say "the
change is done, tests passed". And the tests passed because they only
tested half the requirement ... the half that's easy to test. The rest is
"covered" in the sense that nobody talks about it.

With coverage.json that's impossible. Every requirement must show a
**concrete implementation file and a concrete test** ... or it's
`missing` and the change isn't archived. No "well, it probably works".
Either you have evidence, or you don't have a change.

## Live status: where is author-profile right now?

And again a meta-layer. I'm writing this article at the moment when the
author-profile change has all four layers done:

- **Spec** ... 4 requirements in `specs/starter-kit/spec.md`
- **Code** ... BlogArticlesService, AuthorProfileAction,
  ServeUploadedFileAction…
- **Tests** ... 194 tests, including e2e traversal tests through the
  Kernel
- **QA** ... not yet: coverage.json and verify.md for this change are
  waiting for the final `aif-verify` before archiving

That means that when you read this, the change is either already all in
the archive ... or it has four layers ready and is waiting for the last
one. Both are correct. The source of truth isn't about everything being
done ... it's about **always knowing what is proven and what isn't yet**.

## Why we do all this

At the start of the series we said OpenSpec is a car manual with a
history of decisions. Today we see the second half: it's also a
**system of evidence**. The spec says what must hold. The code proves
it. Tests guard it. QA checks that none of them is lying. And when in
six months someone asks "why does this route serve uploads and why 404
and not 403?" ... the answer is all in the archive.

In [part 5](/en/blog/openspec-ai-factory) we'll show the last
piece of the puzzle: how AI Factory skills drive this whole workflow ...
`aif-plan`, `aif-implement`, `aif-verify`, `aif-done` ... and what the
human + AI collaboration looks like in this system. See you there!

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
