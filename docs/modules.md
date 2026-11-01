# Modules > adding and removing

A module is a slice of the application that can be installed and, provably,
removed again. The catalogue is `modules/<Name>/`; installation copies the
slice to `src/Modules/<Name>/` (or `src/Modules/Themes/<Name>/` for a grouped
module), removal deletes it. Everything the tool knows
about a module it read from the module's own files — never from a list the
kernel would have to keep.

## The tool

`bin/cms` is plain PHP with zero dependencies (the kernel runtime has no
Composer packages, so it cannot be a symfony/console application). Exit 0 on
success, 1 on refusal or error; refusals go to STDERR and copy nothing.

The classes behind it (`ModuleCatalog`, `ModuleInstaller`, `ModuleManifest`)
live in `src/Modules/Tooling/` — committed kernel code the exported slice
ships, but **not a module**: `module:list` never lists `Tooling` and
`module:remove Tooling` refuses with *"is the kernel itself — it cannot be
removed"*, so the tool cannot be used to delete itself. Before 2026-10-15 they
sat inside `Modules\Core\Application\Services`; the namespace is the only thing
that changed.

| Command | What it does |
|---|---|
| `php bin/cms module:list` | installed + catalogued modules |
| `php bin/cms module:show` | the catalogue → slice drift for every module, with file counts |
| `php bin/cms module:show <Name>` | that module's manifest plus the same drift answer |
| `php bin/cms module:add <Name>` | copy the slice in; flush the page cache |
| `php bin/cms module:remove <Name> [--force]` | delete the slice; flush the page cache; report (never delete) the data |
| `php bin/cms migrate` | run every module migrator (the first-request run, forced) |
| `php bin/cms schedule:run` | run the due timetable entries (the outbox drain, and the queue worker — see below) |
| `php bin/cms queue:work [<name>] [--limit=N]` | work one batch of queued jobs; exit 1 on a parked one (see below) |
| `php bin/cms queue:prune [--interval="30 days"] [--dry-run]` | drop failed jobs older than the interval — never part of `queue:work` (see below) |
| `php bin/cms discovery:clear` | delete the discovery cache (`storage/cache/discovery.php`); the next request rescans |
| `php bin/cms discovery:status` | whether the discovery cache exists, is fresh, and how many routes it holds |
| `php bin/cms clickhouse:attach [--db=<name>] [--prefix=<p>] [--dry-run] [--force] [--checkpoint] [--typed]` | attach every `storage/data/*.db` as a ClickHouse database (see below) |
| `php bin/cms clickhouse:status` | the server's view of the attached databases, and drift |
| `php bin/cms clickhouse:query "<sql>" [--db=<name>] [--write] [--format=JSONEachRow\|TSV]` | run one statement (read-only unless `--write`) |

## Catalogue vs slice: drift, measured (task 4.1, 2026-10-17)

`modules/<Name>/` is the **source**: it is tracked in git, an author edits it,
and `module:add` copies it. `src/Modules/<Name>/` — or the grouped
`src/Modules/Themes/<Name>/` — is the **installed slice**:
generated, untracked, and the copy the running app loads. Nothing in git
compares the two, so a fix can land on one side only while `git status` stays
clean and the page keeps serving the old code. `module:show` with no name
answers that question by content hash, per relative path:

```
Catalogue → installed slice (the catalogue in modules/ is the source):
  Audit          identical       8/8
  Locales        drifted         18/17 (1 only in catalog, 0 only in installed, 0 changed) — tests only
  Scratch        not-installed   1/0
  KernelTheme    not-catalogued  0/2
...
13 identical, 6 drifted, 1 not installed, 1 not catalogued.
```

Four states, each with a count on both sides:

| status | meaning |
|---|---|
| `identical` | every file matches by content (not mtime — a reinstall touches every timestamp) |
| `drifted` | at least one file is only on one side or has different bytes; `module:show <Name>` lists each by name |
| `not-installed` | the catalogue entry exists and the slice does not |
| `not-catalogued` | the slice exists with no catalogue entry — `KernelTheme` is this by design, it ships inside `src/` |

