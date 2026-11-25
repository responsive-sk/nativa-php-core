---
title: "Bug Story, Tutorial, Philosophy ... choosing your rhythm"
date: "2026-08-29"
version: "1.0.0"
tags: ["writing", "narrative-rhythm", "technical-writing", "blog"]
excerpt: "Not all articles have the same rhythm. A bug story has a long fog phase. A tutorial has a long sun phase. A philosophy piece has a long night phase. The article's purpose chooses its rhythm."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Writing"
translation: "rhythm-part-6-profiles"
published: true
---

You're writing an article about a bug you fixed. Should it follow the same rhythm as a tutorial about setting up Docker?

No. The bug story needs a long fog phase, the reader needs to feel the problem. The tutorial needs a long sun phase, the reader needs to see the solution step by step. The article's purpose chooses its rhythm.

## Bug Story: fog dominant

A bug story is about a problem that happened and how you fixed it. The reader wants to feel the confusion, then the relief.

```
INTRO  [10%]  Hook
HMLA   [35%]  Long — problem grows, cascade, chaos
SLNKO  [20%]  Quick — the fix is usually simple
NOC    [15%]  Medium — why this happened
OBJEKT [10%]  Short — X remains, just not here
TÉMA   [5%]   One sentence
OUTRO  [5%]   Links
```

The fog phase dominates because the story is about the problem. The sun phase is short because bug fixes are usually one line. The reader's journey is: confusion → clarity → understanding.

**Example:** "Click Page 3 ... and the Pager Doubled"

The article spends 44% of its length describing the problem. The fix is three lines of code. That's correct, the story is about the bug, not the fix.

## Tutorial: sun dominant

A tutorial is about learning something new. The reader wants to see the solution, step by step, with code.

```
INTRO  [5%]   Hook (short)
HMLA   [10%]  Short — "this doesn't work yet"
SLNKO  [50%]  Long — step by step, lots of code
NOC    [10%]  Short — why this approach
OBJEKT [10%]  Medium — when to use this
TÉMA   [5%]   One sentence
OUTRO  [10%]  Links to next steps
```

The sun phase dominates because the story is about the solution. The fog phase is short, the reader knows they don't know how to do this. The reader's journey is: gap → filling → completion.

**Example:** "MoonShine Features + HTMX"

The article spends most of its length showing how to build toast notifications, live search, and form submit. The problem ("admin panels need JavaScript frameworks") is stated once and left behind.

## Philosophy: night dominant

A philosophy article is about understanding why something is the way it is. The reader wants to think, not learn.

```
INTRO  [10%]  Hook
HMLA   [10%]  Short — context
SLNKO  [15%]  Short — facts
NOC    [40%]  Long — reflection, depth, "why"
OBJEKT [10%]  Medium — closing
TÉMA   [10%]  Maybe 2-3 sentences (exception)
OUTRO  [5%]   Links
```

The night phase dominates because the story is about understanding. The sun phase is short, the facts are given quickly. The reader's journey is: question → reflection → insight.

**Example:** "Philosophy of Fewer Layers"

The article spends most of its length explaining why fewer dependencies matter. The code examples are minimal. The reader's journey is: "why zero dependencies?" → understanding → agreement.

## Review / Comparison: balanced

A review article compares options. The reader wants facts, tables, and a clear recommendation.

```
INTRO  [10%]  Hook
HMLA   [15%]  "Why we're comparing"
SLNKO  [30%]  Facts, tables, code
NOC    [20%]  "What we learned"
OBJEKT [10%]  When to use what
TÉMA   [10%]  One sentence
OUTRO  [5%]   Links
```

No single phase dominates. The reader's journey is: context → facts → understanding → decision.

**Example:** "Theme Comparison: Starter vs Magazine vs Minimal"

The article presents three themes with equal weight. The night phase explains the philosophy behind each. The reader's journey is: "what are the options?" → comparison → choice.

## How to detect the profile

Ask one question: **what is the article about?**

```markdown
# The decision tree:
A problem that happened → Bug Story (HMLA dominant)
Learning something new   → Tutorial (SLNKO dominant)
Understanding why       → Philosophy (NOC dominant)
Comparing options       → Review (balanced)
```

The rhythm diagram tool shows you which profile your article has:

```bash
php bin/rhythm-diagram.php storage/blog/en/article.md
```

```
Profile: Bug Story
HMLA dominance: 44% | SLNKO: 16% | NOC: 33%
```

If the profile doesn't match your intent, restructure the article.

---

The article's purpose chooses its rhythm. Not the other way around. A bug story with a long sun phase is a tutorial in disguise. A philosophy piece with a long fog phase is a bug story that forgot to be about understanding.

---

→ [Part 1, The Article Nobody Finished](/blog/rhythm-part-1-nobody-finished): why rhythm matters
→ [Part 2, Fog and Sun](/blog/rhythm-part-2-fog-and-sun): how to write problems that keep people reading

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
