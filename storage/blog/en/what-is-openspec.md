---
title: "OpenSpec for kids ... part 1: what OpenSpec is and why changes aren't written directly into the spec"
date: "2026-08-09"
version: "1.0.0"
tags: ["openspec", "php", "workflow"]
excerpt: "A new series. What OpenSpec is, what a change is and why nobody in this project touches the spec directly ... with a living example running in parallel with this article."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "co-je-openspec-pre-deti"
published: true
---

> This is part 1 of a new series, **OpenSpec for kids**. In this series
> we show how changes are managed in this project: what OpenSpec is,
> what a single change consists of and what its path looks like from an
> idea to the archive. Today we start from the very beginning ... **why
> changes aren't written directly into the spec**.

## A car manual vs. a project manual

Imagine building a car from a manual. The manual has 200 pages. One day
you think: "I want different seats" ... and **right in the manual** you
cross out the seats and draw new ones. Does it work? For a while. But a
month later nobody remembers why the seats are different, which parts
of the car changed because of it, or whether it was ever tested. The
manual is up to date, but the **history of decisions is gone**.

In this project we have a manual ... it's the **spec**
(`openspec/specs/starter-kit/spec.md`), and it's the source of truth:
39 requirements, each with GIVEN/WHEN/THEN scenarios. And one strict
rule applies: **nobody ever writes into the spec directly.** Every
behavior change goes through a **change**.

## What a change is

A change is a small, bounded package that describes ONE change:

```text
openspec/changes/2026-08-09-blog-author-linking/
├── .openspec.yaml        # meta: when it was created, what the goal is
├── proposal.md           # why we're doing it (and what we're NOT doing)
├── design.md             # how we'll do it technically + limits
├── tasks.md              # tasks: implementation, tests, quality
└── specs/starter-kit/    # new requirements for the canonical spec
    └── spec.md
```

When a change is done and verified, it moves to
`openspec/changes/archive/` and its requirements are merged into the
canonical spec. **The archive is the history of decisions** ... exactly
what was missing from the car manual. This repo already has seven
archived changes, from the first `minimal-starter-kit` through the
locale module to the mobile menu.

## Why it isn't bureaucracy

At first glance it looks like extra paperwork. But each file solves one
thing:

| File | Answers the question |
|---|---|
| `proposal.md` | Why are we doing it? What do we deliberately NOT do? |
| `design.md` | How exactly? Which project rules must we respect? |
| `specs/…/spec.md` | How do we verify it works? (GIVEN/WHEN/THEN) |
| `tasks.md` | What exactly needs doing and when is it done? |
| `archive/` | Why did we decide this way (in six months)? |

Small changes = a small change. In the archive there's also
`2026-08-07-fix-pentest-f7-f8` ... a fix of two security findings that
went through exactly the same workflow. Nothing is governed by the size
of the change, but by whether it changes behavior.

## GIVEN / WHEN / THEN ... the language of requirements

Requirements in the spec aren't written like "we need to link the
author with the user". They're written as **verifiable scenarios**:

```text
GIVEN an article with author_email: eva@example.test
WHEN /blog is rendered
THEN Eva Autorka's name from the users database is displayed
```

GIVEN = what's prepared. WHEN = what happens. THEN = what must be
true. Such a requirement can be **verified by a machine** ... a test
either fulfills it or not. No "it should probably work".

## A living example: this article is being written in parallel with a change

And that's the point of the whole series. While I'm writing these
lines, the change `2026-08-09-blog-author-linking` is running in the
repo ... linking the article author with a real users account. In
[part 2](/en/blog/anatomy-of-a-change) we'll break down what exactly
is in each file of such a change, and in [part 3](/en/blog/lifecycle-of-a-change)
we'll walk the whole path from idea to archive ... on this living
example.

And the nicest meta-layer? **This article about how to do a change
properly is being created exactly the way it talks about.** Clean
manuals, clean decisions, clean archives.

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
