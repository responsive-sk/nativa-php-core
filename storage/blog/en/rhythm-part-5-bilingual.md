---
title: "Two Tones ... writing in EN and SK without losing soul"
date: "2026-08-29"
version: "1.0.0"
tags: ["writing", "narrative-rhythm", "technical-writing", "blog", "bilingual"]
excerpt: "EN is Steinbeck — direct, active, every word works. SK is Dostoevsky — deeper, reflective, wider context. Same rhythm, different soul."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Writing"
translation: "rhythm-part-5-bilingual"
published: true
---

Read these two versions of the same opening:

```markdown
# EN
Click page 3. The pager doubled.
The code was correct. Or so it seemed.

# SK
Kliknite stránku 3. Pager sa zdvojnásobil.
Kód bol správny. Alebo sa tak zdalo.
```

Same structure. Same rhythm. Different soul. The EN version is direct. The SK version has a slightly wider pause — "alebo sa tak zdalo" carries more weight than "or so it seemed." That's the difference between Steinbeck and Dostoevsky.

## EN: Steinbeck direct

EN technical writing follows Steinbeck's economy: every word must work. No fillers. No "it is important to note that." Active voice. Short sentences.

```markdown
# Bad EN
It should be noted that the caching mechanism
is not suitable for user-specific data.

# Good EN
Cache has no business with user data.
```

The good version is 7 words. The bad version is 15. Both say the same thing. The good version says it with respect for the reader's time.

Rules for EN:
- Active voice everywhere: "Server returns" not "The server is returning"
- No "it is important" — say "Important:"
- Short sentences, minimum adjectives
- Code comments in EN
- Theme: human, not academic

The fix: replace passive with active, cut fillers, trust the reader to understand without hand-holding.

## SK: Dostoevsky depth

SK technical writing allows more context, wider connections, a slightly reflective tone. The NOC phase can be longer. The theme can carry a philosophical undertone.

```markdown
# Bad SK (direct translation of EN)
Cache nemá čo robiť s user datami.

# Good SK
Cache zostáva dobrým sluhom.
Chyba je nechať ho stať sa pánom.
```

The SK version adds a metaphor — "servant/master" — that the EN version doesn't need. SK readers expect that slight extra layer. It's not verbosity — it's depth.

Rules for SK:
- Wider context, more reflective NOC phase
- Code comments in SK (if the article is SK)
- Theme: human, with philosophical undertone
- Metaphors are welcome (if they add meaning)
- "Alebo sa tak zdalo" works better than "Or so it seemed"

## The frontmatter contract

Both versions share a `translation` key:

```yaml
# EN version
translation: "rhythm-part-5-bilingual"

# SK version
translation: "rhythm-part-5-bilingual"
```

The blog engine uses this to link the two versions. The slug stays the same. The title and excerpt change. The rhythm stays.

## Same structure, different voice

Here's a full section side by side:

```markdown
# EN — HMLA phase
We had a finished, tested, secure system.
PasswordHasher used PASSWORD_ARGON2ID.
Everything worked on development, in Docker, on VPS.

Then came shared hosting.

First login attempt: server crashed.

# SK — HMLA phase
Mali sme hotový, otestovaný, bezpečný systém.
PasswordHasher používal PASSWORD_ARGON2ID.
Všetko fungovalo na vývoji, v Dockeri, na VPS.

Potom prišiel shared hosting.

Prvý prihlasovací pokus: server spadol.
```

Identical structure. Identical rhythm. Different language. The SK version has slightly longer words — that's normal. The rhythm (short, short, short, pause, short) stays the same.

## When to write which

Write EN when:
- The audience is international
- The article is part of an EN-first series
- You want Steinbeck directness

Write SK when:
- The audience is Slovak/Czech
- The article is part of a SK-first series
- You want Dostoevsky depth

Write both when:
- The project is bilingual (like Nativa Minimal)
- You want maximum reach
- The `translation` key links them

---

Language changes the tone, not the truth. The rhythm stays. The soul adapts.

---

→ [Part 6 — Rhythm Profiles](/blog/rhythm-part-6-profiles) — choosing your rhythm for bug stories, tutorials, and philosophy
→ [Part 4 — Tempo](/blog/rhythm-part-4-tempo) — why sentence length is a design decision

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
