---
title: "Fog and Sun ... how to write problems that keep people reading"
date: "2026-08-29"
version: "1.0.0"
tags: ["writing", "narrative-rhythm", "technical-writing", "blog"]
excerpt: "The problem must be personal — not 'problem X exists' but 'Y happened and it was bad.' How fog and sun phases create the tension that keeps readers engaged."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Writing"
translation: "rhythm-part-2-fog-and-sun"
published: true
---

Read this sentence: "Performance issues can occur when using argon2id on shared hosting."

Now read this one: "First login attempt: server crashed. Second one too. Third ... same result."

Both describe the same problem. One is a documentation note. The other is a story. The difference is fog.

## The fog phase

Fog is confusion, mystery, a problem that hasn't been solved yet. It's the part of the article where the reader doesn't know the answer — but wants to.

The fog phase must be **personal**. Not "problem X exists" but "this happened to us and it was bad."

```markdown
# Bad: abstract problem
Performance issues can occur when using argon2id on shared hosting
with limited RAM allocation.

# Good: personal problem
We had a finished, tested, secure system. PasswordHasher used
PASSWORD_ARGON2ID. Everything worked on development, in Docker,
on VPS.

Then came shared hosting.

First login attempt: server crashed.
```

The first example describes a condition. The second describes an event. Events are memorable. Conditions are forgotten.

## Why short sentences create tension

The fog phase uses staccato rhythm — short sentences, each one adding pressure:

```markdown
Click page 3. The pager doubled.
Click again. Tripled.
Four pagers stacked on top of each other,
each slightly different — some with Prev,
some with Next, none with both.
```

Each sentence is a beat. The reader feels the problem growing. That's tension — and tension keeps people reading.

If you write the fog phase in long, flowing sentences, the tension dissolves. The reader skims. The fog clears before it should.

## The sun phase

Sun is clarity — the moment the reader understands the problem and sees the solution. But the solution must be **earned**. If you rush from fog to sun, the reader feels cheated.

```markdown
# Bad: rushed solution
The fix is to use bcrypt instead of argon2id.

# Good: earned solution
Jeden riadok. Žiadna chyba v logike, žiadny bug.
Len algoritmus, ktorý si pýta viac pamäte,
než server má.

I switched PASSWORD_ARGON2ID to PASSWORD_BCRYPT.
Bcrypt needs ≈4 MB RAM ... 16 times less.
```

The second example makes the reader feel the "aha!" moment. The fix is simple — but it's presented as a discovery, not a directive.

## Code blocks are proof, not decoration

In the fog phase, code shows the problem:

```php
// PasswordHasher.php ... before the change
public const string ALGORITHM = PASSWORD_ARGON2ID;
```

One line. No logic error, no bug. Just an algorithm that demands more memory than the server has. The code is the evidence.

In the sun phase, code shows the fix:

```php
// PasswordHasher.php ... after the change
public const string ALGORITHM = PASSWORD_BCRYPT;
```

Same structure, different constant. The reader sees the change and understands it instantly.

Code blocks in HMLA are "look, this is broken." Code blocks in SLNKO are "look, this is how we fixed it." Code blocks anywhere else are decoration.

## The gap between fog and sun

The best articles have a moment of silence between the problem and the solution. A breath. A horizontal rule. A sentence that lets the reader sit with the problem before moving on.

```markdown
The admin panel looked broken.
But the code was correct. Or so it seemed.

---

Here is what happened on a pagination click:
```

That gap is where the reader's curiosity lives. Don't skip it.

---

The fog phase earns the reader's attention. The sun phase rewards it. Skip either one, and the article becomes documentation — technically correct, emotionally dead.

Fog and sun are the foundation. Every article has them. The phases that follow — night, object, theme — are what turn a technical note into a story you keep.

Fog remains the hardest phase to write. Sun remains the most satisfying. The gap between them is where curiosity lives.

---

The problem must be personal. The solution must be earned. Everything else is commentary.

---

→ [Part 3 — Night, Object, Theme](/blog/rhythm-part-3-night-object-theme) — the parts that make articles memorable
→ [Part 1 — The Article Nobody Finished](/blog/rhythm-part-1-nobody-finished) — why rhythm matters

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse tool
