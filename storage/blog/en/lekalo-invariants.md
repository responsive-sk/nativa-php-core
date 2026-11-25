---
title: "Rust and Lekalo ... part 9: unbreakable declarations — 0.1.31, comments, and the #63 invariants"
series: "Rust & Lekalo"
series-part: "9"
date: "2026-09-11"
version: "1.0.0"
tags: ["rust", "lekalo", "semantic-model", "invariants", "architecture"]
excerpt: "Part 8 landed the authorization contract. This week brought something new: the first module that admits its imports (comments needs both blog and users), the admin user edit as a command, and issue #63 — three state machines and four invariants declared as an attachment, including two honest gaps the model names itself."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-invarianty-pre-deti"
published: true
---

> This is part 9 of the **Rust and Lekalo** series. In part 7 the model
> grew to 106 nodes and became a gate
> ([part 7](/en/blog/lekalo-model-grows)). Part 8 dissected the
> authorization contract and strict review
> ([part 8](/en/blog/lekalo-authorization-contracts)). Today: version
> 0.1.31, a third module in the graph — comments — and a new kind of
> document that 0.1.31 shipped: state invariants and transitions as a
> model attachment (#63).

The series has revolved around one idea: **declare, don't enforce**.
This part is about the first document that goes one step further — it
doesn't just declare *what exists* and *who may do what*, but *what can
happen and what can never happen*. Every number below is live output
from this repository (version 0.1.31).

## 1. The base: 124 definitions, 128 nodes

When part 6 ran Lekalo live for the first time, the model had 53
definitions and the graph 55 nodes. Part 7 doubled it to 106. Today:

```bash
$ lekalo graph export --json
status: valid
nodes: 128 edges: 226
```

The third module is **comments** — reader comments on articles,
`comments.db`, guest/identity authors, CSRF + per-IP throttled submit
and admin moderation. Alongside it, the Users module gained an admin
edit surface (`users.update_user`) with its effect and a new
`users.flag` scalar. The graph grows linearly, but what's more
interesting is that its *density* grows too: 226 edges over 128 nodes.

## 2. The first module that admits its imports

Until now the modules in the graph were neighbors without relations.
Comments is the first module declaring cross-module imports:

```json
{
  "id": "comments",
  "kind": "module",
  "imports": ["blog", "users"]
}
```

And the graph can prove it immediately:

```text
module:comments --requires--> module:blog
module:comments --requires--> module:users
```

A small but meaningful shift. The kit has known for a long time that a
comment binds to an article (`article_slug: blog.slug`) and that the
author may be a signed-in user (`user_id: users.user?`). The model knew
that only implicitly — through type references. Since 0.1.31 it is a
declared module dependency the validator checks: importing a module
that doesn't exist? Error. Referencing a type from a module you didn't
import? Error. For the first time the model documents not just *what
is* but *what depends on what*.

## 3. users.update_user: the admin edit as a contract

A new command in the Users module:

```json
{
  "id": "users.update_user",
  "kind": "command",
  "input": [
    { "name": "id", "type": { "ref": "users.identifier" }, "required": true },
    { "name": "name", "type": { "ref": "users.display_name" }, "required": true },
    { "name": "email", "type": { "ref": "users.email" }, "required": true },
    { "name": "is_active", "type": { "ref": "users.flag" }, "required": true }
  ],
  "effects": ["users.effect_user_updated"]
}
```

No rocket science — it's the form in `/mark/users`. But a contract is a
contract: two new nodes (`users.update_user`,
`users.effect_user_updated`), a new `users.flag` scalar (a boolean for
checkboxes) and a mandatory allow policy in the authorization document:

```yaml
- id: users.policy.admin_user_management
  actor: identity.user
  decision: allow
  error_ref: "users.error.forbidden"
  applies_to: ["users.update_user"]
  roles: ["users.role.admin"]
```

Strict review swallowed it without a single new failure — the lessons
of part 8 already told us every mutating effect needs an allow policy,
so we wrote it before strict could flag `LEK-AUTH-009`.

## 4. comments.submit: deny policies instead of allow

An interesting contrast: comments are modeled with **deny** policies.
`inspect` shows why:

```bash
$ lekalo inspect comments.submit
symbol: comments.submit
kind: command
module: comments
contract:
  input:
    - article_slug: blog.slug [required]
    - user_id: users.user?
    - guest_name: comments.display_name?
    - guest_email: comments.guest_email?
    - body: comments.body [required]
    - ip_address: comments.ip_address?
policies: state=available
  - comments.article_must_exist deny
  - comments.identity_or_guest_required deny
  - comments.throttled_per_ip deny
```

A public surface with conditions: the article must exist, the comment
must have an identity (signed-in user or guest name/email) and the IP
must pass the throttle. Each condition is its own deny policy — a
readable list of rules that can hold simultaneously.

The authorization (as opposed to these domain rules) stays in
`lekalo/authorization.yaml`: `comments.policy.public_submit` for
`comments.submit` under the `public` actor and
`comments.policy.admin_moderation` for the three admin operations under
`users.role.admin`.

## 5. #63: invariants and transitions as an attachment

The main thing 0.1.31 brought: issue #63 — invariant-transition — as a
core library module. No CLI subcommand reads project attachments yet,
but the contract (`lekalo/invariant-transition/v1.0.0`) is public and
verified, so we could declare our state model as an attachment in
`docs/lekalo/invariant-transition.nativa.json` and verify it against
the real 0.1.31.

Three state spaces:

| State space | States | Initial | cyclePolicy |
|---|---|---|---|
| `nativa.blog.article` | `public` ⇄ `members_only` | `public` | allow |
| `nativa.comments.moderation` | `pending`, `approved`, `rejected` | `pending` | allow |
| `nativa.users.account` | `active` ⇄ `inactive` | `active` | allow |

Four invariants — three `member_of_set` (a state is always exactly one
of the declared set) and one `field_value` (a freshly submitted comment
is always `pending`). Six transitions. Moderation, for example:

```json
{
  "transitionId": "nativa.transition.comment_approve",
  "fromStates": ["pending", "rejected"],
  "toState": "approved",
  "command": "comments.approve",
  "policyRef": "authorization.nativa/blog-admin-editing",
  "errorRefs": ["errors.comments/unknown-comment"]
}
```

Why does `fromStates` include `rejected`? Because this runtime has no
terminal states — an admin can approve a rejected comment and vice
versa. Hence `cyclePolicy: allow`: the machine is openly cyclic, no
fake "final" labels.

And here is the design decision worth spelling out: **the command ref
is a command, not an endpoint**. A transition isn't triggered by an
HTTP path but by a semantic operation — `comments.approve` — which
carries the policyRef and errorRefs. The endpoint
`/mark/comments/{id}/approve` is just one of its possible callers.

## 6. Verification: two full, two honest gaps

The `verificationMappings` section governs how transitions and
invariants relate to reality. Our four mappings split exactly in half —
and that's the most important line in this article:

```json
{
  "mappingId": "nativa.mapping.comment_lifecycle_tested",
  "subject": { "kind": "transition", "id": "nativa.transition.comment_approve" },
  "relation": "tested_by",
  "status": "full",
  "evidence": { "resultStatus": "pass", "executableDigest": "sha256:81309a4c…" }
}
```

`nativa.mapping.comment_lifecycle_tested` and
`nativa.mapping.gate_state_tested` are `full` — real integration tests
in this repository (`CommentsTest`, `BlogMembersTest`) cover those
transitions, with evidence digests.

`nativa.mapping.comment_status_runtime` and
`nativa.mapping.account_state_runtime` are `gap` — we declare the
`comment_status_member` and `account_state_member` invariants, but the
runtime does **not** enforce them; rows in SQLite could theoretically
carry a foreign status if something bypassed the services. The mapping
says so plainly:

```json
{
  "mappingId": "nativa.mapping.account_state_runtime",
  "subject": { "kind": "invariant", "id": "nativa.invariant.account_state_member" },
  "relation": "verified_by",
  "targetRef": { "targetKind": "runtime_method", "targetId": "runtime.nativa/users-admin-toggle" },
  "status": "gap",
  "confidence": "low"
}
```

We could have bent both mappings into `full`. We must not. This kit's
audit log is immutable because BEFORE UPDATE/DELETE triggers on SQLite
forbid rewriting it — but a comment's status and `is_active` are plain
columns that services mutate. Declaring `full` when the runtime
enforces nothing would be a lie inside the contract. A model that lies
about its own boundaries is worse than no model.

## 7. How the attachment was born: the wire contract up close

The attachment lives in `docs/lekalo/`, not `lekalo/` — the loader
enforces a closed `lekalo/` root (`project.yaml`, `modules`,
`targets`, `authorization.yaml`), so attachments have no canonical home
yet. When upstream picks one, we'll move. (The whole procedure is
documented in `docs/lekalo-model.md`.)

