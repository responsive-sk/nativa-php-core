# Rhythm Is an Aid, Not a Gate

**Plan file:** `openspec/changes/rhythm-is-an-aid-not-a-gate.md`
**Mode:** full (no git branch — `git.create_branches: false`)
**Created:** 2026-10-05
**Base branch:** `master`

## Original Request

```
ano > rytmus budeme menit podla vole agentov a guests ... sme tu pre nich a a nie oni pre nas > pravidlo bude iba optional pomoc pre autora ... aif plan prosim
```

## Settings

| Setting | Value |
|---|---|
| Testing | **Yes** — PHPUnit, including a guard that keeps the decision from being silently reverted |
| Logging | **Verbose** |
| Docs | **Mandatory docs checkpoint** |
| Gates | `composer test`, `composer phpstan`, `composer cs-fix-dry`, `composer rector-dry`, `make pre-commit` |

## Roadmap Linkage

**Milestone:** "Blog voice follows the readers, not the rhythm template"
**Rationale:** The seven-phase template is a tool in the author's hand. This
milestone demotes it to exactly that, and names the instrument that replaces it
as a decision input — reader questions — without building that instrument yet.

---

## Settled Decisions

| Question | Decision |
|---|---|
| Is the 7-phase rhythm a gate | **No.** Advisory aid for the author. |
| What decides the voice | **The readers** — guests and agents, in that order. We are here for them. |
| What never becomes optional | Word dignity, truth, a real source with a link. Those stay hard. |
| What replaces a score as evidence | **Reader questions**, collected by the planned `Modules\Questions` (see `reader-questions-module.md`). |
| Do the rhythm scripts go away | **No.** They stay, they get better, and they stop being able to fail a build. |
| Does the score matrix go away | It stays as a **dated snapshot**, clearly marked as an experiment, never as a target. |

## Problem

The kit grew a narrative-rhythm apparatus: a skill, three PHP tools under
`bin/`, and a generated score matrix over all 61 articles. None of it is
wired into a gate, and that is exactly the problem. An unwired tool that
prints "failed" is one `run:` line away from being wired, and nobody has to
decide anything.

The evidence that it must never be wired is already in the repo:

```text
lekalo-upgrade-pre-deti.md          Result: 8 passed, 1 failed
lekalo-trace-manifest-pre-deti.md   Result: 7 passed, 2 failed
posledny-pin-pre-deti.md            Result: 6 passed, 3 failed
uow-philosophy.md                   Result: 8 passed, 1 failed
semanticky-model-pre-deti.md        Result: 6 passed, 3 failed
lekalo-tri-verzie-pre-deti.md       Result: 6 passed, 3 failed
```

The newest article of the series scores exactly like the weakest published
ones. `audit-rhythm.php` exits **1** on all of them, including
`hello-world.md`. Wiring it into CI would not improve the writing; it would
teach a rule that the writer cannot satisfy without abandoning the house
convention (`## Zdroje` with real links) to satisfy a string matcher that
wants `→ links after ---`.

Worse, `docs/rhythm-score-matrix.md` already turned that machinery into a
progress report: SK average 2.0/7 to 2.9/7, articles below 3/7 down from 43
to 22. Nobody read a single article differently because of that number. It
measures the template, not the reader.

**The inversion this plan fixes.** An author polishing against a phase
checklist is serving the checklist. The readers are the only party in this
arrangement who cannot be argued with later.

## What does NOT change

The mechanical half of `docs/blog-voice.md` stays exactly as strict, because
it is enforced and because each item is a way of being decent rather than a
taste:

- `HumanLanguageTest` — dignity, no filler words, no redundant demonstratives
- `BlogVoiceTest` — a real `## Zdroje` section with at least one link, zero
  emoji, `translation:` pairing both ways, semver, unique `series-part`
- cover contracts — deterministic, series-aware, code taken from the article
- `docs/blog-voice.md` own typography rules: no `--` in prose, rhythm of
  lines, real code from the repository

Nothing in this plan relaxes any of them.

## Non-Goals

- Building `Modules\Questions`. It stays a separate plan
  (`openspec/changes/reader-questions-module.md`) that this one only points
  at.
- Analytics of any kind. No page-view instrumentation, no external services —
  the kit's zero-dependency rule is not negotiable, and a traffic number is
  not an answer anyway.
- Rewriting existing articles to a higher rhythm score. The score is not a
  backlog.
- Deleting the rhythm skill or its reference examples.

---

# Phase 1 — Make the advisory status structural

The decision has to be more than a sentence. Today the tools **exit 1**; a
sentence in a doc does not stop the next agent from wiring them.

- [ ] **1.1** `bin/ops/audit-rhythm.php` — **default exit 0**. Print an
  `ADVISORY` banner above the result block: the nine checks measure the
  template, not the reader, and the published corpus does not pass them
  either. Add `--strict` as an explicit opt-in that restores exit 1 for a
  human who wants a hard answer on one file. Keep every existing check and
  every printed line; only the meaning of the exit code changes