**Expected drift: test files only.** Module tests run from `modules/`
(`phpunit.xml`'s module suite), and the app never loads them, so a slice that
predates a test-only addition is harmless. The report names that case
`— tests only`; every other difference is followed by `— RUNTIME` and the
reinstall command, because that is code the page loads. Measured on
2026-10-17, all six drifted modules were tests-only (`Blog`, `Cardboard`,
`Comments`, `Locales`, `Questions`, `Queue`); `Mono` had a stale built
`assets/critical/mono.css` and was reinstalled, after which it read
`identical`.

A test file is never a reason to reinstall, and a runtime file is never a
reason not to: the point of the report is that neither answer depends on
someone remembering which copy was edited.

### Two shapes: `modules/<Name>/` and `modules/Themes/<Name>/` (measured 2026-10-17)

The catalogue root holds modules that DO something: `Blog`, `Pages`, `Queue`.
The four presentation modules (`Erik`, `Griffe`, `Jednotka`, `Mono`) are
themes, so they are filed under `modules/Themes/<Name>/` and the root reads as
a list of features rather than a mixed bag. Measured at the move: 15 files,
0 insertions and 0 deletions — a `git mv`, not an edit.

**The installed tree has the same shape.** `module:add Erik` copies to
`src/Modules/Themes/Erik/`, and the theme therefore owns
`Modules\Themes\Erik` — PSR-4 maps the namespace onto the path, so the two
must agree. Discovery is recursive and reads each file's own `namespace`
line, so `ThemeRegistry` needs no group knowledge; what does need it is every
resolver of a module-declared path. Those are all `AppPaths::moduleFile()`:
`InlineCss`/`InlineJs` (critical CSS, theme init script), the asset test, and
`ModuleTreeState`. The declarations themselves stay group-agnostic
(`Modules/Erik/assets/…` — the module, not the layout).

Identities do not move: the theme NAME (`erik`, what the settings select
stores) and `lekalo/modules/erik/` are unchanged, and `phpunit.xml`'s module
suite is recursive, so the tests under `modules/Themes/Erik/tests/` still run.

`Themes` is a GROUP, not a module, and it is excluded by shape rather than by
syntax: the name passes `isValidName()` (PascalCase), so
`ModuleCatalog::cataloguePath('Themes')` returns `null`, `catalogued()` skips
the directory and lists its children instead, and `module:add Themes` is
refused with a message saying it is a group. `module:list` prints the grouped
members on their own line so a reader looking in `modules/` does not conclude
they are missing:

```
Installed (20): ...
Catalogued (20): ...
Themes (4): Erik, Griffe, Jednotka, Mono — modules/Themes/
```

Two resolvers, each on its own side and each constructive:
`ModuleCatalog::cataloguePath()` / `installedPath()` know where a module is
filed (`catalogueGroups()` is the only list of group names), and
`AppPaths::moduleFile()` resolves a module-declared asset against the
installed tree. `bin/cms`, both build scripts, `ModuleTreeState` and the gates
that guard the catalogue ask one of them rather than spelling a path
themselves. `installedPath()` returns the grouped path even before anything is
copied, which is what makes `module:add` land in the same shape the catalogue
uses. That matters
because the admin page compares the catalogue with the slice, and a
hand-built `modules/<Name>/` would fail `is_dir()` there, return "no
differences" and report every theme as `identical` — a false green that
testing the happy path cannot catch.

## What the scan sees

Routes from `#[Route]`, migrators from `#[AsMigrator]`, middleware from
`#[AsMiddleware]`, nav badges from `#[AsNavBadge]`, markdown blocks from
`#[AsMarkdownBlock]`, shell providers from `#[AsShellContextProvider]`, themes
from `implements ThemeModule`, assets from `assets/` directories, the SQLite
file from the module name (`Scratch` → `storage/data/scratch.db`), and the
dependency closure from `use Modules\<X>\…` across the module's own files
(never self, `Core`, `Database` or `Tooling` — those are the tree, not
something in it).

The scan reads files as TEXT and never loads a class it describes, so a module
whose dependencies are absent is still describable. A scanner that fatals on a
missing class cannot report the very closure that is missing.

The admin gate is picked up the same way, one level down: a module ships an
`AuthProvider` marked `#[AsAuthProvider]`, and `AuthProviderRegistry` (Core)
finds it by file name — `*AuthProvider.php` in the module's `Interfaces/HTTP`.
The composition root asks that registry and wraps `AuthMiddleware` around what
it gets, so the HTTP composition root (`src/http.php`) names no module class
for auth at all. Install
the module and the gate exists; a tree with the admin router but no provider
module answers 200 (there is nothing to gate with — a route is not a guard),
and a kernel-only tree answers 404. (`Users` used to ship an `AuthWiring::wire()`
entry point for this; it was deleted when discovery landed — see
`docs/kernel-status/04-kernel-contracts.md` §6 and
`docs/kernel-status/06-auth-discovery-and-mail-transport.md` §10 for the
measured states, with `docs/kernel-status.md` as the index over the parts.)

`RateLimitMiddleware` is the one stage still wired explicitly in
`src/http.php`, behind `class_exists`: it needs a limiter FACTORY in its
constructor, which the zero-argument scan cannot supply, and its product is a
service holding a PDO rather than a zero-argument port implementation.

The security-event sink is wired the same way and for the same reason: the
kernel owns the port (`Modules\Core\Domain\Contracts\SecurityEventSink`), the
`Outbox` module implements it (`OutboxSecurityEventSink`, where the event type
and payload keys live), and `src/http.php` constructs it behind `class_exists`
and hands it to `AuthMiddleware` and `RateLimitMiddleware`. Both middlewares
name no module, so a kernel-only tree drops the record and still answers the
request — and `mago guard` sees a kernel with no module dependency at all.

## The closure rule

`module:add` refuses when any closure member is not installed, names the
missing modules, and copies nothing. `module:remove` refuses while any
remaining installed module imports the name, names the importing file, and
leaves the slice untouched. `--force` is the only way past a refusal.

`Core` and `Database` can never be removed — they are the kernel itself.

## module.json (optional)

Only for what a scan cannot see: an npm package, a PHP package, a runtime
dependency on another module.

```json
{
  "depends": ["Locales"]
}
```

A module written before this capability existed installs unchanged, on the
strength of the scan alone — `modules/Scratch/` ships no `module.json` on
purpose, so the suite keeps proving that path.

## Removal never deletes data

The code is not the data. `module:remove` deletes the slice and reports what
it leaves behind: the module's `storage/data/<db>.db` (with `-wal`/`-shm`/
`-journal` when present), its uploaded assets and its frontend package entry.
The operator may hold the only copy; a removal that silently deletes it is not
a removal, it is loss.

## The round trip (measured 2026-10-11)

`modules/Scratch` — one route (`GET /scratch`), zero non-kernel imports — is
the lifecycle fixture:

- hash of the tree (excluding `.git`, volatile `storage/` and `.lekalo/`):
  `812636c7…` before and `812636c7…` after — byte-identical;
- `module:add Scratch` → `/scratch` answers **200** (`<title>Scratch</title>`);
  a second `module:add` is refused (`already installed`, exit 1);
- `module:add Provisional` (a throwaway slice importing `Modules\Nope`)
  is refused by name (`missing dependencies: Nope`, exit 1, nothing copied);
- `module:remove Scratch` while a holder imports it is refused with the
  importing file named (`src/Modules/Holder/Holder.php`, exit 1);
- after a real `module:remove`, `/scratch` answers **404** on a fresh server
  process; `/` and `/docs` still answer **200**.

Restart the server between phases: the built-in server is one long-lived
process, and a class it already declared outlives the file it came from. A
check run against a removed-but-remembered class is a green run of a file that
no longer exists — the same stale-bytecode lesson
`docs/kernel-status/01-import-and-edge-cuts.md` records for edits.

## The Lekalo model > what it is, and what it does not check

`lekalo/` is a **semantic model of this codebase**: entities, commands, effects,
queries, events, policies, endpoints and scenarios, described in Lekalo's
grammar. It is the one place where the shape of the system is written down as
data rather than as prose, and it is worth knowing exactly how much standing it
has, because the gate in front of it is easy to over-read. Mago is a different
layer — it reads the PHP source, not this model — and the side-by-side table is
in `docs/mago-evaluation.md`.

| | |
|---|---|
| what runs | `make lekalo-check` = `lekalo lock --check` + the whole model + **every module on its own** (`bin/build/lekalo-modules.sh`), in the `Lint + PHPStan + Lekalo model` CI job |
| the CLI | pinned to release `v0.6.5` with a sha256-verified archive — the same pin and checksum `nativa-minimal` uses, so the two projects cannot drift into validating against different binaries |
| measured | run 38933437965: the install step and the gate step both green on a real runner |

**What it verifies:** the model is well-formed and self-consistent — every `ref`
resolves (`LEK-SEM-019`), every value matches the grammar (`LEK-IR-006`), ids
are unique, the CLI's contract versions match the lock. Both halves are real
gates, measured without a pipe so the exit code was the tool's and not `tail`'s:
a `ref` pointing at a symbol that does not exist gives `lekalo validate` exit 1
and `make lekalo-check` exit 2.

**Each module on its own** is a command (`make lekalo-module MODULE=blog`) and the
gate runs all of them, but the tool's per-module answer is weaker than it looks,
measured 2026-10-16: `--module` scopes the **report**, not the validation, so a
healthy module exits 1 while another module is red — with a dangling ref in
`blog`, `lekalo validate --module media` prints *blog's* diagnostic and fails.
What the gate gets per module, then, is **attribution by source path**: a
`blog` diagnostic names `lekalo/modules/blog/commands.yaml`, so the gate prints
`FAIL blog` once instead of failing all twenty-one. Building that surfaced two
tool quirks worth knowing: the text output of a structural refusal
(`LEK-STR-004`, `LEK-STR-015`) names **no path at all** while `validate --json`
carries one for the same diagnostic, and on an INVALID model that JSON arrives on
**stderr** with stdout empty — a reader of stdout alone sees no diagnostics exactly
when there are some.

**What it does NOT verify, and this is the half that matters:**

1. **Nothing compares the model to the code.** `lekalo.lock` reports
   `adapters 0` — there is no PHP adapter — so there is no code extraction step
   to compare against. A model whose every statement had gone stale would still
   validate cleanly.