Writing it was a lesson in itself:

- **ASCII-strict.** An em-dash in a description? Rejected. An
  apostrophe? Rejected. The contract is a wire format, not prose.
- **camelCase.** `stateSpaceId`, not `state_space_id` — different from
  the YAML documents in `lekalo/`.
- **Every transition needs errorRefs.** A transition without an error
  reference is incomplete — there is always a way to fail, and the
  contract makes you write it down.
- **Self-loops are refused.** A transition from a state to itself
  interacts with `deadPolicy`/`cyclePolicy` and reachability checks —
  you cannot declare one even if you wanted to.
- **policyRef uses a namespaced grammar** (`ns.name/name`), not a
  semantic id — hence `authorization.nativa/blog-admin-editing`.

The digests in the attachment: `modelRef.digest` is the Model 1.0.0
contract digest from the lock; `irRef.digest` is the SHA-256 over the
**compact canonical IR JSON without a trailing newline** — verified
against the real `model_ref` pin review. Both must be refreshed after
every model change.

## Honest limits (as of 0.1.31)

- **#63 has no CLI surface.** `lekalo_core::invariant_transition` is a
  library module; no subcommand reads project attachments. Our
  attachment is verified by a scratch harness over `lekalo-core`, not
  by a gate.
- **Error contracts (#62)** — still no per-project declaration
  surface; the `errorRefs` in transitions and `error_ref` in policies
  are pinned stable ids without payloads.
- **Extended effects (#26)** — event/job/cache semantics still
  undeclarable.
- **Authorization policies still can't be inspected** (part 8) and
  still don't appear in the #13 graph.

---

The deeper point of this part: until now the model said what the system
*does* — commands, queries, endpoints, policies. Invariants say what
the system *means*: a comment is always in one of three states, a fresh
comment is always pending, an account is always active or inactive. And
when a declaration fails — when the runtime cannot guarantee something
— the model records it as a `gap`, not as silence. A computer doesn't
care what you meant; a mapping with `status: gap` is how you admit that
intent and reality are still two different things.

→ [Part 8 — the authorization contract](/en/blog/lekalo-authorization-contracts) — who may do what, and what strict review checks
→ [Part 7 — the model grows](/en/blog/lekalo-model-grows) — 106 nodes, a gate on every push
→ [The model documentation on GitHub](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — including the #63 section and the attachment wire rules

## Sources

- [Lekalo](https://github.com/ichinya/lekalo) — project repository (product 0.1.31)
- [Lekalo issue #63](https://github.com/ichinya/lekalo) — the invariant-transition core module (contract `lekalo/invariant-transition/v1.0.0`)
- `docs/lekalo/invariant-transition.nativa.json` — this repository's attachment (three state spaces, four invariants, six transitions)
- `docs/lekalo-model.md` — semantic model documentation (the comments, 0.1.31 and #63 sections)
- `lekalo/modules/comments/` — the comments module: entities, commands, deny policies
