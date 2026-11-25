---
title: "OpenSpec for kids ... part 3: the life cycle of a change ... from idea to archive"
date: "2026-08-09"
version: "1.0.0"
tags: ["openspec", "php", "workflow"]
excerpt: "A change isn't a file, it's a journey. We walk the whole life cycle ... from idea through research, design, implementation and quality gates to the archive ... on a fresh live example of phase 2."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "zivotny-cyklus-change-pre-deti"
published: true
---

> This is part 3 of the **OpenSpec for kids** series. In [part 1](/en/blog/what-is-openspec)
> we explained what a change is and why it isn't written directly into the
> spec. In [part 2](/en/blog/anatomy-of-a-change) we broke down the five
> files of an archived change. Today it all comes alive: we take a
> **fresh change that is going through the whole workflow right now**, and
> show its path from idea to archive ... step by step, in real time.

## A change isn't a file, it's a journey

In part 2 we opened a change like a **photo** ... five files lying in the
archive. But when you look at a photo, you don't see how it was taken. Who
took it? From which side? What happened between the first and second shot?

The life cycle of a change is exactly that "in between". And phase 2 of
this project ... `2026-08-09-author-profile` ... is a perfect example, because
it **is going through this workflow right now**, while I'm writing this
article. The author page `/authors/{id}`, an avatar on the author line and
public serving of uploads. Let's look at its journey.

## Phase 0: Idea ... nothing exists yet

Every change starts as a request in a conversation. No file, no checklist.
In this case it was one sentence:

> "Prepare the design of phase 2: author page `/authors/{id}` + avatar on
> the author line (the `avatar` column in users already exists)."

Sounds simple. But careful ... at this stage we **don't know anything yet**.
Is the avatar column really unused? Can we even reach it? What will it
break? An idea is just fuel ... the journey starts with research.

## Phase 1: Research ... design from facts, not from guesses

Before writing the first line of design.md, the facts were verified directly
in the code. This is the phase you don't see in the photo, but it's the most
important one:

| Verified fact | What it meant for the design |
|---|---|
| `users.avatar VARCHAR(255)` exists in the schema, but **no code reads or writes it** | It can be used without a migration ... the column is already there |
| `LocalStorageProvider` is built and hardened (MIME whitelist, 10 MB, random hex names) ... but **nowhere is it used** | The upload infra exists, it just needs wiring in |
| **Nothing serves `storage/uploads/`** ... both the dev router and production serve only `public/` | Without a new route avatars would never be reachable ... the biggest discovery of the research |
| The router supports `{path*}` catch-all | `GET /storage/uploads/{path*}` can be expressed via `#[Route]` |
| CSP `img-src` already allows `'self' data: blob:` | Self-hosted avatars and the placeholder work without changing CSP |

