---
title: "Rust and Lekalo ... part 7: the model grows — authorization, a second module, and gates on every push"
series: "Rust & Lekalo"
series-part: "7"
date: "2026-09-08"
version: "1.0.0"
tags: ["rust", "lekalo", "semantic-model", "authorization", "architecture"]
excerpt: "Part 6 ended with a live run on 53 definitions. Yesterday the model doubled to 106 nodes, authorization became a declared contract, the blog module joined the model, and the toolchain became a gate that runs on every push — with honest limits, live output included."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-model-rastie-pre-deti"
published: true
---

Part 6 ended with a live run: 53 definitions, 23 rules, zero errors. The
model worked. Yesterday the model grew up — and the toolchain stopped being
optional. One commit at a time, three things happened: the CLI moved to
0.1.30, authorization became a declared contract instead of an assumption,
and the Blog module joined the model. Then the whole thing became a CI gate
that runs on every push.

Every number below is live output from this repository, run today.

## 1. The graph nearly doubled

In part 6 the whole model was one module — Users — and the dependency graph
reported `55 nodes, 106 edges`. Yesterday we added the Blog module. The
graph now reports:

```bash
$ lekalo graph export --json
status: valid
nodes: 106 edges: 189
```

Almost double the nodes, 78 more edges — because the blog surface is
surprisingly large: six read queries (index, by-slug, full-text search,
tags, categories, series), four admin commands with four declared effects
and two events, ten endpoints, four scenarios and a policy
(`blog.cover_owned_by_article`) that keeps covers bound to their article.
All of it declared, none of it executed. Lekalo stays pure declaration —
the PHP runtime remains hand-written.

## 2. Authorization became a contract (#25)

The biggest change wasn't a new module — it was a new document at the
project root: `lekalo/authorization.yaml`. It declares who is allowed to do
what, in the model's own vocabulary:

```yaml
schema_version: "lekalo/authorization/v1.0.0"
roles:
  - id: users.role.guest
  - id: users.role.member
    implies: ["users.role.guest"]
  - id: users.role.admin
    implies: ["users.role.member"]
policies:
  - id: users.policy.public_auth_flow
    actor: public
    decision: allow
    error_ref: "users.error.unauthorized"
    applies_to: ["users.register", "users.login", "users.request_password_reset", "users.reset_password"]
  - id: users.policy.admin_role_management
    actor: identity.user
    decision: allow
    error_ref: "users.error.forbidden"
    applies_to: ["users.create_role", "users.delete_role", "users.sync_role_permissions", "users.list_roles"]
    roles: ["users.role.admin"]
```

Three actors (`public`, `identity.user`, `internal`), a role chain that
mirrors the kit's RBAC (`guest -> member -> admin`, with `admin` implying
`member` implying `guest`), and four policies covering every Users
command and query. Domain rules that are *not* authorization — password
length, unique email, inactive account — deliberately stay model-level
policies, because authorization and domain logic are different contracts.

The honest footnote: authorization here is **declaration and validation,
not enforcement**. Lekalo proves the policies are well-formed and the
symbols they reference exist; it never runs them. The runtime RBAC is still
hand-written PHP — the model and the runtime agree because both are kept
honest, not because one generates the other.

## 3. The blog module: read-side gates in a command-only grammar

Modeling the blog exposed a real limitation of the current schema. The
Model 1.0.0 policy grammar accepts commands only — but two of the blog's
most important gates are read-side: the members-only gate on article
viewing, and translation parity between `sk/` and `en/` directories. The
workaround is honest and documented: those read-side gates live in
`lekalo/modules/blog/policies.yaml` with a comment explaining *why* they
can't be first-class authorization policies yet.

The rest of the module is textbook. The article entity's identity is
`slug + locale` — the same pair that actually addresses an article on
disk (`storage/blog/{sk,en}/{slug}.md`):

```bash
$ lekalo inspect blog.article_by_slug
symbol: blog.article_by_slug
kind: query
module: blog
contract:
  reads: blog.article
  returns: blog.article?
effects: state=available
  - read operation:blog.article_by_slug -> blog.article (canonical)
dependents: state=available
  - exposes blog.endpoint_article (canonical)
  - exposes blog.endpoint_cover (canonical)
```

One query, projected: what it reads, what it returns, who exposes it. And
the scenarios tie it together end-to-end — `read_flow`, `search_flow`,
`members_gate_flow`, `admin_editing_flow` — so the model covers the
lifecycle, not just the symbols.

## 4. Gates: the model became mandatory

The most important commit yesterday wasn't a feature — it was the lock.

```bash
$ lekalo lock --check
lock checked sha256:d6550607… resolver 1.0.0 (adapters 0, generators 0, profiles 0, capabilities 0)
```

The re-lock from 0.1.26 to 0.1.30 changed exactly two fields in the
lockfile — `core.version` and `resolver.request_digest` — because the
contract digests moved upstream. Resolution itself is unchanged. And now
the lock isn't documentation, it's a gate:

- `make lekalo-check` — `lock --check` + `validate` — is wired into
  `pre-commit` and runs on every push in CI.
- The CI job installs the CLI at the pinned `v0.1.30` tag, so the gate
  never silently shifts under you.
- `docs/lekalo-model.md` documents the layout, the re-lock workflow and
  the version policy, so the next model change doesn't have to be reverse-
  engineered.

```bash
$ lekalo validate --project .
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
```

## Honest limits (as of 0.1.30)

Yesterday's work also drew the boundary of what's not there yet — on
purpose:

- **`lekalo inspect` can't project authorization policies yet.** The
  policies validate, but `inspect users.policy.public_auth_flow` answers
  `inspect.symbol-unknown`. The authorization document is validated, not
  yet individually inspectable.
- **`validate --strict` passed by the time you read this.** The first
  run after the blog module refused with `LEK-AUTH-009
  authorization.effect-unprotected` — the blog effects had no
  authorization declarations. The follow-up was small: two new policies
  (`blog.policy.public_read` for the six queries plus `record_view`,
  `blog.policy.admin_editing` for CRUD under `users.role.admin`), a
  refreshed `model_ref` pin, and full mapping evidence for the
  `php-nativa` target. Strict now validates clean — `23 rules, 0
  errors, 0 warnings, 0 info`.
- **Upstream surfaces are still deferred:** error contracts (#62) — the
  `users.error.*` vocabulary is pinned via `error_refs` but has no
  per-project declaration surface yet; extended effects (#26) — the
  intended event semantics (local delivery, unordered, no dedup, standard
  sensitivity, portable) are documented but have no canonical home; strict
  review waits for adapter mapping evidence (#27–#29); the AIFHub provider
  protocol is unpublished.

These aren't failures. They're the model telling the truth about where the
project stands — which is exactly what the tool is for.

---

The deeper shift yesterday wasn't the node count. It was that the model
stopped being something we *look at* and became something that *checks* —
on every push, in CI, before anything ships. A reviewer can miss a file;
a gate cannot. The model is a second pair of eyes that never sleeps, and
yesterday we gave it a badge and a schedule.

→ [Part 6 — Lekalo deep dive](/en/blog/lekalo-semantic-model) — where the series went live on the Users model
→ [Part 5 — semantic application models](/en/blog/semantic-application-models) — why the model layer exists at all
→ [The model documentation on GitHub](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — layout, re-lock workflow, version policy

## Sources

- [Lekalo](https://github.com/ichinya/lekalo) — project repository (product 0.1.30)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — this repository's semantic-model documentation
- `lekalo/authorization.yaml` — authorization contract (#25), role chain and policies
- `lekalo/modules/blog/*.yaml` — the Blog module's semantic model