2. **`lock --check` does not pin the model.** The digest it reports,
   `sha256:e77647f7…`, is the same one recorded in
   `docs/kernel-status/05-security-findings-7-to-9.md` — across the whole §7–§11
   security remediation and two Boot rounds since. Editing a description leaves
   it untouched; adding six files and forty-three definitions leaves it
   untouched. The lock covers the CLI's contract versions, not the project's
   model content.
3. **A description can be false and pass.** Measured: changing
   `queue.job`'s `available_at` description to claim "a CONSTANT 30 second
   delay, never growing" — the opposite of the doubling, capped backoff that
   ships — leaves `make lekalo-check` at exit 0. A false *payload list* passes
   the same way, and that is where the next binding went.

   Measured 2026-10-16, for `users.authorization_denied`:

   | | keys |
   |---|---|
   | model `payload` | `required, path, user_id, ip` — **4** |
   | `SecurityEvents::payloadKeys(AUTHZ_DENIED)` | `path, required, userId, roles, ip` — **5** |
   | in code, not in the model | **`roles`** |
   | the same event's own description | lists `roles` — the array did not |

   Both emit sites send it (`AuthMiddleware::recordDenial()` — through the
   kernel's `SecurityEventSink` port since 2026-10-22, see the note below — and
   `SecurityEventServices::bulkRefused()`), so the model was the only side still
   describing four keys, and it contradicted itself doing so. **Fixed
   2026-10-16:** `roles` is declared, and `ModelPayloadContractTest` binds the
   event's payload to `SecurityEvents::payloadKeys()` — the class read as TEXT,
   not loaded, because it lives in an optional module and `phpstan.neon`
   analyses `tests/`. Three negative controls (the key removed; the description
   naming a constant that does not exist; the code declaring one more key) each
   red, both files restored by digest. It is deliberately narrow: **one event,
   the one whose description names a `SecurityEvents::` constant**, which is the
   whole of the seam that exists today.
4. **`error_ref` is resolved by nothing the tool ships — a test does it instead.**
   The tool resolves the referenced MODULE and denies with `LEK-AUTH-005` when it
   is missing from the model; it never resolves the SYMBOL, and it accepts any
   string in the field. Measured 2026-10-16, that is how `lekalo/authorization.yaml`
   came to carry **15** `error_ref` values naming **2** symbols —
   `users.error.forbidden` (8) and `users.error.unauthorized` (7) — **neither of
   which existed anywhere in the model**, behind a gate reporting 23 rules,
   0 errors, exit 0. `lekalo inspect` on either ref answers
   `exit 1 [LEK-INS-001] inspect.symbol-unknown`. The model has no `error` kind at
   all (its vocabulary is closed — command, effect, endpoint, entity, enum, event,
   policy, query, scalar, scenario, target-binding, value-object; `kind: error` in a
   slice is refused with `LEK-IR-003`), so no such symbol could have been created.
   **Fixed 2026-10-16:** all 15 now name `users.authorization_denied`, the event the
   one denial path in `Modules\Core\Interfaces\HTTP\Middleware\AuthMiddleware`
   raises, and `ModelReferenceContractTest` — new the same day — resolves every ref
   against the model's definition ids and refuses one that names a non-`event`.
   The model gained one definition doing so (`lekalo load --json`: 250 → 251), so
   the source-map counts in the route table above are one behind today's tree. The
   tool-side gap is unchanged; the hole it left is closed by a test. `--strict` does
   not close it either — measured the same day, strict is red on
   `LEK-AUTH-009 authorization.effect-unprotected` (protected effects with no
   authorization declaration) and reports no `LEK-AUTH-005` at all.

   **Naming a real event is what exposed the next defect, and the round went on to
   fix it: the middleware's denial reached no audit row.** The refs had to name
   something that is really raised, so both emit sites were read and then run — each
   emit into a throwaway outbox, the real `OutboxProcessor` over both:

   | emit site (before) | outbox `event_type` | outcome |
   |---|---|---|
   | `BulkPublishAction` → `SecurityEventServices::bulkRefused()` | `security.authz.denied` | `processed`, **one `audit_log` row** (`Refused /mark/bulk-publish (needs users.manage…)`) |
   | `AuthMiddleware::recordDenial()` → `AuthProvider::noteEvent('authz.denied', …)` | `authz.denied` | **`failed`** — `No handler registered for event type: authz.denied` — **no audit row** |

   `SecurityEventHandler::register()` binds one handler per type in
   `SecurityEvents::all()`, which holds `security.authz.denied` and not the bare
   `authz.denied` the middleware passed through `noteEvent()` verbatim — so every
   refused admin path wrote an outbox message no handler could ever claim: five
   retries, then parked, with no row. The dilemma (name the owned type, or bind the
   bare one) had already been answered twice in code nobody had read:

   - **`RateLimitMiddleware`, the same directory, emits the owned constant** —
     `OutboxServices::securityEvent(SecurityEvents::RATELIMIT_TRIPPED, …)`, inline
     behind a `class_exists` guard. The auth middleware was the only refusal site
     that spelled a name instead of naming one.
   - **`SecurityEvents::isSecurityEvent()` exists for exactly this and had zero
     callers**, its docblock describing what happened here: "an unregistered type
     throws — so this is what keeps a typo from becoming a message that retries five
     times and then fails". `payloadKeys()` was unwired too, and it omitted `ip`
     although the Audit handler reads it and every emit site sends it.

   **Fixed 2026-10-16:** the middleware names `SecurityEvents::AUTHZ_DENIED` the way
   its neighbour does; `payloadKeys()` declares `ip` for both refusal types; and the
   provider seam — the one boundary that still takes a string — refuses a name
   outside the vocabulary loudly instead of parking it, which is `isSecurityEvent()`'s
   first caller. `AuthMiddlewareDenialTest` pins the outcome end to end: a 403 from
   the middleware leaves one `processed` outbox message and one `audit_log` row with
   the actor, the target and the missing permission in it; the emitted payload keys
   equal the declared ones; an owned name is still recorded while `authz.denied` is
   refused; and an inactive identity is refused with no event at all — the one
   refusal decided before any policy.

   **2026-10-22 — the inline naming became a port.** `mago guard` was right about
   the shape that kept the `use`-line ledger empty: a kernel that can only say
   "record an `AUTHZ_DENIED`" by naming `Modules\Outbox\…` still depends on the
   module, whether or not a `class_exists` guard protects the call. Both
   middlewares now raise the fact through
   `Modules\Core\Domain\Contracts\SecurityEventSink` (`authorizationDenied()`,
   `rateLimitTripped()`), `OutboxSecurityEventSink` maps it to the owned type and
   the declared keys, and `src/http.php` wires the adapter. The kernel names no
   module, guard reports 0 with no baseline, and
   `SecurityEventPayloadContractTest` reads the adapter as the emit site instead
   of the two middleware files. Four negative controls, each reverted with the
   tree verified by digest: the bare name back → the delivery test red; the guard
   removed → red; `ip` dropped from the declaration → red; the inactive branch made
   to record → red. The same claim is then made against a **booted server**:
   `bin/build/admin-smoke.sh` walks an author's `GET /mark/users` to the 403, reads
   the `security.authz.denied` message back out of the outbox by its `path`
   (`json_extract`, because `json_encode` escapes the slashes), and — after the
   end-of-request drain has provably run — asserts the audit row that names
   `/mark/users` and that nothing sits under the bare `authz.denied` no handler can
   claim.