- [ ] **1.2** `bin/ops/rhythm-diagram.php` and `bin/ops/tempo-analyzer.php` — same
  banner, same reasoning in their file headers. They print, they do not judge
- [ ] **1.3** Verify the exit-code contract directly, before and after:
  `php bin/ops/audit-rhythm.php storage/blog/hello-world.md` must exit **0** by
  default and **1** with `--strict`. A test that cannot fail is worth nothing
- [ ] **1.4** `.agents/skills/narrative-rhythm/SKILL.md` — an advisory banner
  at the top, in the same wording as the tools. The skill is tracked in git,
  so the next agent reads it there first. `references/EXAMPLES.md` stays
  untouched: examples are still good examples

**Dependencies:** none.
**Commit:** `chore(blog): make the rhythm audit advisory instead of failing`

---

# Phase 2 — Write the decision where it is read

- [ ] **2.1** `docs/blog-voice.md` — new section after `## Rytmus riadkov`:
  **"Rytmus je pomôcka, nie pravidlo"**. Three sentences of substance:
  the seven phases are a tool in the author's hand; we are here for the
  readers, not they for us; the voice changes when the readers ask, and the
  instrument for that asking is the question box, not a score
- [ ] **2.2** Same file — **"Ako sa hlas mení"**: what counts as evidence and
  what does not. Evidence: the same reader question asked on one article
  again and again, a question that says where the text lost someone. **Not**
  evidence: a phase score, a paragraph count, a reading-time estimate. Link
  both plans so the instrument has a name
- [ ] **2.3** `docs/rhythm-score-matrix.md` — a status banner above the
  tables: dated 2026-08-26, an experiment that has already run, **not a
  target and not a backlog**. Leave the numbers exactly as they were; the
  file's value is being an honest record of what was tried, and rewriting the
  numbers would destroy that
- [ ] **2.4** `AGENTS.md`, Agent Rules — one bullet: the narrative rhythm is
  an optional aid, never a gate, and an article is not improved by scoring
  higher on it. Name the three exceptions that stay hard, so the bullet
  cannot be read as "the rules are gone"

**Dependencies:** 1.x (the wording refers to the behaviour 1.1 creates).
**Commit:** `docs(blog): the reader decides the voice, the template only suggests`

---

# Phase 3 — Guard the decision

- [ ] **3.1** `tests/Architecture/RhythmIsAdvisoryTest.php` — two claims, both
  falsifiable:
  1. **No gate references the rhythm tools.** `Makefile`, `composer.json` and
     `.github/workflows/ci.yml` must not mention `audit-rhythm`,
     `rhythm-diagram` or `tempo-analyzer`. This is the guard that matters: it
     fails the moment someone wires a tool whose exit code used to mean "no"
  2. **The decision is written down.** `docs/blog-voice.md` carries the
     advisory marker and names the three things that stay hard — a rule that
     nobody reads is a rule that does not exist, and a decision that lives
     only in a chat transcript is one
- [ ] **3.2** The test must fail when it should. Prove it by temporarily
  adding a `make rhythm-check` line to the `Makefile`, watching the test go
  red, and reverting — an unwired guard is a belief, not a guard
- [ ] **3.3** Verify: `composer test`, `composer phpstan`, `composer cs-fix-dry`,
  `composer rector-dry`

**Dependencies:** 1.x, 2.x.
**Commit:** `test(blog): keep the rhythm advisory and the decision written`

---

# Phase 4 — Close the loop and record it

- [ ] **4.1** `.ai-factory/ROADMAP.md` — add the milestone from **Roadmap
  Linkage** as an **unchecked** box. This milestone is a stance, not a feature
  to ship, and an unchecked box is the honest state until readers have
  actually asked for something
- [ ] **4.2** `openspec/changes/reader-questions-module.md` — one line in its
  Phase 2 section: the question box is the voice's instrument, so its
  placement follows the readers' reading of a text, not a form placement. A
  cross-link between two plans is cheaper than rediscovering the link later
- [ ] **4.3** Docs checkpoint: re-read `docs/blog-voice.md` and the new section
  as a reader who has never seen the template — if it still sounds like a
  rule, it is written wrong. That reading is the deliverable
- [ ] **4.4** `openspec validate --specs --strict` green

**Dependencies:** 1–3.
**Commit:** `docs(roadmap): the voice follows the readers`

---

## Suggested Commit Sequence

```
chore(blog): make the rhythm audit advisory instead of failing
docs(blog): the reader decides the voice, the template only suggests
test(blog): keep the rhythm advisory and the decision written
docs(roadmap): the voice follows the readers
```

## Final Gate

```sh
composer test && composer phpstan && composer cs-fix-dry && composer rector-dry \
  && make pre-commit \
  && openspec validate --specs --strict
```

## Next Step

```
/aif-implement
```

## What This Plan Deliberately Does Not Solve

**It does not tell us what the readers want.** It only stops us from
pretending we already know, and it removes the machinery that was quietly
answering that question on the template's behalf. The first honest answer
arrives when `Modules\Questions` collects real questions — and until then,
"we are here for them" is a posture, not evidence.