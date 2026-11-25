---
title: "Rust and Lekalo ... part 8: the authorization contract, who may do what, and the strict review that checks it"
series: "Rust & Lekalo"
series-part: "8"
date: "2026-09-08"
version: "1.0.0"
tags: ["rust", "lekalo", "authorization", "semantic-model", "security"]
excerpt: "Part 7 ended with one green line: strict review validated clean. This article is about the document that made it possible, lekalo/authorization.yaml: actors, role chains, protected effects, the IR-digest pin, adapter mapping evidence, and what strict review actually checks (and honestly doesn't)."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-autorizacia-pre-deti"
published: true
---

Part 7 ended with a one-line victory:

```bash
$ lekalo validate --strict
validated strict profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
```

This is the story of the document that made that line possible, and of
what strict review actually checks, including the honest gaps it cannot
see. Everything below is from this repository, live.

## 1. One document, closed vocabulary

Authorization in Lekalo lives in exactly one project-level file,
`lekalo/authorization.yaml`: never per-module, never inside target code.
Its vocabulary is closed: a fixed set of actors, roles, policies, scope
bindings, field permissions and mapping evidence. No runtime principals,
no tokens, no middleware names, no secrets. It carries semantic ids and
bounded symbolic values only.

The actors are the interesting part:

- `public`: no authentication; only explicitly public policies/effects.
- `identity.user`: an authenticated user is required.
- `internal`: a trusted runtime identity at a declared boundary (our
  `users.find_user_by_email` / `find_user_by_id` lookups).