5. **A test reads the model's module set — and nothing else.**
   `ModelCatalogueContractTest`
   (2026-10-16) compares the model's *module set* against the catalogue: every
   slice names a module the catalogue knows, every catalogued module has a
   slice, and the kernel slices are named as a list rather than excused by a
   pattern. That catches a slice left behind by `module:remove` or a module
   nobody wrote down — measured, by deleting the slice directory and watching
   the test fail. It says nothing about whether any *description* matches the
   code, which is item 1; before this test, `grep -rl lekalo tests/` was empty —
   there are two such tests now, this one and the reference guard in item 4.

So: **the model is documentation with a schema, and the gate keeps the
documentation well-formed.** It does not make the documentation true. Nothing
below should be read as "the model is enforced against the implementation".

What it HAS been good for is the other half of the job — as a checklist a human
reads. The §7 audit found the password minimum stated as 8 in three code
places *and* in `lekalo/modules/users/policies.yaml`
(`docs/kernel-status/05-security-findings-7-to-9.md`); the model did not catch
it, but naming it in four places is what made the fourth one findable.

**Coverage, measured 2026-10-16: seven of twenty-one modules modelled** —
`audit`, `blog`, `comments`, `pages`, `questions`, `queue`, `users`. The other
fourteen are stubs holding a `module.yaml` and nothing else, including `core`
and `database`. A facet is optional (`audit` has no policies, no queries and no
bindings), which is why `queue` has no `policies.yaml`: the queue has no
authorization or validation rule to state, and inventing one to fill the gap
would put the first uncheckable claim in the model.

### Where the model may live is not ours to choose (measured 2026-10-16)

The obvious follow-up to the binding test is "then let each module own its
slice" — move `lekalo/modules/<name>/` into `modules/<Name>/lekalo/`, so the
model travels with the thing it describes. **The tool does not support that,
and the ways it fails are worse than the thing it fixes.** Four routes, all
measured against the pinned CLI, with the model fingerprinted before and after:

| route | measured result |
|---|---|
| move the slice out of `lekalo/` | **not loaded, and not reported**: `sourceMap` 272 → 271, the module's definitions gone, `lekalo load` still `valid`. Only modules whose symbols are referenced elsewhere are caught — `authorization.yaml` gives `LEK-AUTH-005 authorization.ref-unresolved`, exit 1, which is how a whole-tree move fails loudly and a leaf like `media` fails silently |
| symlink the directory, or one file, out of `lekalo/` | **refused**: `structure.path-link` (`LEK-STR-013`, severity `error`, category `security` — "Project path policy refusal"), `status: denied` |
| declare `imports:` in `module.yaml` or `project.yaml` | **nothing happens**: `status: valid`, no diagnostic, and no source from outside `lekalo/` enters the model. Imports are not a file-include seam |
| leave the module's directory with no document in it | **caught**: `structure.document-missing` (`LEK-STR-004`), `lekalo validate` exit 1, `make lekalo-check` exit 2 |

The source map is the evidence for the first row: it lists project-relative
paths, all of them under `lekalo/`, and there is no key in it — or in the lock —
that names a source root to configure. `lock --check` is no help either: it pins
the CLI's contract versions and a resolver request digest, not the model, so it
stays at exit 0 across a slice disappearing and across a description being
rewritten (item 2 above).

So the model stays one tree under `lekalo/`, and the binding test is what keeps
that tree honest. Making a module own its slice needs one of two things that do
not exist yet: a generator that materialises authored slices into `lekalo/` with
a freshness gate in CI, or a source-path seam upstream. This is recorded as an
open item against the tool, not as a design preference — the cheap version of
that change was attempted and reverted, byte-for-byte, on the same day.

## Themes travel as modules (measured 2026-10-11)

A theme arrives with the module that ships it: `ThemeRegistry` discovers every
installed class implementing `Themes\ThemeModule`, `ThemeKernel` resolves
through it (the static table keeps the kernel theme and always wins over
discovery, so no module can shadow the fallback), and `ThemeResolver` accepts
a provider-named theme when it is whitelisted or discovered. A removed module
takes its theme with it — nothing is edited, nothing lingers.

Five of the modules in that group are authored **outside this tree**, in
`evan70/nativa-themes` under the same `modules/Themes/<Name>/` path. The
catalogue here is their copy, because `bin/cms module:add <Name>` installs from
it; `make themes-check` (`bin/build/sync-themes.sh --check`) proves the copy by
SHA-256 and `make themes-sync` refreshes it. A fix typed into this catalogue is
therefore drift the gate reports — even though `git status` shows it as an
ordinary edit, which is exactly why the gate exists.

`modules/Themes/Erik/` is the proof: a trimmed `ErikTheme` (er-* vocabulary,
dark-first, one critical CSS file) rendering from `ShellContext` +
`PageContext` alone. The showcase Erik cannot live here — it imports
Blog/Locales/Settings, modules this tree does not have — so this one is the
smallest Erik that is still Erik, not a copy that fatals.

The slice used to carry a fixture provider answering exactly one question
(`theme()` → `erik`, everything else abstained), because a theme the port
never names is a theme no surface renders. The fixtures are removed since:
with three theme modules the glob-order vote became a liability, and the
Settings theme vote (priority −100) is the only theme answer — a theme
activates by picking it in `/mark` → Design, and removing the module removes
the option. With no stored choice the kernel theme renders.

- hash `b2bb62c8…` before and after — byte-identical;
- `module:add Erik` → `erik` discovered (registry + Design select), the
  chrome itself switches only when picked: stored `erik` answers **200** in
  the `erik-shell` body class with `er-header__brand` chrome and the critical
  CSS inlined (45-line `<style>`, no `dist/` references — the absent Vite
  manifest degrades to the theme's own bytes, not to another theme's);
- `module:remove Erik` → discovery empty, the select option gone, a stale
  stored `erik` degrades to `kernel-shell` with `k-header__brand`, no fatal.

A theme that cannot be constructed is skipped and logged, never fatal — the
answer to a broken theme is the kernel theme, not a 500 on every surface.

## A second theme: Mono (measured 2026-10-11)

`modules/Themes/Mono/` carries the evan70.github.io/mono vocabulary (navbar, hero,
feature cards, footer) as `MonoTheme`. The CSS is a vendored, prefixed
snapshot of the live site's two stylesheets (2026-10-11, ~19 kB → `mo-`):
the source classes are unprefixed and would collide, the `@font-face` webfonts
are dropped (their files are not vendored — system stacks instead), and the
hero photo is dropped (an external webp the kernel must never fetch at
runtime; the gradient stays). The adaptation script caught its own blind spot
on the first run: compound selectors (`.a.b`) skip the regex lookbehind, so
four second-classes shipped unprefixed until a second pass closed them —
verified by grepping the vendored file for unprefixed selectors (zero left).

