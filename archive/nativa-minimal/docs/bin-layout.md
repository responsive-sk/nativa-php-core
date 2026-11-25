# The `bin/` layout

`bin/` holds every script this repository runs against itself: the CLI, the
browser checks, the generators, the dist builder, the dev server, the agent
tooling and the trace generator. It was 43 files in one flat directory —
sorted alphabetically, indistinguishable from one another, with nothing in the
tree stating which files belonged together. This document is that statement,
and `tests/Architecture/BinLayoutTest.php` enforces it.

## One role per directory

| Directory | Files | Role | What lives there |
|---|---|---|---|
| `bin/` | 1 | the one CLI entry the docs and the `src` docblocks name | `cms` |
| `bin/checks/` | 18 | verifies the **running** app — a browser over CDP, or plain HTTP | `*-check.mjs` |
| `bin/generate/` | 4 | generates a derived stylesheet, or stamps a theme base | `generate-{form,comments,questions}-css.mjs`, `stamp-theme-base.mjs` |
| `bin/build/` | 4 | builds or verifies the dist artifact | `build-dist.sh`, `build-install.php`, `verify-build.php`, `smoke-dist.php` |
| `bin/dev/` | 4 | sets up and runs a local instance | `dev-server-router.php`, `set-theme.php`, `db-reset.php`, `generate-all.php` |
| `bin/ops/` | 7 | operator and agent tooling, invoked through `make` | `gate-run.php`, `revision-report.php`, `lekalo-reports.php`, `audit-rhythm.php`, `rhythm-diagram.php`, `tempo-analyzer.php`, `series-publish-check.sh` |
| `bin/lib/` | 7 | shared helpers — imported by the others, never executed | `cdp.mjs`, `http.mjs`, `sqlite.mjs`, `repo-root.mjs`, `repo-root.php`, `repo-root.sh`, `RevisionState.php` |
| `bin/trace/` | 2 + `mappings/` | the trace-manifest generator (Python, not PHP) | `build.py`, `builder.py`, and the authored `mappings/*.json` |

`checks/` is deliberately flat. Fourteen of the eighteen drive a browser and
four are plain HTTP, but splitting them (`checks/http/`) would move a file the
day a check grows a browser leg — churn in the one directory whose whole point
is that a check is additive.

## Classification: how each file is actually invoked

A mention is not an invocation. Each row below was decided by looking for a
caller, not by reading the name:

| Verdict | Files | Evidence |
|---|---|---|
| CLI entry | `cms` | `Makefile`, `composer.json`, the `src/Modules/**` docblocks |
| CI-gated | 18 `checks/*` | `.github/workflows/ci.yml` (48 references), asserted per script by `CheckScriptsWiredTest` |
| Build flow | `build/*` | `Makefile` (`dist`, `dist-smoke`), `composer.json` (`verify-build`), and `build-dist.sh` calls `smoke-dist.php` itself |
| Dev flow | `dev/*` | `Dockerfile` `CMD`, `phpstan.neon` (analysed paths), `Makefile` |
| Wired no-op | `dev/generate-all.php` | `composer.json` still calls it; it is a documented no-op and is kept so the script name in the docs keeps working |
| CI report | `ops/lekalo-reports.php` | `Makefile` (`lekalo-reports`) and `.github/workflows/ci.yml` (the `lekalo` job appends its markdown to `$GITHUB_STEP_SUMMARY`); advisory by design, so the step is not wrapped in `\|\| true` — an unreadable report exits 1 |
| Manual tooling | `generate/stamp-theme-base.mjs`, `ops/{audit-rhythm,rhythm-diagram,tempo-analyzer}.php`, `ops/series-publish-check.sh` | **zero** invocation in `Makefile`/`ci.yml`/`composer.json`/`phpstan.neon`/`Dockerfile` — run by hand, kept for that reason |
| Library | `lib/*` | imported, never executed |

The only committed artifact found uninvoked was
`bin/trace/__pycache__/builder.cpython-314.pyc` — Python bytecode, now
untracked and gitignored (`__pycache__/`, `*.pyc`).

## If you move a script, these are the files that change with it