- (`identity.service` and `system.job` exist in the vocabulary but we
  don't use them, the kit has no services or scheduled jobs yet.)

The role chain mirrors the kit's RBAC with explicit implication:

```yaml
roles:
  - id: users.role.guest
  - id: users.role.member
    implies: ["users.role.guest"]
  - id: users.role.admin
    implies: ["users.role.member"]
```

`admin` implies `member` implies `guest`. Inheritance is declared, never
hidden in target code.

## 2. Policies: who may do what

A policy is the closed combination of an actor, a decision
(`allow`/`deny`), an `applies_to` list of operations, required roles, and
a mandatory `error_ref` pointing at an existing error-contract symbol.
Four policies cover the Users surface:

```yaml
policies:
  - id: users.policy.public_auth_flow
    actor: public
    decision: allow
    error_ref: "users.error.unauthorized"
    applies_to: ["users.register", "users.login",
                 "users.request_password_reset", "users.reset_password"]
  - id: users.policy.member_session
    actor: identity.user
    decision: allow
    error_ref: "users.error.unauthorized"
    applies_to: ["users.logout", "users.current_user"]
    roles: ["users.role.member"]
  - id: users.policy.admin_role_management
    actor: identity.user
    decision: allow
    error_ref: "users.error.forbidden"
    applies_to: ["users.create_role", "users.delete_role",
                 "users.sync_role_permissions", "users.list_roles"]
    roles: ["users.role.admin"]
```

Two more cover the Blog surface (`blog.policy.public_read` for the six
read queries plus view recording, `blog.policy.admin_editing` for CRUD
under `users.role.admin`). Note the `error_ref` split: public surfaces
deny with `users.error.unauthorized`, protected ones with
`users.error.forbidden`: the vocabulary is pinned, even though #62 has
no per-project declaration surface yet (see Honest limits).

## 3. Protected effects: the ten failures that started it

Strict review is deny-by-default. Its core check is simple: *every
protected effect needs at least one allow policy covering its
operation.* Entity create/update/delete, event enqueue, cache writes,
publication, the mutating things, plus query reads with field grants.

When the Blog module joined the model, the first strict run failed with
ten of these:

```json
{
  "id": "authorization.effect-unprotected",
  "code": "LEK-AUTH-009",
  "data": { "effect": "create", "operation": "blog.create_article" }
}
```

The full list was the whole blog surface,`create_article`,
`update_article`, `delete_article`, `record_view`, plus all six read
queries. The module was modeled, but nothing said who was allowed to
touch it. Strict said: not done.

## 4. The `model_ref` pin: a digest game

The second failure was subtler: `LEK-AUTH-010 authorization.mapping-stale`
with `data: {policy: model_ref, state: stale-model}`.

`model_ref` pins the exact IR payload the policies were written against.
The digest is not the lockfile's IR digest and not a hash of the source
files, it is the SHA-256 of the **canonical IR document**:

```bash
$ lekalo load --ir --json | jq -r .ir | sha256sum
```

(The `ir` object inside the `--json` envelope, byte-for-byte as emitted.)
Our first strict run was refused because the pinned digest predated the
Blog module. Recomputing it after the module landed and re-pinning the
document cleared the check. One honest note: getting this right took a
look at the CLI's own fixtures, the golden planner project's
`model_ref` digest matched exactly what `sha256(load --ir --json .ir)`
produced. The formula is reproducible, and now documented in this
repository's `docs/lekalo-model.md`.

## 5. Mapping evidence: proving the adapter saw the contract

Strict review also demands adapter mapping evidence, the `mappings`
section that records which target observed which policy at which
revision:

```yaml
mappings:
  - target: "php-nativa"
    authorization_contract_ref: "dev.lekalo.authorization@1.0.0"
    semantic_policy_ref: "blog.policy.public_read"
    mapping_state: full
    evidence_ref: "lekalo/targets/php-nativa.yaml"
    observed_revision: "sha256:44334cbad…"
```

`mapping_state` is the lever: only `full` passes strict;
`partial`/`unsupported`/`unknown` cannot. An adapter reports
`unsupported` rather than optimistically passing. For us the PHP target
declares full evidence, its config file's digest is the observed
revision, and the gate stays honest about the fact that this is
declaration-level evidence, not generated middleware.

## 6. What strict review is: and is not

Three facts worth stating plainly:

1. **Strict is deny-by-default.** No match, missing policy, unresolved
   reference, all block. The default profile is advisory: it never
   silently weakens strict, it simply does not block.
2. **Declaration, not enforcement.** Lekalo proves the policies are
   well-formed, the operations exist, and the effects are covered. It
   never runs them. The kit's RBAC stays hand-written PHP; the model and
   the runtime agree because both are kept honest, not because one
   generates the other.
3. **The gate makes it stay said.** Once strict is in CI, adding a
   command without an allow policy fails the build. The document stops
   being documentation and becomes a contract.

## Honest limits (as of 0.1.30)

- **Authorization policies are not inspectable.** `lekalo inspect
  users.policy.public_auth_flow` answers `inspect.symbol-unknown`. The
  document is validated, not yet individually projectable.
- **They are not in the dependency graph either.** `graph export` shows
  `authorizes` edges only from *model-level* policies
  (`users.password_min_length`, `blog.cover_owned_by_article`, …), the
  authorization-document policies are a separate contract family and do
  not appear in the #13 graph in 0.1.30.
- **Error contracts (#62)**: `error_ref` values are pinned stable ids,
  but there is no per-project declaration surface for their payloads
  yet.
- **Extended effects (#26)**: event/job/cache semantics are documented,
  not declarable in this version.

These are the model telling the truth about its own boundaries, which
is exactly the point of the tool.

---

Authorization is the one place where "saying it" matters as much as
"doing it". Lekalo can't stop a bad actor at runtime, your guards do
that. What it can do is force you to write down who may do what, refuse
to let the build pass until you have, and then keep that promise
checked on every push. The computer doesn't care what you intended; the
gate cares what you wrote.

→ [Part 7, the model grows](/en/blog/lekalo-model-grows): the blog module joins the model and strict starts passing
→ [Part 6, Lekalo deep dive](/en/blog/lekalo-semantic-model): the live run that started the model journey
→ [The model documentation on GitHub](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): layout, re-lock workflow, authorization section

## Sources

- [Lekalo](https://github.com/ichinya/lekalo): project repository (product 0.1.30)
- [Lekalo, Authorization](https://github.com/ichinya/lekalo/blob/main/docs/authorization.md): actors, scope, policies, strict review, mapping evidence
- `lekalo/authorization.yaml`: this repository's authorization contract
- `docs/lekalo-model.md`: the semantic-model documentation (authorization section)