What the preview page never had is designed in its idiom, not ported: the
locale switcher (navbar links), the data-driven nav, the blog views (card
grids). Every `mo-` class the markup emits has a CSS rule (checked
mechanically). The hero title renders `mo-is-visible` server-side — on the
live site scroll-JS adds it, but this tree ships no such JS and without the
class the H1 keeps `opacity:0` (a homepage that renders its own title
invisible; caught over HTTP, fixed, re-measured).

### The article view (measured 2026-10-12)

The vendored snapshot is a LANDING PAGE — navbar, hero, feature cards, footer.
The kernel serves surfaces that page never had, and each of them reaches the
DOM wearing `mo-` classes no rule matched. On `/blog/nativa-php-core`, before
this pass: **27 `mo-` names without a rule** (20 classes — 9 comments, 11
questions — plus 7 `id` attributes, which need none), and the article's own
elements bare: no `pre`, no `code`, no `table`, no `th`/`td`.

Same defect class the Frontend packages already guard against, in a tree that
cannot run their generator: `CommentSection` and `QuestionBlockRenderer` build
every class from the ACTIVE THEME'S prefix, so `mo-comments` can only ever
match rules written for mono, and mono has no package under
`Frontend/packages/` for `bin/generate/generate-*-css.mjs` to stamp.

Added to the vendored file, in its idiom (mono tokens, minified, one rule per
line): prose (`pre`, `code`, `pre code`, `table`, `th`/`td`), the two reaction
blocks (`mo-comments*`, `mo-questions*` — all 33 classes the two renderers can
emit), and the two structural wrappers the docs/pages surfaces emit
(`mo-section`, `mo-article__body`).

| | before | after |
|---|---|---|
| inlined stylesheet on the article page | 19 056 chars | **23 827 chars** |
| `mo-` classes without a rule, across `/`, `/blog`, `/docs`, the article | 27 on the article; `mo-section` on `/docs` | **0** |

Verified in chromium over the running server, in both colour modes — the rules
name mono tokens only, so `[data-theme=light]` needs no second copy:

- **dark**: button `rgb(212, 175, 55)` on ink `rgb(10, 10, 10)`, `pre` bg
  `#1a1a1a` with a `#2d2d2d` border and 8px radius, `pre > code` reset to
  transparent / 0 padding, `th` bg `#1a1a1a` weight 600, `td` bottom border
  1px `#2d2d2d`, the honeypot `display:none`;
- **light**: the same rules through the light palette (`#f8fafc` surfaces,
  `#e2e8f0` borders, `#926f00` button) — the light block shipped dormant all
along and needed nothing new;
- the class check re-run over every served page: zero `mo-` classes without a
  rule.

One rule has no element to style in this deployment: `mo-article__body` is
emitted by `DocsPageAction` / `PageViewAction` for a guide or page that has
content, and no guide is published here — so it was verified as a parsed rule
(the browser accepted it) rather than against a rendered element.

Not covered, recorded so the next reader does not mistake them for present:
`blockquote` and the article body's headings/lists still render with browser
defaults, and `mo-inline-edit*` (9 classes from `BlogInlineEditor`, rendered
only for a signed-in editor) has no rules at all.

The slice used to carry the same one-question fixture provider (`theme()` →
`mono`) — removed together with the Erik/Griffe fixtures once the Settings
vote became the only theme answer (see above). There is no glob-order vote
left to collide: co-installed themes simply wait in the Design select.

- hash identical before and after the round trip;
- `module:add Mono` → `mono` discovered (registry + Design select), stored
  `mono` answers **200** in `mono-shell`, hero H1 visible, the full CSS
  inlined, zero runtime fetches (no `url(`, no `@import`, the single `http`
  href is the page's own canonical);
- `module:remove Mono` → discovery empty, the select option gone, a stale
  stored `mono` degrades to `kernel-shell`.

The first hash pair of this round trip mismatched — honestly recorded: two
catalog fixes (an unprefixed `btn--sm` in the markup, the missing
`mo-is-visible`) landed between H0 and H1, so the hashes compared different
trees. The lifecycle was clean; the protocol was not. Re-run on the frozen
tree: identical.

### The white page (measured 2026-10-11, fixed)

Mono rendered a white page in a real browser while every curl check was
green: the vendored header comment said `No @charset/@import/@layer`, and
`InlineCss::stripForbiddenAtRules()` — which cannot tell an at-rule from its
name inside a comment — ate from `@charset` to the next semicolon, which lay
past `*/` inside `:root{--brand-gold:#d4af37;`. The `:root` opener died, every
`var(--…)` went undefined, `body` lost its background. Curl saw a 200 with a
`<style>` block; only a browser saw the white.

Two fixes, both committed: the stripper removes `/*…*/` comments BEFORE
at-rules (a safety net that corrupts what it guards is not one), and both
theme headers no longer name the at-rules even in prose. Re-measured:
`:root{--brand-gold:#d4af37;` and the `body` rule present in the served
`<style>`, zero comment residue. Erik's header had the same trap and survived
by luck — its first semicolon fell inside the comment.

### The dev variant switcher (measured 2026-10-12)

The upstream `navbar__actions` carries dev-preview palette switchers
(`dev-theme-switcher` toggling `neon.css`, a second button `fire-show.css`);
both stylesheets are `:root` variable overrides only (~1–3 kB), which is what
makes the port cheap. Mono keeps the shape but not the palettes: `ember`
(warm orange on near-black) and `glacier` (ice-cyan) live in the vendored
`mo-variants.css` as `html[data-variant]` blocks, the upstream webfont
overrides dropped (no external dependencies — the system stack stays).

The first button keeps the upstream `id="dev-theme-switcher"`; both key off
`data-variant-toggle`, mutually exclusive, second click toggles off, choice
persisted to `localStorage` (`nativa-variant`). The tracker is the same
vendored no-build twin idiom as the highlighter and the scrollspy
(`mo-variant-switcher.js`, ungated — its buttons precede every page), and the
persisted variant restores pre-paint from `MonoTheme::themeInitScript()`, which
reproduces the shared dark/light restore plus the variant. Without JS the
buttons are inert and the page stays in the default palette.

Verified over CDP against the running server: click → `data-variant="ember"`
with `--brand-gold: #ff7a1a` computed, `aria-pressed` synced, reload restores
pre-paint, second click clears. `MonoThemeTest` pins the ids, the button
order, the stylesheet selectors, and the init-script restore. A stale stored
value is ignored by both restores (pre-paint and tracker agree on default),
and the variants re-skin light mode too (`html[data-variant]` outranks
`[data-theme=light]`) — measured in headless over the light default
(`rgb(255, 255, 255)`): ember body `rgb(12, 7, 5)`, glacier body
`rgb(6, 10, 14)`.

## A third theme: Griffe (measured 2026-10-12)

`modules/Themes/Griffe/` renders the public surfaces in the webgriffe.com agency
idiom (sticky text-nav header, fullscreen home slider, line-mask scroll
reveals, case-study grid, minimal legal footer). The reference runs on Xtend
UI (Tailwind + vanilla JS); the port re-expresses only the USED behaviours in
hand-written `gr-` CSS plus three vendored ES2015-safe scripts
(`gr-header.js` hide-on-scroll, `gr-slider.js` autoplay/pagination/arrows,
`gr-reveal.js` IntersectionObserver reveals) — no framework, no build, no
CDN, colours deliberately neutral placeholders (layout and motion first).

Measured from the live homepage HTML (13 slides: 1 editorial hero + 12 case
studies, `data-xt-pag` progress pagination, `anim-block` line masks): the
theme maps articles to case slides and the grid below from the same
slug+title shape Mono/Erik read, hero slide active server-side, pagination
and arrows rendered only for 2+ slides, scripts gated on the markup they
drive (no slider markup → no slider JS). No-JS fallbacks: stacked readable
slides, plain sticky header, fully visible text (the reveal arms its masked
state from JS — the stylesheet never hides text on its own).

- `composer test` 88 / `phpstan` / Rector dry-run / `cs-fix-dry` green;
- Chromium/CDP over the running server: griffe chrome renders, reveal arms
  and hero lines gain `.gr-in`, hamburger opens the menu at 390px, reload
  keeps the chrome with `data-theme` restored, body wears the paper token
  (`rgb(255, 255, 255)`, sticky header confirmed — the white-page class of
  defect revisited and absent), served `<style>` 10 358 chars with `:root`,
  `@media` and `.tok-*` intact;
- the shipped `gr-slider.js` proven in Chromium against a grafted 2-slide DOM
  (verbatim server markup): goes live, pagination click activates slide 2,
  autoplay wraps;
- Selected work is illustrated: the kernel HomepageAction passes no articles
  (no Blog import by explicit contract), so the theme falls back to the
  latest published ones itself — `class_exists`-guarded, empty when Blog is
  absent, logged on failure. Case slides carry full-bleed covers, grid cards
  carry `gr-case__img` thumbnails; covers resolve locally when
  `storage/blog-images/` holds generated SVGs, else the established
  Cloudinary pool fallback. Live tree: hero + 2 case slides, pagination,
  autoplay wrap, every illustration loaded (CDP, 6/6).

The slice used to carry the same one-question fixture provider (`theme()` →
`griffe`) — removed together with the Erik/Mono fixtures once the Settings
vote became the only theme answer (see above).

## A fourth theme: Boson (measured 2026-10-17)

`modules/Themes/Boson/` carries the boson-php marketing design as `BosonTheme`:
a fixed 100 px header that shrinks to 70 px on scroll, dotted rails framing
ruled full-bleed bands, Roboto Condensed display type over Inter body copy,
and one brand red (`#f93904`) on near-black (`#0d1119`). The upstream
reference — the boson-php marketing site, a Symfony/Twig project whose body
is entirely Lit web components, styles inside shadow roots and artwork in
`/images` — is not vendored in this tree.

**What the port keeps and what it drops.** The markup is server-rendered PHP
and the stylesheet is one `bo-` prefixed file
(`assets/critical/boson.css`), so the theme ships no framework, no Twig and
no build. The reference's twelve elements become one vendored script
(`assets/scripts/boson.js`: header shrink, checkbox menu, horizontal
accordion, TOC scrollspy, syntax highlighting and copy buttons) — each init
self-gates on its markup, and the page is complete with the script absent.
The reference's artwork (`/images/logo.svg`, the platform icons, the
screenshots) is not in this repository, so the hero orbit, the platform marks
and the dotted rails are CSS drawings; the remote font stylesheet is replaced
by the local webfonts the tree already ships.

