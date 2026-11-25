---
title: "Tempo ... why sentence length is a design decision"
date: "2026-08-29"
version: "1.0.0"
tags: ["writing", "narrative-rhythm", "technical-writing", "blog"]
excerpt: "Steinbeck knew that short sentences create tension and long sentences release it. The same principle applies to technical articles."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Writing"
translation: "rhythm-part-4-tempo"
published: true
---

Read these two versions of the same sentence:

```markdown
# Version A
The server experienced an unexpected termination of the PHP
FPM worker process due to insufficient memory allocation
resulting from the argon2id hashing algorithm's default
memory cost parameter exceeding the available resources.

# Version B
Server crashed. Out of memory. Argon2id asked for 64 MB.
Shared hosting had 128 MB for everything.
```

Same information. Version A is 42 words. Version B is 22 words. Version B is the one you'll remember.

## Why sentence length matters

Sentence length is not a style preference — it's a rhythm tool. Short sentences create tension. Long sentences release it. Alternating between them creates the pulse that keeps readers engaged.

Steinbeck knew this. His sentences alternate between punchy and flowing:

```markdown
The autumn leaves blew over the moonlit streets.
A man in a black coat walked alone.
His hands were in his pockets.
The wind was cold.
```

Four short sentences. Then a longer one would break the pattern. The rhythm itself tells the story.

## Tempo by phase

Each phase has its own tempo:

### INTRO — punchy (5-15 words)
```markdown
Click page 3. The pager doubled.
Click again. Tripled.
```

Three sentences. Each one a beat. The reader is hooked before they can look away.

### HMLA — staccato (8-15 words)
```markdown
We had a finished, tested, secure system.
Everything worked on development, in Docker, on VPS.
Then came shared hosting.
First login attempt: server crashed.
```

Short sentences building pressure. Each one adds a problem. The reader feels the fog thickening.

### SLNKO — flowing (15-25 words)
```markdown
The server returns the full HTML blob: search input,
count info, table, pagination — all in one string.
HTMX finds the target element and replaces it.
```

Longer sentences now. The tension releases. The reader understands the solution.

### NOC — reflective (12-20 words)
```markdown
Cache isn't an automatic answer or an enemy.
It's a tool — and every tool has its place.
```

Medium sentences. Quiet. The reader is thinking, not learning.

### TÉMA — one sentence (10-20 words)
```markdown
A framework's magic is only as good
as the HTML you feed it.
```

One sentence. Maximum impact. The reader carries this with them.

## The tempo analyzer

We built a tool that measures sentence length across sections:

```bash
php bin/tempo-analyzer.php storage/blog/en/article.md
```

Output:
```
[OK] INTRO    Avg: 9 words/sentence (target: 5-15)
[OK] HMLA     Avg: 11 words/sentence (target: 8-15)
[!] SLNKO    Avg: 11 words/sentence (target: 15-25)
[OK] NOC      Avg: 13 words/sentence (target: 12-20)
```

The SLNKO section has sentences that are too short. That's a signal: the explanation isn't flowing enough. The fix: combine related sentences, add context, let the explanation breathe.

## The rhythm pattern

The full rhythm looks like this:

```
INTRO:   ▓▓░░░░░░░░  short, punchy
HMLA:    ▓▓▓░░░░░░░  staccato, building
SLNKO:   ▓▓▓▓▓░░░░░  flowing, explanatory
NOC:     ▓▓▓░░░░░░░  medium, reflective
OBJEKT:  ▓▓░░░░░░░░  2-3 sentences
TÉMA:    ▓░░░░░░░░░  one sentence
OUTRO:   ▓░░░░░░░░░  1-2 sentences + links
```

Short, short, long, medium, short, one, done. That's the pulse.

Tempo remains a tool, not a rule. Some articles need longer HMLA phases. Some need shorter SLNKO phases. The targets are guidelines — the rhythm is the goal.

---

Every word must work. If a word doesn't add meaning, cut it. If a sentence doesn't add rhythm, split it. Tempo is not decoration — it's structure.

---

→ [Part 5 — Two Tones](/blog/rhythm-part-5-bilingual) — writing in EN and SK without losing soul
→ [Part 3 — Night, Object, Theme](/blog/rhythm-part-3-night-object-theme) — the parts that make articles memorable

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