| Consumer | refs | Why it breaks |
|---|---|---|
| `.github/workflows/ci.yml` | 48 | every `node bin/checks/<name>-check.mjs` step |
| `AGENTS.md` | 57 | the Key Entry Points table and every "run `node bin/…`" instruction |
| `.ai-factory/rules/*`, `skill-context/*`, `DESCRIPTION.md`, `RULES.md` | 32 files | the agent-facing copies of the same paths |
| `docs/*.md` (`getting-started`, `template-map`, `blog-covers`, `questions`, `forms`, `security-*`, `theme-layout`, `lekalo-model`) | ~30 | the reader-facing instructions |
| `Makefile` | 16 | `cms`, `db-reset`, `verify-build`, `build-dist.sh`, `smoke-dist`, `trace/build.py`, `revision-report`, `gate-run`, `lekalo-reports.php` |
| `src/Modules/**` | 12 files | CLI hints in docblocks (`bin/cms seed`, the router path in `ServeCommand`) |
| `README.md` | 10 | quick start |
| `tests/Architecture/*` | 9 files | the guards that glob `bin/` and the parity messages that say "rerun `node bin/generate/…`" |
| `tests/Unit/RevisionStateTest.php` | 6 | a hard `require_once … /bin/lib/RevisionState.php` — a miss is fatal, not a warning |
| `composer.json` | 5 | `migrate`, `seed`, `db-reset`, `verify-build`, `generate-all` |
| `phpstan.neon` | 2 | the analysed-path list |
| `Dockerfile` | 1 | `CMD` — a wrong router path is a container that starts and serves 404s |
| `public/index.php` | 1 | a comment naming a path |
| `docs/lekalo/trace/*.trace.json` | — | nothing here names a `bin/` path; the **`Makefile` digest is pinned**, so editing the Makefile requires `make trace-build` |

`openspec/specs/starter-kit/spec.md` names four of these paths and is updated
through the OpenSpec change flow, never by hand.

### The trap that cost the most

**Sixteen scripts** resolved the repository root by counting levels up — in
three different languages, all with the same idiom:

```js
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')  // .mjs
```
```php
$root = dirname(__DIR__);                                            // .php
```
```sh
cd "$(dirname "$0")/.."                                              // .sh
```

Moving them one level deeper turned that `..` from the repo root into `bin/`.
The CSS generator then tried to read
`bin/Frontend/packages/starter/src/form.css` — and a script that **writes**
(`writeFileSync(resolve(ROOT, file))`) creates a directory tree beside itself
and exits 0 while doing it. `bin/build/build-dist.sh` had the same shape one
step further out: a wrong `PROJECT_DIR` makes `DIST_DIR` point at `bin/dist`,
and `mkdir -p` makes that without complaint, so the build copies from
`bin/src`/`bin/public` and reports success. `bin/ops/revision-report.php`
failed as a fatal `require` of `bin/ops/lib/RevisionState.php`.

Every one of them now resolves the root through a marker, not a depth:
`repoRoot(import.meta.url)` (`bin/lib/repo-root.mjs`),
`binRepoRoot(__DIR__)` (`bin/lib/repo-root.php`), `bin_repo_root <dir>`
(`bin/lib/repo-root.sh`). The marker is **`src/init.php`**, and it is not
`composer.json` for a reason worth keeping: `bin/build/smoke-dist.php` boots
the dist's own `bin/dev/dev-server-router.php`, and the dist ships no Composer
manifest — a `composer.json` marker would find the repository and throw inside
the artifact. This is the same rule the PHP side already follows through
`AppPaths`.

A second silent consumer was `Dockerfile`'s `CMD`: the dev container would have
started and served 404s, with no failing gate to say so.

### The half a root fix does not cover: the import that stopped resolving

Fixing the root left a second breakage in place. Fifteen CDP checks imported
their helper as `'./lib/cdp.mjs'` — correct while `lib/` was their sibling in
`bin/` root, and afterwards a specifier that resolves to
`bin/checks/lib/cdp.mjs`. Node throws `ERR_MODULE_NOT_FOUND`, but everything in
the suite was green: `composer test` executes none of the checks,
`CheckScriptsWiredTest` asserts a script is *listed in CI* (the string, not the
graph) and `CheckScriptsShareCdpTest` asserts a check *uses* the helper. The
first thing to actually run one was a hand invocation of
`bin/checks/toc-scrollspy-check.mjs`.

