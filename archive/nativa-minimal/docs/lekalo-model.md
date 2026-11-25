# Lekalo semantic model

This repository is a [Lekalo](https://github.com/ichinya/lekalo) project: the
Users module's semantics (endpoints, commands, queries, effects, events,
policies, scenarios) are declared in a language-neutral semantic model, locked
reproducibly, and validated as a CI gate. Lekalo is pure declaration and
validation — nothing executes; the PHP runtime is and stays hand-written.

## Layout

| Path | Class |
|---|---|
| `lekalo/project.yaml` | Canonical project marker (the `nativa` project) |
| `lekalo/modules/users/*.yaml` | Users module semantic model (bindings, commands, entities, events, policies, queries, scenarios; `commands.yaml` also carries the admin `users.update_user` edit surface) |
| `lekalo/modules/comments/*.yaml` | Comments module semantic model (module, entities, commands, policies, events, scenarios, bindings) — reader comments with the pending → approved/rejected moderation lifecycle, per-IP throttled submit, admin moderation endpoints |
| `lekalo/modules/design/*.yaml` | Design module semantic model — the design layer as a **bounded token-identity set**: `design.identity` (entity, identity `[variant, mode, token]`) referencing `design.role` (the seven role suffixes the CSS audit contracts on), `design.mode` (`dark|light`) and `design.variant` (`starter|moonshine|mark|auth`), plus the `design.resolve_tokens` read query (pins the WCAG AA contract) and the `design.contrast_flow` scenario. Declaration-only, read side — see the design section below. |
| `lekalo/modules/blog/*.yaml` | Blog module semantic model — `blog.article` entity (identity: slug+locale), read queries (index, by-slug, FTS search, tags, categories, series), admin CRUD commands with effects, **publish commands** (`blog.publish_article` single-row toggle, `blog.publish_bulk`, the `blog.effect_article_published`/`blog.effect_article_bulk_published` effects, `blog.article_published`/`blog.article_bulk_published` events, `blog.slug_list` scalar), feed/cover/admin endpoints, read/search/members-gate/admin-editing scenarios. Read-side gates (members-only, translation parity) are documented in `policies.yaml` because the Model 0.2.16 policy grammar accepts commands only. |
| `lekalo/modules/audit/*.yaml` | Audit module semantic model — `audit.entry` (identity: at+action+actor), the internal-actor `audit.append` command with the `audit.effect_append` create effect and the `audit.entry_appended` event, plus the `audit.immutable_trail` scenario; every admin write (users, roles, settings, locales, media, comments, articles, pages, publish toggles, bulk actions) appends here and flushes the page cache — mirrors the append-only `audit.db` trail guarded by `AuditLogTest`. |
| `lekalo/modules/pages/*.yaml` | Pages module semantic model — `pages.article` (a static page document: frontmatter + Markdown body, identity slug+locale, the `published` frontmatter flag defaulting to false), the admin CRUD + publish commands (`pages.create_page`, `pages.update_page`, `pages.delete_page`, `pages.publish_page`, `pages.publish_bulk`) with effects/events mirroring the blog publish chain, the public read queries (`pages.page_index`, `pages.rendered_page`) and the `pages.publication_lifecycle` scenario — mirrors `/mark/pages` CRUD and the public `/{slug}` rendering guarded by `PagesModuleTest`. |
| `lekalo/modules/themes/*.yaml` | Themes module semantic model — `themes.variant` enum mirroring the `ThemeResolver` variant set (ADR-0002: exactly three themes — starter, moonshine, erik), `themes.active_state` (the request-scoped resolved variant — no persisted resolver state), `themes.resolve_active` query (/mark/* path-based → moonshine, public surfaces → the whitelisted `theme` setting with the starter fallback), a **representative endpoint binding** (`GET /blog` → resolve; the root path `/` is equally theme-aware but the Model 0.2.16 endpoint-path grammar has no empty-segment representation for it), a `php-nativa` target-binding and one scenario (read flow). There is no themes write command — selection flows through the settings surface. |
| `lekalo/targets/php-nativa.yaml` | Opaque PHP runtime target configuration |
| `lekalo/authorization.yaml` | Project-level authorization document (#25): actors, roles, policies |
| `lekalo.lock` | Committed resolved lock (`lekalo/lock/v0.3.2`) |
| `.lekalo/cache/` | Runtime cache — regenerated, never committed |

## Toolchain

The CLI is not on crates.io (`publish = false`); install it from the repo at a
pinned tag:

```sh
version=v0.6.5
target=x86_64-unknown-linux-gnu   # aarch64-unknown-linux-gnu, *-apple-darwin and x86_64-pc-windows-msvc are published too
archive="lekalo-${version}-${target}.tar.gz"
curl -fsSL -o "/tmp/${archive}" \
  "https://github.com/ichinya/lekalo/releases/download/${version}/${archive}"
# verify BEFORE unpacking — the checksum is the identity, not the URL
echo "27cdb64ae99d7180530a05fbc722834c129e73faa693ddafc8c08b60e7794694  /tmp/${archive}" | sha256sum -c -
tar xzf "/tmp/${archive}" -C /tmp
mkdir -p ~/.local/bin
install -m 0755 "/tmp/lekalo-${version}-${target}/lekalo" ~/.local/bin/lekalo
```

`cargo install --git https://github.com/ichinya/lekalo --tag v0.6.5 --locked`
still works for a build from source (Rust 1.80+, which is what CI did before
0.6.5); the prebuilt archives, `SHA256SUMS`, SBOM and provenance arrived with
the 0.6.5 release pipeline (#104).

The tag `v0.6.5` ships CLI `0.6.5` — the tag and the `--version` string agree
from this release on. They did **not** for `v0.6.4`, which shipped CLI `0.6.3`.
The lock's `core.version` is always the **CLI** version, never the tag.

**Which binary your shell and `make` actually run — check this first.** `make`
resolves `LEKALO` as `command -v lekalo` first and `$HOME/.cargo/bin/lekalo`
second (`Makefile` line 14), while the install block above writes to
`~/.local/bin`. A stale `~/.cargo/bin/lekalo` therefore wins silently whenever
it comes earlier on `PATH`, and it fails in the least legible way available:
the new subcommands simply do not exist, and `lock --check` exits 1 against a
newer lock. Measured on this machine (2026-10-10):

| Binary | `--version` | `coupling` / `ai-lint` / `architecture-profile` | `lock --check` |
|---|---|---|---|
| `~/.cargo/bin/lekalo` **before** 2026-10-10 | `0.6.3` | **absent** | **exit 1** |
| `~/.cargo/bin/lekalo` **since** 2026-10-10 | `0.6.5` | present | exit 0 |
| pinned archive (`/tmp/lekalo-065/lekalo`) | `0.6.5` | present | exit 0 |

So *"the new surfaces are broken"* and *"you are running the old CLI"* look
identical from the outside. Run `lekalo --version` before believing a red gate
or an `unknown subcommand`, and either upgrade that install or override per
invocation:

```sh
lekalo --version                                  # 0.6.5 is the pinned tag
make lekalo-check LEKALO="$HOME/.local/bin/lekalo"
make lekalo-verify LEKALO="$HOME/.local/bin/lekalo"
```

**The upgrade that closed this, and why the file was replaced rather than the
record.** `~/.cargo/bin` was not on `PATH` at all, so `make` reached that
binary through its own `|| echo` fallback and not through `command -v` — the
same silent resolution, one step further down. The install itself is the
checksum-verified release archive from the section above
(`sha256:27cdb64a…`, verified before unpacking), written to
`~/.cargo/bin/lekalo`. `cargo uninstall lekalo-cli` ran first, because the
existing file was **cargo-managed** at `v0.3.2` — overwriting it would have
left `cargo install --list` claiming one version while the binary reported
another, which is the same class of disagreement between a record and the thing
it records that the digest table further down got wrong. `make lekalo-check`
now exits 0 with no override, which it did not before.

### Upgrading the CLI: delete the lock before you regenerate it

A lock written by an older CLI carries `schema_version: lekalo/lock/v<core>`,
and a newer CLI refuses it as `LEK-LOCK-020
lock.unsupported-schema-version` ("the lock wire carries a future schema
discriminator"). **`lekalo lock` fails on the stale lock too** — it reads
before it writes — so the command you would reach for to upgrade the lock
is the one command that cannot do it:

```sh
mv lekalo.lock /tmp/lekalo.lock.bak   # not `rm`: git has it, but the diff is the record
lekalo lock                            # now regenerates: schema_version + core.version move together
lekalo lock --check
```

The *model* contracts do **not** move with the CLI: model/IR stay `0.2.16`
and the target protocol stays `0.3.2` across the whole 0.4–0.6 range
(`lekalo compatibility` prints the registry). Only `core.version`,
`schema_version` and `resolver.request_digest` change. That is why this
upgrade touched `lekalo.lock` and the pinned tag, and no YAML.

## Everyday commands

```sh
make lekalo-check        # lock --check + validate + validate --strict (the CI gate, part of pre-commit)
lekalo validate --strict # strict review profile (runs inside lekalo-check)
lekalo load --ir         # canonical IR bytes
lekalo inspect <symbol>  # one-symbol projection
lekalo impact <symbol>   # change-radius analysis
```

## Re-lock workflow

Only a changed request or contract makes a lock stale (`lock.stale`) — a newer
CLI release alone never does. After changing the model or upgrading the CLI:

```sh
lekalo update --dry-run          # preview component changes (+N ~N -N)
lekalo lock                      # regenerate — refuses (lock.stale) while a
                                 # stale lock exists; move it away first, then
                                 # re-create, then review the diff
lekalo lock --check              # headless gate — must exit 0
```

`core.version` in the lock records the CLI version that *wrote* the lock; it is
not request-bound and does not need bumping on its own. In practice the 0.1.26
→ 0.1.30 refresh changed exactly `core.version` and `resolver.request_digest`
(the 0.1.31 refresh also carried the comments module + `users.update_user`
model additions and the fresh `model_ref.ir_digest` in `authorization.yaml`).

> **Refreshing `model_ref.ir_digest` after a model change.** Strict review
> refuses a stale pin (`LEK-AUTH-010`, `state: stale-model`). The pin is the
> sha256 over the **compact canonical IR JSON** — the exact bytes of the `ir`
> object inside the `lekalo load --ir --json` envelope, **without the trailing
> newline** the digest pipeline would otherwise swallow. Derive it with:
>
> ```sh
> lekalo load --ir --json | jq -c '.ir' | tr -d '\n' | sha256sum
> ```
>
> Verify the recipe against the known-good pin before trusting a fresh value
> (run it on the committed model, compare with the pin, then change the model).
> The pin itself is guarded by `tests/Integration/ModelRefPinTest.php`, which
> recomputes it from the model — the gate cannot be satisfied by editing the
> pin alone, and a stale pin cannot slip past the test suite either.

## Version policy

| Family | Current | Notes |
|---|---|---|
| Model schema | `0.2.16` | 14 closed definition kinds |
| IR | `0.2.16` | deterministic, target-neutral |
| Authorization | `lekalo/authorization/v0.2.16` | `lekalo/authorization.yaml` |
| Product (CLI) | `0.6.5` | pinned tag in CI is `v0.6.5`, installed as a checksum-verified release archive; tag and `--version` agree from 0.6.5 on |

Model/IR contract digests are bound into the lock and change only with an
upstream contract revision.

### Upgrade record: v0.3.2 → v0.6.4 (2026-10-04)

0.6.4 (released 2026-10-01) is Node/TypeScript bindings (Hono + Drizzle),
local run history and a brownfield pilot. None of that touches this project,
which adopts the model + trace manifests only — which is why the upgrade is a
lock and a pinned tag, and not a migration.

What actually moved:

| What | v0.3.2 | v0.6.4 / CLI 0.6.3 |
|---|---|---|
| Model / IR contract | `0.2.16` | `0.2.16` — unchanged |
| Target protocol | `0.3.2` | `0.3.2` — unchanged |
| Lock wire `schema_version` | `lekalo/lock/v0.2.16` | `lekalo/lock/v0.3.2` |
| Lock `core.version` | `0.3.2` | `0.6.3` (the CLI version, not the tag) |
| Doctor report contract | `doctor.schema.v0.2.16.json` | `lekalo/doctor/v0.3.2` |
| Trace manifest wire | `lekalo/trace-manifest/v0.2.16` | `0.2.16` — all 7 manifests revalidate untouched |

No `lekalo/*.yaml` changed, and `bin/trace/build.py --check` is clean — the
generated manifests did not move.

**`lekalo doctor` reports `blocked`: 15 checks, 12 ok, 1 degraded, 2
blocked.** Both blocked checks (`adapters.inventory`,
`capabilities.profiles`) are **pre-existing, not caused by this upgrade**:
their reason codes (`targets-without-adapters`, `targets-without-profile`)
and both check ids exist in the v0.3.2 source (`crates/lekalo-core/src/doctor/checks.rs`,
`crates/lekalo-cli/tests/doctor.rs`), and `lekalo adapter list` is empty —
this project never installed an adapter package, because it adopts the model
and the trace manifests, not an adapter. `doctor` is informational, never a
gate. Do not confuse the *semantic* target declaration (`php-nativa` declares
adapter mapping evidence, which is why `validate --strict` passes) with the
*adapter package* inventory doctor asks about. The one degraded check is
`integrations.hlv` (`not-supplied`), also optional.

### Upgrade record: v0.6.4 → v0.6.5 (2026-10-10)

0.6.5 (released 2026-10-05, milestone M7) adds the capabilities this project
can actually use — `trace assess`, `coupling`, `ai-lint`, `waivers`,
`architecture-profile`, `metrics` and an OpenSpec-native `requirements`
surface — plus checksummed platform archives (#104) and a grown diagnostic
registry. None of it moves the model, so the upgrade is a lock, a pinned tag
and a CI install method, not a migration.

What actually moved (the lock diff, and nothing else):

| What | v0.6.4 / CLI 0.6.3 | v0.6.5 |
|---|---|---|
| Model / IR contract | `0.2.16` | `0.2.16` — unchanged |
| Target protocol | `0.3.2` | `0.3.2` — unchanged |
| Lock wire `schema_version` | `lekalo/lock/v0.3.2` | `lekalo/lock/v0.3.2` — unchanged |
| Lock `core.version` | `0.6.3` (the CLI version) | `0.6.5` |
| Registry contract | `0.2.16` | `0.6.4`, new digest (514 → 522 diagnostic entries) |
| `resolver.request_digest` | `sha256:8f194d07…` | `sha256:08ee1815…` |
| Trace manifest wire | `lekalo/trace-manifest/v0.2.16` | `0.2.16` — all 8 manifests revalidate untouched |
| Validation profile | `v0.6.4` | `v0.6.4` — 23 rules, 0/0/0 in default **and** strict |

Verified 2026-10-10 with CLI `0.6.5` (release archive, checksum
`sha256:27cdb64a…`): `make lekalo-check` (lock --check + validate + strict) and
`make lekalo-trace` (all eight manifests + generator drift) both exit 0, with
`uncovered sinks 0` in every manifest — the generated manifests did not move.
CI installs that same archive and verifies the same checksum.

`lekalo doctor` reports the SAME shape as at the previous upgrade — **blocked:
15 checks, 12 ok, 1 degraded, 2 blocked** — so neither the new commands nor the
new registry entries changed this project's readiness picture. The two blocked
checks are still `adapters.inventory` and `capabilities.profiles`, blocked
because this project installs no adapter package; the degraded one is still the
optional `integrations.hlv`.

**A producer-only move is repaired with `update`, not by deleting the lock.**
`lock --check` answered `LEK-LOCK-019 lock.stale` — the CLI moved, the wire did
not. `lekalo update --dry-run` printed a deterministic plan (`+0 ~0 -0`) with a
plan id, and `lekalo update --apply sha256:c0b3eae2…` wrote exactly that plan.
The delete-then-regenerate recipe above is for a *schema* move
(`LEK-LOCK-020 lock.unsupported-schema-version`); for this narrower class,
previewing and applying one exact plan is reviewable and leaves the old lock
readable for the diff.

### What the new surfaces can do for this project today (verified 2026-10-10)

"The tool has the feature" and "the feature runs on our model" are different
claims, so each surface below was actually run:

| Surface | Result |
|---|---|
| `verify` | **Works.** `make lekalo-verify` — read-only pipeline, exit 0, verdict **`degraded`** with command result `valid` and coverage `incomplete` (no adapter package installed, `bindings.registry` warns `observed.index-missing`); `--report-format md\|json\|junit\|sarif` writes the CI report (to a path that does **not** exist yet — an existing one answers `LEK-CI-001 ci.report-write-failed`, exit 4, which is why `make lekalo-verify` deletes its own report first; measured 2026-10-10). Now an informational CI step feeding the job summary. `--ci-policy strict` marks the optional absence `blocked (exit 4)` **in the report** while the process still exits 0 (measured 2026-10-10, CLI v0.6.5, envelope `verdict: ready`, all three policy levels exiting 0), so it is not promotable to a gate on exit status. **`degraded` is the measured verdict, not `ready`** — the heading of the report says so (`## Lekalo verify — degraded (exit 0)`) and this line said `ready` until 2026-10-10, which is the failure mode the surrounding rule warns about in the other direction: a claim about a tool is worth exactly as much as the run behind it, and this one had none. |
| `trace assess` | **Blocked** — `LEK-TRACE-008 trace.bridge-input-invalid`. It needs a *neutral validation evidence* document, and nothing here produces one; a `grep` for a candidate finds nothing, and **the `verify` CI report is rejected by the same check**. That is the concrete shape of the native-gate receipt gap (#89): narrower and better specified than the prose that described it. |
| `coupling --module <m>` | **Works, and is wired** — `lekalo coupling --module users`: `117 subjects` in the module, `94 public contracts`, `0 internal symbols`, advisory, per-subject `fan-in` / `fan-out` / `public radius`. Two advisory codes, both real warnings rather than a clean bill: `LEK-COUPLING-012 coupling.evidence-incomplete` (the report is honest about the evidence it lacks — the same shape as `impact`'s `impact.evidence-unknown`) and `LEK-COUPLING-006 coupling.shared-mutable-state` (multiple declared operations mutating one resource). `--symbol` and `--changed-input` are the other selectors. Reported for `users` + `blog` into the CI job summary by `make lekalo-reports`. |
| `coupling --all` | **Blocked** — `LEK-COUPLING-001 coupling.input-invalid`. The whole-project selector still needs a coupling profile/baseline input; a bare `--all` reports `status: invalid` (and, with `--json`, does so on exit 0 — read the envelope, not the exit code). |
| `architecture-profile catalog` / `assess` | **Works, and is wired** — `catalog` emits the versioned rule catalog (`lekalo/architecture-rule-catalog/v0.6.5`, recipe `architecture-core/1`); `assess --module users` emits `lekalo/architecture-profile-report/v0.6.5` for one module, source read-only and advisory without `--check`, with **no** diagnostics and status `valid`. Of the 15 catalog rules, **1** has current evidence (`architecture.stable-semantic-ids`, the required one, observed at 56 declared ids for `users` and 57 for `blog`) and **14** are `disabled`/`unsupported` — the adapter gap, not a finding. `diff` compares two admitted profiles independently of the model. Reported alongside coupling by `make lekalo-reports`. |
| `ai-lint` | **Runs, but proves nothing yet** — `ai-lint --module users` exits 0 with `0 raw finding(s), 0 active, 0 waived` **and** `LEK-AILINT-003 ai-lint.coverage-incomplete` ("collect current evidence before claiming a clean result"). The zero is a placeholder, not a clean bill of health; treating it as green is the failure mode this project warns about elsewhere. |
| `waivers list` / `add` | **Needs an entry** — `waivers list` denies with `LEK-AILINT-004 ai-lint.waiver-invalid` (the waiver set is validated against the ai-lint family), so there is nothing to list until one is authored. `add` previews a scoped candidate and `--apply` writes exactly that plan. |
| `requirements validate` | **Not applicable yet** — there is no requirements attachment in `lekalo/` (only `project.yaml`, `authorization.yaml`, `modules/`, `targets/`). Adopting it means authoring a new artifact, and `requirements trace` is a candidate replacement for part of `bin/trace/build.py`. |
| `history` / `metrics` | **Needs the history home** — `history init` creates `.lekalo/history/` (git-ignored, local-only), `history record --input -` ingests one bounded observation, and `metrics` aggregates from there. This project retains no run history today, which is the only reason `metrics` has nothing to export. |

Copy-paste starting points for the surfaces that run clean today (`coupling`,
`architecture-profile`):

```sh
lekalo coupling --module users                     # blast radius of a module, advisory
lekalo architecture-profile catalog                # which architecture rules exist
lekalo architecture-profile assess --module users  # the per-module report
make lekalo-reports                                # both, for users + blog, as markdown
make lekalo-reports LEKALO_MODULES=users,blog,forms # widen the set
```

**`make lekalo-reports` is the wired form of those two surfaces.**
`bin/ops/lekalo-reports.php` runs each command per module, reads the JSON
envelope and renders one markdown table per surface — one row per module, with
the advisory codes and their messages stated underneath rather than filtered,
because a green row that hides `coupling.shared-mutable-state` teaches the
reader that a green row means a clean report. The CI `lekalo` job appends the
output to `$GITHUB_STEP_SUMMARY`, and it passes `LEKALO="$HOME/.local/bin/lekalo"`
explicitly rather than going through `command -v`: an empty `command -v` would
override the Makefile default with nothing and fail every row. Two modules is
the default and the floor — a single module says nothing about whether the
shape holds where the model is different, and both surfaces were adopted on the
strength of `--module users` alone.

The rule the table exists to keep: adopt a surface when a command has been run
against this model and exited 0, not when a release note names it.

## What is modeled (users module)

- **Public flow:** register, login, request/reset password — `public` actor.
- **Session:** logout, current user — `identity.user` (`users.role.member`).
- **RBAC management:** create/delete role, sync role permissions, list roles —
  `users.role.admin` (the kit's admin super-user bypass).
- **Internal lookups:** find user by email/id — `internal` runtime boundary.
- Domain rules that are *not* authorization (password length, unique email,
  inactive account) stay model-level `policy` definitions in
  `lekalo/modules/users/policies.yaml`.

The closed error vocabulary referenced by authorization policies
(`users.error.unauthorized`, `users.error.forbidden`) is pinned as stable
semantic ids.

**Blog surface:** all six read queries plus `blog.record_view` are
authorized for the `public` actor (`blog.policy.public_read` with article
field-read grants); article CRUD is authorized for `users.role.admin`
(`blog.policy.admin_editing`). The `model_ref` pin tracks the current IR
digest, and the `php-nativa` target declares full adapter mapping
evidence, so `validate --strict` passes.

**Themes surface (ADR-0002):** `themes.resolve_active` is authorized for
the `public` actor (`themes.policy.public_read`). The enum is a
**hand-written mirror** of the `ThemeResolver` variant set
(`PUBLIC_WHITELIST` + `ADMIN_THEME`) — the gate cannot read PHP, so
`tests/Integration/ThemesModelParityTest.php` enforces the set-equality in
CI instead (the "gate cannot read, test does" pattern). Adding a new
frontend theme means adding the variant to the enum and the resolver
whitelist in the same commit — the parity test fails otherwise. The
admin-switch command (`themes.set_site_theme`, its effect/event and the
`themes.policy.admin_editing` policy) stays removed — the public theme
selection flows through the existing `/mark/settings` surface.

**Themes bindings (representative subset):** one endpoint is bound —
`GET /blog` → `themes.resolve_active` (the representative theme-aware read
surface). The `POST /mark/settings/site` → `themes.set_site_theme` themes
write path stays removed (ADR-0001/0002) — the public theme selection is
settings-surface work, not a themes-module command. Binding every
theme-aware surface would make every future route
change a mandatory model edit for zero additional contract value — the theme
is a *dimension* of those endpoints. Two things keep the bindings honest:

- **Route parity** — `tests/Integration/ThemesBindingParityTest.php` asserts
  method+path set-equality between every themes endpoint binding and the
  runtime `#[Route]` declarations (regex over the action classes). A route rename without
  the model — or vice versa — fails CI.
- **Grammar limit (Model 0.2.16)** — endpoint paths are
  `^(/[a-z0-9:_{}-]+)+$` with **non-empty segments**: the root path `/` is
  not expressible, so the equally theme-aware homepage is represented by the
  `/blog` binding instead. Revisit when upstream widens the grammar.

`themes.read_flow` covers the resolve query (the admin-switch flow was
removed with its command by ADR-0001) — the model's first scenario beyond
blog.

**Design surface (read-only, role-not-value):** the design layer is modeled
as a bounded **token-identity set**, not as token values — Lekalo declares
*which* identities exist and under what role contract; the byte-level
colors stay in CSS. `design.identity` (identity `[variant, mode, token]`)
references three hand-written enums:

- `design.role` — exactly the role suffixes
  `tests/Css/BuildCssAuditTest.php` enforces with real WCAG ratios
  (`accent_strong`, `accent_soft`, `accent_hover`, `primary_ink`,
  `error_ink`, `surface`, `tag_bg`; enum values are snake_case per the
  model value grammar). The decorative `--mag-accent` / `--min-accent`
  (~3:1, icon/border-only by contract) is deliberately **not** a role —
  its contract is exclusion from text roles, not a contrast floor.
- `design.mode` — exactly `dark|light` (the two audited `:root` blocks;
  the `prefers-color-scheme` fallback re-uses the light values).
- `design.variant` — the audited surfaces: the shipped themes' bundle
  families (`--st-*` starter, `--m-*` moonshine, `--mag-*` magazine,
  `--min-*` minimal) plus the two inline (non-bundle) sources `mark`
  (`AdminLayout.php` inline CSS) and `auth` (`AuthTemplate.php`).
  Bundle membership is independent of markup: magazine/minimal have no
  `src/Themes/` markup left, but their built CSS is still audited, so their
  token families are still model surfaces.

The `design.resolve_tokens` query (reads the identities, returns the list)
pins the WCAG AA (≥ 4.5:1) contract in both modes and names the
enforcement points; the `design.contrast_flow` scenario covers the mode
flip. **Former themes bridge (removed by ADR-0001):** the module previously
declared `imports: [themes]` in `module.yaml` and carried the
`design.theme_change_reaudit_flow` bridge scenario covering the persisted
theme-change symbol set (`themes.set_site_theme`,
`themes.effect_site_theme_set`, `themes.site_theme_set`). ADR-0001 removed
the persisted theme switch together with its command/effect/event, so the
bridge lost its anchored symbols and was deleted — the design module no
longer imports themes, and `DesignModelParityTest`
(`testBridgeScenarioStaysRemoved`) keeps the removal honest. (Historical
evidence from the bridge era, 2026-09-20 commit `ed55f01`: the bridge moved
the `lekalo impact themes.set_site_theme` radius from 2 to 3 direct
dependencies — model edges made change-radius analysis meaningful.)

**CI impact report (adopted 2026-09-20 — report, not a hard gate):** the
Lekalo CI job runs `lekalo impact --changed --base
origin/$GITHUB_BASE_REF --worktree` as an **informational** step when a
PR touches `lekalo/**`, after fetching the base ref. Measured reasoning:
the default profile only warns (`impact.evidence-unknown` — artifacts,
tests and evidence sources are not wired yet) and never fails, and
`--profile strict` impact is unusable as a gate today
(`LEK-IMPACT-010` — blocked on required unknown evidence), so a hard gate
would either be a no-op or a guaranteed red. It adds value anyway: the
radius lands in every model-touching PR log. Two loader facts constrain
the wiring: an empty changed set is itself an error
(`LEK-IMPACT-002`, exit 1), so the step must first test whether the diff
is empty and skip explicitly; and `--worktree` analyzes the checkout's
working tree against the committed base, which is exactly the PR
scenario. Locally the same report is `make impact-report BASE=<ref>`
(default `HEAD`). Revisit hard-gating when upstream wires the evidence
sources (#26/#22) so strict impact can pass. Two authorization policies mirror the themes pair:
`design.policy.public_read` (actor `public`) and
`design.policy.admin_editing` (actor `identity.user`, role admin, on the
same read surface — the model's closed schema rejects an empty
`applies_to`, and no design write command exists to model instead; the
admin reads the surface too, so the policy is honest, not a placeholder
for an invented command).

Deliberately **not** modeled: a write side (no per-token/per-set runtime
switcher exists — the theme dimension resolved by `themes.resolve_active`
is orthogonal; token values are build-time CSS, never runtime state),
per-token definitions (50+ entries would bloat the Model 0.2.16 grammar —
roles are the bounded unit the audit contracts on) and endpoint bindings
(design is a dimension of rendered endpoints, not new routes).The parity guard is `tests/Integration/DesignModelParityTest.php`
(5 tests): the variant enum MUST cover every surface the audit touches
(parsed from the test source), the role enum MUST be set-equal with the
audited role list, the mode enum MUST be exactly `dark|light`, and the
model contract shape (identity field refs, query reads/returns) MUST
hold. A new audited token family or role without a model entry — or vice versa — fails CI.

**Block vocabulary is deliberately not modeled** (the "postupne"
decision): structural blocks (nav, footer, grid, section, form, widget,
hero, card, toc) live only as a doc artifact —
`docs/design-blocks.md` holds the kind × theme × variant matrix with its
own architecture guard (`DesignBlockVocabularyTest`). Promote the
vocabulary into `design.block` entities only after the promotion
criteria recorded in that doc hold.

**Design surface (read-only, role-not-value):** the design layer is modeled
as a bounded **token-identity set**, not as token values — Lekalo declares
*which* identities exist and under what role contract; the byte-level
colors stay in CSS. `design.identity` (identity `[variant, mode, token]`)
references three hand-written enums:

- `design.role` — exactly the role suffixes
  `tests/Css/BuildCssAuditTest.php` enforces with real WCAG ratios
  (`accent_strong`, `accent_soft`, `accent_hover`, `primary_ink`,
  `error_ink`, `surface`, `tag_bg`; enum values are snake_case per the
  model value grammar). The decorative `--mag-accent` / `--min-accent`
  (~3:1, icon/border-only by contract) is deliberately **not** a role —
  its contract is exclusion from text roles, not a contrast floor.
- `design.mode` — exactly `dark|light` (the two audited `:root` blocks;
  the `prefers-color-scheme` fallback re-uses the light values).
- `design.variant` — the audited surfaces: the four public themes' bundle
  families (`--st-*`, `--mag-*`, `--min-*`, `--m-*`) plus the two inline
  (non-bundle) sources `mark` (`AdminLayout.php`) and `auth`
  (`AuthTemplate.php`).

The `design.resolve_tokens` query (reads the identities, returns the list)
pins the WCAG AA (≥ 4.5:1) contract in both modes and names the
enforcement points; the `design.contrast_flow` scenario covers the
mode flip. Two authorization policies mirror the themes pair:
`design.policy.public_read` (actor `public`) and
`design.policy.admin_editing` (actor `identity.user`, role admin, on the
same read surface — the model's closed schema rejects an empty
`applies_to`, and no design write command exists to model instead; the
admin *reads* the surface too, so the policy is honest, not a placeholder
for an invented command).

Deliberately **not** modeled: a write side (no per-token/per-set runtime
switcher exists — the theme dimension resolved by `themes.resolve_active`
is orthogonal; token values are build-time CSS, never runtime state),
per-token definitions (50+ entries would bloat the Model 0.2.16 grammar —
roles are the bounded unit the audit contracts on) and endpoint bindings
(design is a dimension of rendered endpoints, not new routes).

The parity guard is
`tests/Integration/DesignModelParityTest.php` (5 tests): the variant enum
MUST cover every surface the audit touches (parsed from the test source),
the role enum MUST be set-equal with the audited role list, the mode enum
MUST be exactly `dark|light`, and the model contract shape (identity
field refs, query reads/returns) MUST hold. A new audited token family or
role without a model entry — or vice versa — fails CI.

## Native gates (#48) and the theme workspace guard (product 0.3.2, M3)

M3 shipped the native gate seam: read-only pnpm/npm workspace detection, an
immutable execution plan over confirmed literal gates, and a plan digest.
Upstream identities: `lekalo/native-gate-{policy,plan,run,view}.schema.v0.3.2.json`,
diagnostic family `native-gate.*` (`LEK-NGT-001..014`). Pure declaration —
nothing is executed from this project.

**What works today (verified 2026-09-20, CLI v0.3.2, read-only):**

- `lekalo init --adopt --dry-run --json` audits the frontend layout without
  touching the tree. On this repository it reports `Frontend/packages`
  as a **single-package workspace root** (`workspaceRoots[0]`: ecosystem
  `node`, members `["."]`; re-run 2026-10-10 on CLI v0.6.5 reads members
  `["*"]`, which is what the workspace file actually declares — same single
  root, same `high` confidence), `monorepoTools: []`, pnpm as
  the package manager (`pnpm-lock.yaml` + `pnpm-workspace.yaml`), and two
  gate proposals sourced from the CI workflow evidence: `build → npm run
  build` (evidence `Frontend/packages/package.json#scripts.build`)
  and `test → composer test`. Because the project already carries
  `lekalo/project.yaml`, the dry-run reports the skeleton write as
  `disposition: conflict` — expected and harmless; the detection payload
  stands.
- `lekalo native run <PLAN>` validates a plan document (path or stdin; the
  document must carry trusted-fixture custody) and answers with the
  immutable plan + `plan_digest` (sha256 over canonical compact JSON). It
  never launches a command.

**What does not work — the M3 execution boundary.** The production binary
never launches a gate command: trusted synthetic plans answer
`unsupported + fixture-runner-not-shipped`, private/untrusted repositories
answer `blocked + confinement-required` (plan-only until upstream #89). The
only real runner lives in lekalo's own test harness; no flag, env var or
marker file turns execution on. Until then the enforced build gate stays
where it is: CI's `pnpm install --frozen-lockfile` + `pnpm run build` over
`Frontend/packages` (all five theme bundles), audited by `BuildCssAuditTest` against
`public/dist/starter/`.

**Why the detection result matters — the pnpm workspace guard.** pnpm
resolves the workspace root by walking **up** from the package directory and
adopts the first `pnpm-workspace.yaml` it finds — a stray one outside the
repo (e.g. in `$HOME`, with its own lockfile) hijacks every install and
build. The workspace root here is `Frontend/packages`, and it declares its members for
real (`packages: ["*"]` — the theme packages are direct children); it
deliberately does **not** carry the old per-package walk-up guard
(`ignoreWorkspace: true` / `.npmrc ignore-workspace=true`), because pnpm reads
that as "ignore this workspace file" and `pnpm -r` would then see a single root
package and build nothing. What the detection asserts is therefore load-bearing
rather than cosmetic: it is an independent second opinion that the shape is
still the intended one — new membership patterns in the workspace manifest, a
monorepo tool, or a second workspace root would all surface in `workspaceRoots`
/ `monorepoTools`.

**The audit is a gate, not an occasional look (wired 2026-10-10, CLI v0.6.5).**
`make workspace-audit` pipes the dry-run into a `jq -e` over `.detection` — the
only workspace root is `Frontend/packages`, `monorepoTools` is empty, and pnpm
is among the package managers — and exits 1 on drift. It is a prerequisite of
`make pre-commit` and a step in the CI `lekalo` job, and
`tests/Architecture/MakeTargetsWiredTest.php` fails when a target like it is
reachable from neither. Measured on this model: exit 0, with the same predicate
rejecting a stray workspace root, a relocated root, a monorepo tool, a
`packageManagers` list without pnpm, `{}` and a payload carrying no `detection`
— it cannot be passed vacuously. Inspect a failure with:

```sh
lekalo init --adopt --dry-run --json \
  | jq '.detection | {workspaceRoots, monorepoTools, packageManagers, gates}'
```

**Connection to the design surface.** `design.resolve_tokens` pins the WCAG
AA contract that `BuildCssAuditTest` enforces against the real built bundle —
a bundle that only exists after the workspace-healthy pnpm build above.
Native gate receipts (confirmed literal commands, affected closure, plan
digest) are exactly the build-evidence shape the impact report is missing
today (`impact.evidence-unknown` — the reason the CI impact step is
informational, not a gate). Once upstream wires the evidence sources
(#26/#22) and ships confinement (#89), a native gate over the starter
workspace is the natural audited build step behind the design token
contract. Not modeled in `lekalo/`: the native gate is a target-protocol
operation (lekalo ↔ adapter), not a definition kind, so the design module
gains no new definitions from it.

## TypeScript scan (#44, adopted as evidence in 0.3.2)

Product 0.3.0/0.3.1 shipped the `node-typescript` adapter — a self-contained
bundle (`adapters/node-typescript/adapter.mjs`, vendored `typescript@5.9.3`)
that scans a TypeScript codebase and records typed scan-entry bindings. The
starter package (`Frontend/packages/starter`) is scanned with it — evidence
home: `docs/lekalo/scan/starter-ts.scan.md` (per-entry outcomes, the
disposable lowercase-mirror method, the invocation contract). Headline
results: magazine 3 / minimal 2 / moonshine 0 entries, `main` an honest
`partial` (one residual `any-surface` on `containerQueryManager`).

**Bindings are deliberately none.** The scanner scopes proposals by the
package name (`@nativa/starter` → scope `nativa_starter.*`), which has no
counterpart module in the model — a merged scan would refuse with
`observed.unknown-module` (LEK-OBS-003) and the forbidden surface of the
adoption change excludes `lekalo/modules/**`. The observed index stays empty
(`bindings list/audit` report `observed.index-missing`, LEK-OBS-004 — the
documented honest state); `lekalo update --dry-run` reports `+0 ~0 -0` (the
lock pins contracts, not scan evidence). If the model ever grows a frontend
module, re-run the per-entry scans through the CLI merge path and confirm
bindings selectively.

**Cadence: manual**, on TS surface changes (new entry, new exported helper
family): re-clone the adapter at the pinned tag into
`storage/cache/lekalo/` (gitignored), rebuild the mirror, re-run the
per-entry profiles, refresh the evidence doc. Never a CI step — cloning a
13 MB bundle per run for evidence that moves only with TS edits is not worth
the minutes (revisit when bindings stabilize).

## Trace manifests (#22, adopted as documents in 0.3.2)

Product 0.3.2 ships the **neutral trace manifest** — the closed
`lekalo/trace-manifest/v0.2.16` wire (identity
`dev.lekalo.trace-manifest@0.2.16`) expressing the chain
requirement → semantic symbol → artifact → scenario → native test → gate,
plus `lekalo trace validate|export|query`. The project keeps **eight**
manifests under `docs/lekalo/trace/` (the loader enforces a
closed `lekalo/` root, so `docs/lekalo/` is the attachment home, same as the
invariant-transition document), and **none of them is hand-written**:
`python3 bin/trace/build.py` (alias `make trace-build`) regenerates every one
from an authored chain mapping (`bin/trace/mappings/<name>.json`) plus the five
canonical sources — the OpenSpec specs, `lekalo/modules/`, the artifact files,
the PHPUnit files and the Makefile. `bin/trace/build.py --check`, wired into
`make lekalo-trace` and into the CI `lekalo` job, regenerates them against their
own recorded revision and fails on any drift, so a hand edit, a renamed symbol
or a deleted artifact is a red build rather than a stale document:

| Manifest | Chain (abbreviated) | Nodes / relations / gaps |
|---|---|---|
| `users.trace.json` | spec requirements (register/login/RBAC/password reset/admin auth) → `users.*` commands + policies → endpoint/action artifacts → `users.register_login_flow` / `users.password_reset_flow` → `UsersModuleTest` + `lekalo validate` | 28 / 34 / 6 |
| `blog.trace.json` | FTS search, feeds, covers, comments + moderation + publish-switch requirements → `blog.*`/`comments.*` symbols (incl. `blog.publish_article`, `blog.publish_bulk` + effects) → service/action artifacts → `blog.search_flow`, `blog.read_flow`, `blog.admin_editing_flow`, `blog.members_gate_flow` → `BlogSearchTest`, `BlogFeedTest`, `BlogCoverTest`, `CoverDeterminismTest`, `CommentsTest`, `BulkActionsTest` | 47 / 66 / 7 |
| `themes-design.trace.json` | themes/design requirements → `themes.resolve_active`, `design.resolve_tokens` → resolver/styles artifacts → `themes.read_flow`, `design.contrast_flow` → `ThemesModelParityTest`, `DesignModelParityTest`, `BuildCssAuditTest` | 14 / 14 / 3 |
| `audit.trace.json` | immutable audit log requirement → `audit.append` + effect → `AuditService`/`AuditEntry`/repository/migrator artifacts → `audit.immutable_trail` → `AuditLogTest` | 13 / 12 / 2 |
| `pages.trace.json` | publish/draft-switch requirement → `pages.*` CRUD + publish symbols → `PageCrudService`/`PagePublishToggleAction` artifacts → `pages.admin_crud_flow`, `pages.public_read_flow` → `PagesModuleTest`, `BulkActionsTest` | 21 / 27 / 3 |
| `comments.trace.json` | comments requirements (public submit, admin moderation UI) → `comments.*` commands/effects/events/policies symbols → `CommentsService`/submit+admin actions/repository artifacts → `comments.moderation_lifecycle`, `comments.throttled_submit_denied` → `CommentsTest`, `CommentsMetricTest` | 33 / 55 / 2 |
| `forms.trace.json` | forms requirements (definitions, embedding, submission, poll/quiz answers, delivery, spam posture) → `forms.*` commands/effects/events/policies symbols → `FormSubmissionService`/editor, inbox, CSV and block-renderer artifacts → `forms.contact_submission_loop`, `forms.poll_result_loop`, `forms.spam_and_gatekeeping` → `FormsIntegrationTest`, `FormsSubmissionServiceTest`, `FormsRepositoryTest`, `FormSubmissionCsvTest`, `FormShortcodeBlockTest`, `FormsContractTest`, `FrontendFormParityTest` | 89 / 173 / 2 |
| `questions.trace.json` | reader-question requirements (ask with an attempted answer, subject as the discriminated pair re-resolved against published content, moderation inbox with a recorded decision, approved-only rendering, queue depth on the nav item) → `questions.*` commands/effects/queries/policies symbols (`questions.submit`, `questions.moderation_inbox`, `questions.subject_must_be_published`, `questions.throttled_per_ip`, …) → `Question` entity, repository, migrator, `QuestionsServices`, `SubjectResolver`, submit + admin actions and `QuestionBlockRenderer` artifacts → `questions.moderation_lifecycle`, `questions.moderation_queue_is_badged`, `questions.throttled_submit_denied`, `questions.unpublished_subject_denied` → `QuestionsTest`, `NavBadgeTest`, `AdminMenuCoverageTest` | 52 / 95 / 2 |

**Completeness stays `partial` with explicit typed gaps** — the honest state
while native-gate receipts do not exist (upstream #89 execution confinement).
Every manifest carries the `missing-gate` gap (expected
`hlv.native.gate.*`) plus an `unsupported-provider` gap recording the receipt
blocker itself; users additionally declares `missing-scenario`
(`users.role-management-flow` — the model has no RBAC scenario yet, so the
RBAC chain closes via direct `verifies` test→symbol edges instead); blog
declares `missing-native-test` for the members-gate scenario. A gap is never
confirmed and never satisfies a gate.

**Conventions (pinned by `tests/Integration/TraceManifestTest.php`, the
"gate cannot read, test does" guard):**

- `sourceRevision` is the HEAD commit at authoring time; every confirmed
  relation's `sourceRevision` must equal the manifest revision (the wire's
  confirmed policy) — refresh all of them together when the chain moves.
- `modelRef`/`irRef` digests are the Model/IR contract digests from
  `lekalo.lock` (`contracts.model.digest`, `contracts.ir.digest`) — they move
  only with an upstream contract revision, not with model content.
- Symbol nodes use the **bare** semantic ids the CLI itself uses
  (`users.register`, not `command:users.register`) — `lekalo inspect` and the
  graph index are the authority.
- Requirement nodes carry verbatim `externalRefs`
  (`openspec:` + the mechanical kebab anchor of the canonical spec's
  `### Requirement:` heading); the guard test re-derives every anchor from
  `openspec/specs/starter-kit/spec.md` and fails on drift.
- Artifact `path` values are **lowercase logical spellings** — the closed
  logical-path grammar rejects ASCII uppercase (`structure.path-case`) and
  characters outside `[a-z0-9._-]` per segment (`structure.path-segment`),
  and the validator never resolves the path against the filesystem (lexical
  data only). The convention: lowercase the repo path and fold `.` inside a
  segment to `-` (`src/Modules/Blog/Application/Services/BlogSearchService.php`
  → `src/modules/blog/application/services/blogsearchservice-php`). Restore
  by case-insensitive segment walk — unambiguous because no two repo files
  share a path modulo ASCII case. The guard test performs exactly that
  restore and asserts the file exists.
- Scenario nodes are declaration receipts: `contractVersion: 0.2.16` with the
  zero digest (`sha256:000…0`) — the model declares the scenario, it does not
  emit a canonical scenario document yet. `native_test`/`gate` nodes pin real
  evidence: `evidenceDigest` is the sha256 of the referenced test file
  (PHPUnit contracts) or of the `Makefile` (the `lekalo.validate-model` and
  `nativa.phpunit` gate definitions live there).
- `relationId` is the canonical sha256 tuple digest over
  `["lekalo/trace-manifest/v0.2.16/relation", kind, from, to, occurrence]`
  (compact JSON, upstream `Relation::canonical_id`) — recomputed by every
  consumer, re-derived by the guard test.

**Canonical digests.** Canonical bytes are deterministic, so these are
re-derived, never asserted — nothing in the suite reads the table below, and it
can only ever describe the sources *as they were*. Re-derive with:

```sh
for m in users blog themes-design audit pages comments forms questions; do
  printf '%-14s %s\n' "$m" \
    "$(lekalo --json trace export "docs/lekalo/trace/$m.trace.json" | jq -r .manifestDigest)"
done
```

| Manifest | manifestDigest (at `f2b07a17`) |
|---|---|
| `users.trace.json` | `sha256:2a642a5290b33ffe8b3d7a48ef63edcd1ceca60b624dda2e4e649bfd0d83abaf` |
| `blog.trace.json` | `sha256:fcb3ab5d1af4eeb4d3126db9a409d74ee6db9f165e75771e702ae42652dd6a32` |
| `themes-design.trace.json` | `sha256:ca4d5bd79175e71d42b7eb27bfe7ef2e9cdca6091ef25c9184313dd858a5b17c` |
| `audit.trace.json` | `sha256:b4e27a6380abfecbfb2b10248bad32603733d23ed368d05c934ac289380f4258` |
| `pages.trace.json` | `sha256:5ab5e97d1db37858f7c97353b0d9ffef81dc40dbd8500b17c909b078cf0fbaa6` |
| `comments.trace.json` | `sha256:71ca05538cd75961c6997022e353d3b62623ee8ac7511ad533fc15aeb05d84d8` |
| `forms.trace.json` | `sha256:72a3cfce728cfde27bf0a71aa2360cca7b4dc505d1d5d295da6011c1baaab336` |
| `questions.trace.json` | `sha256:83405eacd4408e6fcca40bf8872edbcb86ee512e275dda58827d2b1b28550af3` |

**A recorded digest goes stale the moment a digested source moves — all of them
at once.** `evidenceDigest` is the sha256 of the real file behind each node, and
every manifest digests the **`Makefile`** for its `make-<target>` gate
definitions. Editing the Makefile therefore rewrites all eight digests in one
go, and the seven values recorded here before `f2b07a17` were wrong for all
seven rows — a table that looks authoritative and is a snapshot. Same failure
class as the manifests themselves drifting (`Makefile` note in the same commit),
and the reason the values above are labelled with the revision they came from.

**Gates:** `make lekalo-trace` loops `lekalo trace validate` over
`docs/lekalo/trace/*.trace.json` and then runs `bin/trace/build.py --check`
(the drift half of the gate); it runs inside `make pre-commit` (after
`lekalo-check`), and CI's `lekalo` job runs the same two steps after
`validate`. `make trace-build` regenerates; `make lekalo-doctor` is the
informational readiness report.
Query examples (all verified):

```sh
lekalo trace validate docs/lekalo/trace/users.trace.json
lekalo trace query docs/lekalo/trace/users.trace.json requirements-for:users.register
lekalo trace query docs/lekalo/trace/blog.trace.json gaps
lekalo trace query docs/lekalo/trace/themes-design.trace.json tests-for:design.resolve_tokens
lekalo --json trace export docs/lekalo/trace/blog.trace.json | jq .manifestDigest
python3 bin/trace/build.py forms   # regenerate one manifest
python3 bin/trace/build.py --check  # the drift gate
```

Deliberately **not** wired: no artifact nodes for the TS bundle (scan
inventory is unmerged — see the scan section), no `full` completeness (the
receipts do not exist), no per-commit digest assertions in CI (the manifests are
regenerated on demand by `make trace-build` and verified in place by
`make lekalo-trace`, not rebuilt by a commit hook).

**What the generator recomputes, and what the mapping owns:** the mapping
(`bin/trace/mappings/<name>.json`) is the authored part and holds only the chain
— which symbol implements which requirement, which one binds to which artifact,
which scenario covers which symbols, which test verifies what, plus the typed
gaps. Everything a consumer can check comes from the sources: requirement
anchors are re-derived from the canonical specs (so a renamed `### Requirement:`
heading fails the build), every symbol must be declared under `lekalo/modules/`,
artifact `contentDigest` and native-test `evidenceDigest` are the sha256 of the
real file behind the lowercase logical path, `sourceRevision` is the commit the
manifest was authored at, and every provenance `sourceDigest` points at bytes —
the binding's model file, the requirement's spec, the PHPUnit file behind the
test, or the Makefile (including a `make-<target>` gate invocation).

## Invariants and state transitions (#63, adopted as declaration in 0.1.31)

Product 0.1.31 ships Lekalo issue #63 as a **core library module**
(`lekalo_core::invariant_transition`) — no CLI subcommand consumes project
attachment files yet (same situation as #26's extended-effects). The project
nevertheless declares its state machines as a #63 attachment, verified against
the real v0.3.2 contract with a scratch harness over `lekalo-core`:

| Path | Content |
|---|---|
| `docs/lekalo/invariant-transition.nativa.json` | The `lekalo/invariant-transition/v1.0.0` attachment (`dev.lekalo.invariant-transition@1.0.0`, revision 1.0.0, `projectId: nativa`) |

Contents: three state spaces — `nativa.blog.article` (`public` ⇄ `members_only`
gate, `blog.update_article` owns both flips), `nativa.comments.moderation`
(`pending` initial, `approved`/`rejected`, cyclePolicy `allow` because the admin
queue can re-approve a rejected comment and vice versa — no terminal states in
this runtime), `nativa.users.account` (`active` ⇄ `inactive` via
`users.update_user`); four invariants (`member_of_set` over each space plus the
`field_value` pending-on-submit rule); six transitions with ordered literal
assignments, separate `policyRef`s (`authorization.nativa/blog-admin-editing`)
and branch error refs; four verification mappings — two `full` `tested_by`
mappings with real evidence digests (`tests/Integration/CommentsTest.php`,
`tests/Integration/BlogMembersTest.php`) and two honest `gap`s for the runtime
enforcement that is not yet wired.

Notes for future edits:

- The attachment pins `modelRef.digest` (Model 0.2.16 contract digest from the
  lock) and `irRef.digest` (sha256 over the **compact canonical IR JSON without
  a trailing LF** — verified against the `model_ref` pin review). Refresh both
  after model changes.
- The wire is closed and ASCII-strict: camelCase keys, descriptions limited
  to plain ASCII text (em-dashes and apostrophes are rejected), every
  transition needs at least one `errorRefs` entry, self-loop transitions
  from any state are refused (declared `deadPolicy`/`cyclePolicy` interact
  with reachability checks), and `policyRef` uses the namespaced grammar
  (`ns.name/name`), not a semantic id.
- It lives under `docs/lekalo/` because the loader enforces a closed
  `lekalo/` root (`project.yaml`, `modules`, `targets`, `authorization.yaml`);
  promote it once upstream picks a canonical project attachment home.

## Deferred upstream surfaces (as of 0.1.31)

- **Error contracts (#62)** — no per-project declaration surface yet (the
  error registry is a contract-published seed for the demo project). The
  `users.error.*` vocabulary is reserved and ready to register.
- **Extended-effects attachment (#26)** — the attachment has **no canonical
  source home and no CLI** yet ("no source attachment home" — the canonical
  home awaits a reviewed #4/#7 revision). The intended declaration for the
  three users events is fixed and documented: `delivery: local`,
  `ordering: unordered`, `deduplication: none`, `sensitivity: standard`,
  `portability: portable`.
- **Adapter projection (strict review)** — strict review passes with the
  declared policies and the `php-nativa` target's full mapping evidence
  (`mappings` in `lekalo/authorization.yaml`). The runtime projection of
  those policies (middleware/guard wiring) stays with the adapter owners
  (#27–#29); the mapping is declaration-level evidence, not generated code.
- **AIFHub provider integration** — the target/provider protocol family is
  still unpublished (`versioning.protocol-unpublished`).