This is the difference between design from guesses ("it'll probably work
via uploads") and design from facts ("we know nothing serves it, so we add
a route"). All five facts are written in design.md ... that's why someone will
believe them a year from now.

## Phase 2: Five files ... the change is born

After the research, the package
`openspec/changes/2026-08-09-author-profile/` came into existence ...
exactly the five files from part 2, in the same order of questions:

```text
openspec/changes/2026-08-09-author-profile/
├── .openspec.yaml        # WHAT is it? ... "author profile pages + avatars"
├── proposal.md           # WHY? ... incl. Non-goals: no bio, no social
│                         #   links, no public uploads, no cloudinary
├── design.md             # HOW? ... 5 parts: profile, avatar, author page,
│                         #   uploads serving, admin upload
├── tasks.md              # WHAT EXACTLY? ... implementation and test checklist
└── specs/starter-kit/    # HOW DO WE VERIFY? ... 4 new requirements with
    └── spec.md           #   GIVEN/WHEN/THEN
```

Notice that the **spec delta was added right at the beginning**, not at the
end. Before the first line of code existed, it was already written down how
it would be verified. That's the whole point of OpenSpec: first you know
what "done" means, only then you do it.

## Phase 3: Implementation ... tasks.md as a checklist

Then came the code. tasks.md is not a wish list ... it's a **verifiable
checklist** where every item has a precise file path:

```text
## Implementation
- [x] BlogArticlesService (new) ... shared article scan + byAuthorProfileId
- [x] AuthorProfileAction (new) ... GET /authors/{id}
- [x] ServeUploadedFileAction (new) ... GET /storage/uploads/{path*}
- [x] LocalStorageProvider::isAllowed() ... whitelist for the serving route
- [x] UsersAction ... avatar upload + remove in the admin form
- [x] UserRepository/User ... persistence of users.avatar

## Tests
- [x] ServeUploadedFileTest ... traversal, dotfiles, whitelist, MIME
- [x] AuthorProfileTest ... 200 for active, 404 for unknown/inactive
- [x] … and more
```

Ticked = done. But careful ... a checklist confirms that something **was
done**, not that it **works**. That's the next phase's job.

## Phase 4: Quality gates ... the same thing CI runs

Every change in this repo goes through the same gates that run in GitHub
Actions. Nothing special for this change ... **always**:

```text
vendor/bin/phpunit        # 188 → 192 → 194 tests (unit + integration + CSS + architecture)
composer phpstan          # level 9, 0 errors
composer cs-fix-dry       # 0 style violations
composer rector-dry       # 0 proposed changes
pnpm run build            # Vite build + bin/verify-build.php (old browsers)
```

PHPStan level 9 is uncompromising ... when it reports a `mixed` offset or a
method on a nullable object, the change doesn't move forward until it's
fixed. The tests are counted: phase 2 added 6 new tests and together with
revisions there are 194.

## Phase 5: Review ... nobody writes perfect code the first time

Now comes the part you never see in the photo: **critical review**. A
change isn't submitted just because the tests passed. Both the design and
the implementation are checked with the question: "Where can this fail?"
And phase 2 had plenty to show:

**First review pass** found that the design contradicted itself ... the
testing section claimed "`.txt` → 404", but the whitelist contains
`txt`/`md`/`pdf`. Such contradictions are resolved with a decision: we keep
the whole whitelist (txt/md/pdf are served, protected by the global
`nosniff`) and fix the design.

At the same time, **e2e tests through the
Kernel** were added ... because calling the action directly doesn't test URL
decoding (`%2e%2e%2f` → `../`), which happens before routing. Double
encoding (`%252e%252e%252f`) survives the lexical guard ... and only the
realpath containment check stops it. That's verified in code, not guessed.

**Second review pass** found three more things, and each was fixed:

| Finding | Fix |
|---|---|
| The author grid was matched by raw email, the page is addressed by id | `byAuthorProfileId()` ... grid keyed by resolved identity; email stopped leaving the resolver |
| Nonce substitution also ran on binary responses (expensive scan + corruption of txt/md containing the placeholder) | `SecurityHeadersMiddleware` replaces the placeholder only in `text/html` ... files pass through byte-identical |
| The admin avatar preview didn't check whether the file exists | `exists()` fallback to the placeholder ... no broken image |

This is the most important lesson of this part: **a change isn't perfect
when it's written ... it's perfect when it survives review.** Every review
found real problems that tests didn't catch. Tests verify that the code
does what it's supposed to do. Review verifies that what it's supposed to
do is right.

## Phase 6: Archive ... the history of decisions

The last phase is in this case **still ahead of us**. When all gates are
green and the change is approved:

1. The whole package moves to `openspec/changes/archive/2026-08-09-author-profile/`
2. Its four requirements are merged into the canonical spec (`openspec/specs/starter-kit/spec.md`)
3. A commit is created and the change becomes part of the project's history

From that moment the change is **closed** ... nobody will modify it anymore,
but anyone can read why it came into being, how it was designed and what
was decided along the way. Exactly what we did in part 2 with its
predecessor.

## Live status: where is author-profile right now?

And here's the meta-layer. I'm writing this article **in the middle of
phase 5**. The change has finished research, design, implementation and all
gates ... 194 tests, PHPStan clean. It just went through two review passes
and its last fixes (identity-keyed grid, HTML-only nonce, preview fallback)
are being verified. The archive and canonical spec sync are still waiting
for it.

That means when you read this, the change may already be archived ... or
still on its way. Both are correct. **The workflow isn't about things being
done. It's about knowing at every moment which phase they're in and what
they must fulfil to move forward.**

## Why we do it all

The life cycle sounds like a lot of steps for one author page. But every
step has its customer:

| Phase | Protects against |
|---|---|
| Research | Building on a non-existent foundation ("uploads already work" ... they don't) |
| Spec delta | A change that can't be verified ... "done" without a definition |
| Quality gates | Regression ... breaking something that worked |
| Review | A flaw in the design that tests don't see |
| Archive | Forgetting ... why we decided the way we decided |

In [part 4](/en/blog/spec-as-source-of-truth) we'll show how the spec,
code, tests and QA check each other ... and why the spec is the source of
truth, not just another document. See you there!

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