The guard for it decides nothing by running: `BinLayoutTest` resolves every
static relative reference under `bin/` — an ES `from '../…'`, a PHP
`__DIR__ . '/…'`, a shell `$(dirname "$0")/…` — and fails on the ones that
point at no file. A path is a claim about the tree, and a claim can be checked
without a browser.

The guard for this rule was first written for `.mjs` alone — the scripts the
first pass happened to break — and **ten PHP and shell scripts carried the
identical defect while the suite stayed green**. A rule policed in one
extension is a rule policed in one extension.

### One session, one database gate

The same consolidation `bin/lib/cdp.mjs` represents had not happened one layer
down. Three checks — `forms-check`, `forms-admin-check`, `author-check` — each
carried a byte-identical `Visitor`: the cookie jar over `getSetCookie`, the
`redirect: 'manual'` fetch, the `token()` regex, the login POST. They had
already drifted, which is what makes it more than a style question: **only the
forms-admin copy returned `bytes` and `headers`**, so the CSV export assertion
could read the Excel BOM as bytes while the other two decoded the body to text
and would have had to grow that capability a second time.

Two checks also each built their own `php -r` + PDO invocation against a module
database. Both now go through `bin/lib/sqlite.mjs`, which resolves the storage
layout through the app's own `AppPaths`, binds values instead of interpolating
them, and owns the TEXT-vs-number rule (`'10' > '9'` is false — the audit count
was green taking 8 → 9 and red taking the same count 9 → 10).

Ownership is `bin/lib/http.mjs` (session, CSRF token, `signIn`, `decodeToast`)
and `bin/lib/sqlite.mjs` (`sqlScalar`, `sqlNumber`, `sqlColumn`), and
`CheckScriptsShareHttpTest` is the enforcement: no other script may keep a
cookie jar, follow its own redirect policy off Node, open a module database, or
hand-roll the login flow. The CDP checks' in-page `fetch`
(`credentials: 'same-origin'`) is explicitly out of scope — that is the
browser's own client being driven through the page, not how Node talks to the
server.

## The guards

- **`tests/Architecture/BinLayoutTest.php`** — the map above, as code. A file
  in the wrong directory fails naming the role it does not fit; an undeclared
  directory fails with "put it in `<dir>` or extend the map"; a mapped
  directory that is missing or empty fails too, because a row nobody honours is
  a comment; and no `.mjs`, `.php`, `.sh` or `bin/cms` may derive the repo root
  from its own depth — the idiom check runs on **tokens**, not raw text, so the
  resolver can document the trap it exists to close. Mutations were planted to
  prove each rule fails (stray script, undeclared directory, depth-counted root,
  unimported `repoRoot()`, a removed `source` line, a relative import reverted to
  its pre-move form, plus the check-script rules below) — the first version of
  its directory walk was **vacuous**, which the mutation caught and nothing else
  would have.
- **`CheckScriptsWiredTest`** — every `*-check.mjs` under `bin/` is discovered
  from disk, and each one must appear in both `ci.yml` and `AGENTS.md`; the
  reverse direction asserts that neither names a script that no longer exists.
  The provider walks the tree rather than globbing `bin/*-check.mjs`, because a
  depth-1 glob that stops matching leaves the provider empty and the rule
  silently covers zero scripts.
- **`CheckScriptsShareCdpTest`** — no `bin/` script pins a literal debugging
  port, opens its own WebSocket, or resolves its own Chrome binary; all three
  belong to `bin/lib/cdp.mjs`.
- **`CheckScriptsShareHttpTest`** — the same rule for the layer below: one
  cookie jar, one redirect policy, one database gate, one login flow, all in
  `bin/lib/http.mjs` / `bin/lib/sqlite.mjs`. It also asserts each helper has at
  least two consumers, because a rule that is satisfied by deleting the helper
  is not a rule. Three mutations were planted (a hand-rolled jar, a `new PDO(`
  in a check, an inlined login) and all three fail it.