**Nativa copy, Boson structure.** The sections are the reference's: hero,
premise, the surfaces matrix, starting points, the three-column band, the
"under the hood" horizontal accordion, the comparison, the API split, the
principles band and the closing call. Their text is this project's, read from
`PageContext::$copy` first with a `DEFAULT_COPY` floor (the same shape Erik,
Mono and Jednotka use), and the links point at `/blog` and the
`AdminRoutePolicy` prefix, never at boson-php. The testimonials band survives
as three unattributed principles: the reference's named endorsements cannot
be ported without inventing quotes.

**Blog surfaces.** Index, search, article, tags, categories, author and error
share the ruled vocabulary: hairline-bordered card grid, chevron pager,
prose styles for `pre`/`code`/`table`/`blockquote`, a contents rail that
ships `open` (a closed `<details>` cannot be forced open from CSS), and the
shared `tok-*` highlighter markers that `ThemeAssetCompletenessTest` looks
for.

**Auth and documentation surfaces.** Boson also implements the two optional
theme contracts: `Themes\AuthPageTheme` renders login, registration,
forgot-password and reset pages inside the site chrome (a `bo-auth` panel
with `bo-field` inputs, placed by `AuthTemplate`), and
`Themes\DocsPageTheme` renders `/docs` as a ruled guide grid and `/docs/…`
as a prose article with a back link. Both are optional by design: a theme
that does not implement them keeps the shared standalone auth page and the
generic `{prefix}-section` docs markup, unchanged.

Measured 2026-10-17 on the installed tree (`module:add Boson`, catalogue 21,
installed 21 + `KernelTheme`):

- `phpunit` 513 tests / 4 836 assertions, 1 skipped; `phpstan`, Rector dry-run
  and CS fixer dry-run green (the module itself is outside the kernel
  analyzer's 117-file scope, as every module is);
- served `/`, `/blog`, `/blog/tags`, `/blog/categories` and
  `/blog/<slug>` all **200** in `boson-shell` with the stylesheet inlined and
  the behaviour script after `</main>`;
- Chromium over CDP: body `rgb(13, 17, 25)`, hero line `rgb(249, 57, 4)`,
  120 px display type, header 100 px → 70 px with `bo-header--scrolled` after
  a scroll, accordion item 3 opens and item 1 closes with `aria-expanded`
  synced, the phone menu opens from the checkbox, locks scroll, closes on
  Escape, and horizontal overflow is **0** at 390 px;
- article page: 39 `tok-*` spans, 2 copy buttons, 2 language badges, prose
  `pre` background `rgb(15, 19, 28)`, contents rail open with its scrollspy
  marking the current heading; zero console errors.

The three ownership ratchets moved for the new module, not for a duplicate:
literal places 496 → **507** (three `boson` contract spellings and eight
`Blog`/`en` labels), declarations 238 → **242** (`DEFAULT_COPY`, `WORKS`,
and the test's `MODULE`/`THEME`), names 202 → **203** (`WORKS` is the only new
name). The reasons are recorded beside each ceiling in
`NameOwnershipContractTest`.

## Scheduler + Queue (measured 2026-10-13)

`modules/Scheduler/` is the cron half of the Marko scheduler/queue pair,
ported to zero dependencies: `CronExpression` (5-field matching),
`ScheduledTask` fluents (`everyFiveMinutes()`, `hourly()`, `cron()`, …),
`Schedule::call()` + `dueTasksAt()`, a `flock` `FileTaskMutex` with
reclaimable expiry under `storage/cache/scheduler-mutexes/`, and a
`ScheduleRunner` that counts executed/failed/skipped — a throwing task is
reported, never stops the rest. `modules/Queue/` is the SQLite half:
`jobs` + `failed_jobs` in `queue.db`, `DatabaseQueue` reserving under
`BEGIN IMMEDIATE` with a guarded `UPDATE` (SQLite has no `SKIP LOCKED` —
the second contender's update matches zero rows instead), `attempts`
authoritative, exhausted jobs parked in `failed_jobs` with the error.
Unknown handlers and corrupt payloads fail the job, never fatal the
worker.

The operator surface is `php bin/cms schedule:run` — the cron entry
(`* * * * * php bin/cms schedule:run`) that replaces a long-running
worker. The shipped timetable (`SchedulerServices::schedule()`) mirrors
`outbox:process`: `outbox:drain` every five minutes, `outbox:prune` daily
(processed rows older than seven days). Per-task lines plus the summary go
to stdout; the exit code is 1 on any failure else 0. The Outbox entries
register only when Outbox is installed (`class_exists`, not a hard edge —
the scan still reports the closure, which is the honest dependency).

- `composer test` 244 / `phpstan` / Rector dry-run / `cs-fix-dry` green;
- `diff -rq` catalogue vs installed clean for both slices;
- `module:remove` + `module:add` round-trip byte-identical (Queue
  `2c90b4b7…`, Scheduler `81c3f87f…` before and after);
- `schedule:run` live twice in a row: nothing due, exit 0 both times; the
due path driven at a fixed 13:00 against the real outbox.db —
`Executed: outbox:drain`, exit 0.

## The queue's worker > `bin/cms queue:work` (measured 2026-10-16)

`DatabaseQueue::work()` shipped with exactly one call site in the tree: an
inline `php` heredoc inside `bin/build/admin-smoke.sh`. The queue therefore had
a worker that

- no operator could put in crontab (it was not a command),
- no support answer could point at (there was nothing to point at), and
- no documentation could describe (there was nothing to describe).

The gap was recorded as a *producer* problem — "nothing enqueues a job yet",
which is true — and that hid a *surface* problem one level down, which a
producer landing would not have fixed. So the worker got the surface it should
have had from the start:

```
php bin/cms queue:work [<name>] [--limit=N]     # default: any queue, 10 jobs
```

- **Refuses** with exit 1 when the Queue module is not installed, the same
  shape as `schedule:run`'s refusal;
- **wires the handler pairings first** — `wire_module_handlers()` from
  `src/composition.php`. A worker that delivers without wiring finds an empty
  handler map and parks every job as unroutable, which is the Boot 16 lesson
  reproduced in a new process;
- prints `Queue: processed=N failed=M (queue: …, limit …)` and, when anything
  is parked, where to deal with it (`/mark/queue`);
- **exits 1 when anything is parked in `failed_jobs`, else 0** — the same
  contract `outbox:process` states, so a cron entry or a CI probe notices a
  poison job instead of silently rotating past it. The semantics live on
  `QueueServices::work()` / `::exitCode()`, exactly as `schedule:run`'s live on
  `SchedulerServices::run()`; the CLI only prints, and the module test asserts
  what the command does.

It also joins the timetable, registered by
`SchedulerServices::withQueueWork()` — which is where the first attempt could
NOT go. Wiring it in `bin/cms` reads better (the kernel owns the composition)
and fails the kernel-only PHPStan gate outright:

```
Parameter $schedule of function withQueueWork() has invalid type
  Modules\Scheduler\Scheduling\Schedule.      🪪 class.notFound
Call to method call() on an unknown class
  Modules\Scheduler\Scheduling\Schedule.      🪪 class.notFound
```

`bin/cms` is analysed in a tree with no module installed, and annotating that
parameter — or calling a method on the value — resolves regardless of the
guard. In the module the cost to the closure scan is zero, because the scan
counts `use Modules\…` lines only and `QueueServices` is named inline and never
imported. Measured: `module:show Scheduler` → `closure: Cardboard, Outbox` —
**Queue absent**.

| | |
|---|---|
| entry | `queue:work`, every minute, `withoutOverlapping(5)` |
| where | `SchedulerServices::withQueueWork($schedule, $report)`, called by `schedule:run` |
| the skip line | a `callable(string): void`, so the module prints nothing and `bin/cms`'s `out()` does |
| skipped when | the queue's database has no `jobs` table — one line quoting the REAL path (`DB_QUEUE` when the deployment set one), because a cron that fires before the first migrate would otherwise fail a task every minute and redden every run for a reason the failure line does not carry |

Either surface works: an operator who wants the queue drained by one cron line
gets it from `schedule:run`, and one who wants it isolated runs `queue:work`
on its own line.

### The retry backoff (Boot 32)

Measured while writing the command, and then fixed:

```
$ php bin/cms queue:work            # a handler that throws, max_attempts 3
Queue: processed=0 failed=1 (queue: (any), limit 10)
                                   # the row is parked with attempts = 3
```

One `work()` call, three attempts. `work()` caught the throw and released the
row with no delay, which writes `available_at = now` — so the next iteration of
the *same* loop found it again. `max_attempts` bounded *retries*, not *minutes*,
and a handler that would have succeeded on the next cron tick had already been
parked.

`release()` always took a `$delaySeconds`; nothing passed one. It does now:

| attempt | delay |
|---|---|
| 1 | 30s |
| 2 | 60s |
| 3 | 120s |
| … | doubling |
| capped | 3600s |

The formula is Media's (`BASE_DELAY_MS * (2 ** ($attempt - 1))`), so the tree's
two retry paths read alike. **The cap is not optional**: `max_attempts` has no
upper bound — a producer sets it per job — so an uncapped `2 ** n` would
schedule a row years out and quietly turn a poison handler into something nobody
looks at again.

The visible consequence is that a worker which will retry now exits **0**, and
one that has parked something exits **1**. That distinction is the contract
`outbox:process` already stated, and before the backoff a single retry could
make a queue:work run red — training an operator to ignore the number that
means something.

## The queue's retention > `bin/cms queue:prune` (measured 2026-10-16)

`failed_jobs` had no retention of any kind. Measured before changing anything:
four parked rows spanning two hours to a year, and every one of them survived a
worker run — `DatabaseQueue` had **no method that pruned, cleaned, purged or
expired anything**. The only way a row ever left that table was an operator
clicking "drop this job" in the panel, so the table could only ever grow.

```bash
php bin/cms queue:prune [--interval="30 days"] [--dry-run]
```

**It is a separate command on purpose, and that is a deliberate deviation from
the Outbox precedent.** `outbox:process` drains *and* prunes processed messages
in one run. `failed_jobs` is not the same thing: a parked row is the only record
that a job failed **and why**, and the panel puts "retry" and "drop" on it. It
is a work queue, not a log. Pruning it as a side effect of a cron entry would
remove evidence nobody had looked at yet, and the run that did it would report
success. So retention is something an operator asks for **by name**.

The SQL is deliberately the same shape as `SqliteOutboxRepository::cleanProcessed()`
so the tree's two retention stories read alike — what differs is what is being
retained, not how.

| | |
|---|---|
| default interval | 30 days — long enough to diagnose a failure reported this month, bounded enough that the table is not a second log |
| `--dry-run` | reports what **would** go and deletes nothing; the count runs the same comparison the DELETE does, so it cannot flatter itself |
| empty interval | refused (exit 1) — SQLite would otherwise compare against `now` and remove every row |
| scheduled? | **no** — an unattended process emptying an operator's work queue is the thing this avoids |

A worker run never prunes. `workingTheQueueNeverPrunesAFailedJob()` says so, and
the gate asserts the command through `bin/cms` rather than the service: if the
dispatch case went away, every module test would still pass.

## The Clickhouse bridge > `bin/cms clickhouse:*` (2026-10-28)

`modules/Clickhouse/` is a catalogue module: `php bin/cms module:add Clickhouse`
installs the slice, and nothing in the kernel names it. The module has two
halves — a zero-dependency HTTP client (`curl` behind a `ClickHouseTransport`
interface) and a bridge that turns the kernel's SQLite files into ClickHouse
databases through the documented `CREATE DATABASE … ENGINE = SQLite('<path>')`.

**Catalogue vs slice, as everywhere else.** The source is
`modules/Clickhouse/`; `src/Modules/Clickhouse/` is the installed copy the
running process loads, written by `module:add` and removed by `module:remove`.
The module imports `Modules\Core\*` only, and `module:show Clickhouse` prints
that closed set.

**The path is resolved on the ClickHouse host.** `attach` writes an absolute
path into the DDL, but the server is what opens the file — so `storage/data/`
has to be reachable there under the same spelling, usually through a bind
mount. Two server-side rules were measured on 26.11.1.973 and neither is on
the doc page: the file must live inside ClickHouse's `user_files`
directory (otherwise `PATH_ACCESS_DENIED`), and a relative path is accepted and
stored as written, which is why `attach` refuses one. Filesystem permissions
are the engine's only access control. `status` reads the path back from
`SHOW CREATE DATABASE` and prints a drift line when it differs from the local
file; `system.databases.engine_full` is empty for these databases, so
`SHOW CREATE` is the only source.

**Read-only by default.** `clickhouse:query` accepts `SELECT`, `WITH`, `SHOW`,
`DESCRIBE` and `EXPLAIN`; anything else needs `--write`, which prints the
whole-file-lock warning first. The reason is not politeness: the kernel's own
connections use WAL and a second writer would be a second journal owner on a
file the site is actively serving.

**Flags that change behaviour.** `--dry-run` prints the DDL and sends nothing
(it works on a machine with no server). `--force` drops and re-creates a
database, because ClickHouse cannot re-point an engine; the SQLite file is
untouched. `--checkpoint` runs `PRAGMA wal_checkpoint(TRUNCATE)` through a
own connection before attaching, and without it a non-empty `<file>-wal` is
reported rather than ignored — although **measured on 26.11.1.973, ClickHouse
reads the WAL pages through SQLite**, so the warning is about file-level
copies, not about the attached database. `--typed` writes explicit
`CREATE TABLE` definitions for tables whose declarations name a type inference
cannot express (`DATETIME`, `UUID`, `DECIMAL(…)`, `FixedString(…)`, the integer
widths), with one warning per typed column. An explicit table cannot be created
inside the attached database — the engine already exposes every table
implicitly, so it answers `TABLE_ALREADY_EXISTS` and does not implement `DROP
TABLE` — so it is created in the server's default database as
`<prefix><name>__<table>` (`nativa_blog__article_views`), and that is the typed
read path. A value that does not parse is rejected when read
(`CANNOT_PARSE_DATETIME` for `''` as `DateTime`), not coerced.

**No CI job needs a server.** The transport is the seam: the module's tests
run against a recording implementation, so `composer test` covers the commands
and the DDL without a network. The server-side facts are a command instead of a
job: `make clickhouse-smoke` (`bin/build/clickhouse-smoke.sh`) starts a
throwaway server from `CLICKHOUSE_BIN`, or uses an existing one through
`CLICKHOUSE_URL` + `CLICKHOUSE_USER_FILES`, and asserts attach, the status
read-back, a read with a live uncheckpointed WAL, a second writer refused while
a kernel transaction holds the lock, the `PATH_ACCESS_DENIED` refusal,
`--typed` and the write gate. CI runs it as an optional, non-blocking job, with
the release pinned and cached in `toolchain.json`.

**Removal has nothing to report.** The module creates no file under
`storage/`: the objects it creates live inside ClickHouse, and
`module:remove Clickhouse` therefore prints "no leftover data".

The server-side behaviour of the engine itself — whether `SHOW CREATE
DATABASE` is stable enough to parse on the target build, how `''` parses into a
`DateTime`, and what `INSERT` does on a WAL file — was measured on 26.11.1.973
and is recorded in `docs/kernel-status/09-clickhouse-sqlite-bridge.md`;
`make clickhouse-smoke` re-runs the parts a fixture can hold.

## Dashboard stat cards for Queue / Outbox / Schedule (measured 2026-10-13)

Three `#[AsMetricProvider]` cards on `GET /mark`, reusing the metric
subsystem untouched (Blog/Audit precedent — `MetricRegistry` discovers
them, `DashboardAction` renders them): `QueueMetric` (reservable jobs,
reserved/failed subtitle, pushed-in-period trend), `OutboxMetric`
(pending+processing needing attention, processed/failed subtitle,
processed trend), `ScheduleMetric` (due-now entries, overlap-held locks,
no trend — the timetable is configuration, not history). No action links
(no list pages to point at) and default color only (no shipped provider
sets custom colors). A stats DB that is missing or unmigrated skips its
card silently — the dashboard never 500s on a metric.

Testability without breaking the no-required-ctor-params contract: each
provider takes an optional `?PDO` (default: its module connection),
`ScheduleMetric` takes an optional `Schedule` + `FileTaskMutex`
(default: shipped timetable + site mutex dir) — tests seed throwaway
SQLite or synthetic timetables, never real closures or `storage/`.

- `composer test` 250 / `phpstan` / Rector dry-run / `cs-fix-dry` green;
- live discovery on the real tree: 10 providers, all three cards render
  in 7d and 30d (`queue=0`, `outbox=0 [6 processed · 0 failed]`,
  `schedule=0 [2 entries]`), empty-DB run registers nothing, no fatal.

## Queue + Outbox admin pages (measured 2026-10-13)

`/mark/queue` (jobs newest-first with `?queue=` filter, failed table
below, job detail with pretty JSON args) and `/mark/outbox` (status
filter, message detail with payload/metadata/error) mirror
`CommentsAdminAction`: `m-table` + `AdminLayout::e()` escaping,
redirect-back POSTs, per-action `AuditServices::log` rows. Failed jobs
re-queue under their own id with attempts reset (`retryFailed`) or drop
(`deleteFailed`); missing ids redirect without an audit row. Behind the
pages: `QueueServices` locator and `DatabaseQueue` read API (`listJobs`
with derived reserved/delayed/pending state, `findJob`, `listFailed`,
`findFailed`) plus `SqliteOutboxRepository::listMessages/countTotal` —
covered on throwaway SQLite, never the site DBs. The dashboard cards
grew `View Jobs` / `View Messages` links once the pages existed.

- `composer test` 256 / `phpstan` / Rector dry-run / `cs-fix-dry` green;
- live over `php -S`: both lists 200 (empty + seeded), details 200 with
  JSON args, unknown ids 404, POST without token 403, retry with token
  302 (failed→pending, attempts 0), delete 302; seeded rows removed
  after the pass.
- `/mark/schedule` (read-only timetable: entry, cron expression,
  due-now badge, overlap locks + the `schedule:run` cron hint) renders
  200 with both shipped entries; the Schedule card links to it.
