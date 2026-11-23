# AGENTS.md — how to work in this tree

Instructions for coding agents, and a useful map for a developer on their first
day. The rule this repository is built on is the one its gates enforce: **every
fact has one owner**, and a second copy of a fact is a defect rather than
documentation. So this file carries **no tool versions, no gate list of its own
and no CI job list** — those live in `toolchain.json`, and `make gates` prints
them. A version spelled here would be a copy that nothing checks.

`README.md` says what this tree is and why it exists. `CONTRIBUTING.md` has the
human PR loop and the Slovak glossary for articles. `docs/kernel-status.md` is
the measured state of the boot. Read this file before changing anything.

## What this tree is

- **Kernel-only.** Four slices travel whole: `Modules\Core`,
  `Modules\Database`, the module tooling (`src/Modules/Tooling`) and the
  kernel's own default presentation (`src/Modules/KernelTheme`). Every other
  surface is a module.
- **Catalogue vs. installation.** The source is `modules/<Name>/` — themes live
  under `modules/Themes/<Name>/`. The installed copy is `src/Modules/<Name>`
  (theme modules arrive under a `Themes/` group there): gitignored operator
  state, written by `php bin/cms module:add <Name>` and provably removed by
  `module:remove`.
- **The runtime has no Composer packages.** `composer.json` requires PHP and
  its extensions and nothing else; `composer install` is for dev tooling, and
  the tree boots without `vendor/`. A CI job exists to keep that honest.
- **The boundary rule:** a kernel file may not import a module; a module may
  import the kernel. `make mago-guard` fails on a kernel→module edge, inline
  FQCNs included.
- **Themes are authored here.** `modules/Themes/` is the source; the theme
  gallery is a consumer and lives under `themes/gallery/`. Edit a theme in
  place, then re-install the slice:
  `php bin/cms module:remove <Name> --force && php bin/cms module:add <Name>`.
- **The generated trees are exports.** `responsive-sk/nativa-core` (public)
  and `responsive-sk/nativa-resp` (private client port) are exported from a
  commit of this one by `bin/build/export-core.sh`; the profiles are
  `bin/export/core/` and `bin/export/resp/`, and `make core-export-check` or
  `make resp-export-check` runs their suites. A change to either tree is made
  here, never there.

## Bootstrap (once per clone)

```sh
git clone https://github.com/responsive-sk/nativa-php-core.git
cd nativa-php-core
cp bin/git-hooks/commit-msg .git/hooks/commit-msg   # strips agent footers
composer install                                    # dev tooling only
make gates                                          # what this tree is checked with
```

`.git/hooks` is not versioned, so copying the hook is part of the clone and not
a nicety: this repository's history carries no agent footer, and the hook
deletes one before the commit is written.

Then only what you need:

| tool | how it arrives |
|---|---|
| PHPStan, Rector, php-cs-fixer, PHPUnit | `composer install` |
| pnpm, theme bundles | `make assets` (or `make assets-import` to copy a built `public/dist`) |
| mago | nothing to install: `make mago-guard` / `make mago-lint` fetch the pinned binary and cache it under `storage/cache/` |
| lekalo CLI | install the pinned release once, below |

**Pins are read from `toolchain.json`, never copied into prose or a script.**
The registry is the one owner of a version, a target and a sha256. The two files
that *do* spell a pin — `bin/build/mago-spike.sh` and the lekalo install step in
`.github/workflows/ci.yml` — are asserted against the registry by its `needles`,
so drift fails a test; a version written a third time would be a copy nothing
guards. `make gates` prints every pin, gate and job.

```sh
# The lekalo CLI, exactly as CI installs it — version, target and checksum
# taken from the registry at run time rather than spelled here.
read -r V T S < <(php -r '
  $t = current(array_filter(
      json_decode(file_get_contents("toolchain.json"), true)["tools"],
      static fn (array $x): bool => "lekalo" === $x["name"]));
  echo $t["version"], " ", $t["target"], " ", $t["sha256"];')
a="lekalo-$V-$T.tar.gz"
curl -fsSL -o "/tmp/$a" "https://github.com/ichinya/lekalo/releases/download/$V/$a"
echo "$S  /tmp/$a" | sha256sum -c -
tar xzf "/tmp/$a" -C /tmp
install -m 0755 "/tmp/lekalo-$V-$T/lekalo" "$HOME/.local/bin/lekalo"
```

`make lekalo-check` finds the CLI on `PATH` (else `~/.cargo/bin/lekalo`);
override it per run with `make lekalo-check LEKALO=/path/to/lekalo`.

## Gates — one question each

The list below is a map, not the inventory: `make gates` prints the live one,
generated from the registry, so it cannot go stale.

| command | question |
|---|---|
| `make lint` | does every tracked PHP file parse — kernel and catalogue |
| `composer phpstan` | types, level 9, over the kernel scope |
| `composer cs-fix-dry` · `composer rector-dry` | style, and language rewrites, dry |
| `composer test` | behaviour — `tests/` plus `modules/*/tests/` |
| `make mago-lint` | the curated lint rules, ceiling **zero** |
| `make mago-guard` | does a kernel file name a module |
| `make lekalo-check` | the model: `lock --check`, the whole model, every module |
| `make workspace-audit` | pnpm workspace root at `Frontend/packages`, no monorepo tooling |
| `make security-audit` | no tracked `.env`, no secret-shaped literal in tracked code |
| `make dependency-audit` | no high or critical advisory in either lockfile |
| `make modules-install` | every catalogued module installs in closure order |
| `make admin-smoke` | the panel boots and walks its admin flows over HTTP |
| `make assets` | do the bundles build, and does every manifest name assets that exist |
| `make dist-check` | a clean clone builds a green artifact |

The CI `assets` job asserts more than the target does — the admin manifest still
carries its `mark`, `mark-switch` and `moonshine` entries, the two frozen
manifests carry theirs, and no `@layer` survived the build (Chrome 70 drops the
whole block). Same distinction for `make modules-install`: the job built on it
also demands that every catalogue test class actually ran.

**Before you commit** — the five that catch most of it, and the set
`CONTRIBUTING.md` asks a human for:

```sh
make lint && composer phpstan
composer test
composer cs-fix-dry && composer rector-dry
```

Informational targets answer a question without answering *may this merge?* —
they exit 0 whatever they find: `make mago-spike`, `make mago-modules`,
`make kernel-size`, `make lekalo-verify`, `make lekalo-reports`,
`make lekalo-doctor`, `make impact-report`. Turning one into a gate means
deciding which number fails; do not wire it into CI and leave that implied.

## Rules you will break by reflex

1. **Edited a module? Re-install it before you test.** `module:add` refuses to
   overwrite an installed slice, so the round trip is the way:
   `php bin/cms module:remove <Name> --force && php bin/cms module:add <Name>`.
   Testing the installed copy instead of your edit is how a green suite says
   nothing.
2. **A kernel file may not name a module.** Dependencies of a module come from
   its `use` lines — `php bin/cms module:show <Name>` prints the closure, and it
   must stay acyclic. A real exception is written FQ-inline with a docblock
   reason, never silently.
3. **No new runtime dependency.** `composer.json`'s `require` stays PHP and
   extensions; a package there turns the kernel-only claim into a wish.
4. **A module test skips, it does not fail, when its module is absent.** Guard
   `setUp()` **and** `tearDown()`: PHPUnit runs `tearDown()` even after a
   skipped `setUp()`. The pattern is `modules/Blog/tests/TaxonomyRegistryTest.php`.
5. **Gates, jobs and pins are registry-owned.** Adding a make target, a CI job
   or a version bump means editing `toolchain.json` in the same change;
   `ToolchainRegistryContractTest` fails on drift in either direction. Prefer
   growing an existing job to adding a sixteenth — the job count is a ratchet,
   and the promise that a check needs no new job is checkable.
6. **Names and values have owners.** Do not re-spell a permission name, a theme
   name, a locale code or a status word — import the class that owns it. The
   ledger is `docs/name-ownership.md`; `NameOwnershipContractTest` counts the
   literals and fails on the ceiling.
7. **The specs are the truth for what is being built.** `openspec/specs/` is
   canonical and tracked; `openspec/changes/<change>/` is the working plan for
   work in flight and is local (`.gitignore` records both decisions and why). A
   canonical scenario that has a test names it —
   `- PINS: tests/Path/To/Test.php::method` — or says `- PINS: none — <reason>`
   with the decision written out. `ScenarioTestBindingContractTest` enforces the
   pins and the count, and reads `specs/` only, so a delta's scenarios join the
   count when the change lands. Landing is `openspec archive <change>`: it
   updates the canonical spec, and a delta left behind is a requirement nobody
   shares.
8. **Internal tooling stays out of git:** `.agents/`, `.ai-factory.json`,
   `.ai-factory/{plans,evolutions,skill-context}`, `openspec/changes/`.
   Committed on purpose: `.ai-factory/config.yaml`, `.ai-factory/patches/`,
   `.ai-factory/extensions/`, `openspec/specs/`, `openspec/config.yaml`, and the
   agent files `.codex/` and this one.
9. **Commit messages** are `type(scope): subject` — `feat`, `fix`, `docs`,
   `refactor`, `test`, `chore`, `ci`, `build`, `style`, `perf` — with the
   description in English, and **no agent footer**.
10. **Language.** `storage/blog/` is Slovak and follows the glossary in
    `CONTRIBUTING.md`; `docs/`, the specs, commit messages and this file are
    English. A Czech character in an article fails `SlovakProseContractTest`.
11. **Runtime state is never committed:** `src/Modules/<Name>`,
    `storage/data|logs|cache|sessions|uploads`, `public/dist`. The three frozen
    bundles `griffe`, `mono` and `nativa` are committed on purpose and edited
    only by rebuilding them — `GriffeBuildFreezeTest`, `MonoBuildFreezeTest` and
    `NativaBuildFreezeTest` fail on a hand edit.
12. **Measured, not remembered.** A larger change earns a part under
    `docs/kernel-status/` plus its row in the index: the state each number was
    read in, not a plan.
13. **No em dash in prose.** The banned character is `—`; do not use it to
    separate parts of a sentence. Prose is hard-wrapped at about 76 characters,
    one sentence per line. The separator that once closed a mid-sentence line
    (the responsive.sk `>`) was retired, because the break it marked is a wrap
    and a reader does not need to see it; `docs/prose-rhythm.md` records the
    decision and the measurement.

    ```text
    A surface the kernel must render without any module installed still needs
    an answer: the site name, the active theme, the default locale
    and the navigation menu.
    ```

    The break falls on a clause boundary, never inside one: verb and object stay
    together, a noun keeps its attribute (`je náprotivok pre bežiacu session`),
    and a longer line is preferred to a broken phrase. A sentence that ends
    starts a new line, because the period already ended it:

    ```text
    Na tomto stroji bol doteraz ako okenný manažér XFCE.
    Dnes je to Hyprland 0.56.2, ale XFCE sa neodstránilo,
    jedna session vedľa druhej.
    ```

    No line leaves a preposition, a conjunction or an auxiliary at its end.

    The rhythm itself, the pause that earns a paragraph and the tempo of
    sentence lengths, is written in `docs/prose-rhythm.md`; it is an aid for the
    author, never a gate, and the ported numbers come from `nativa-minimal`.

    A conjunction that joins two clauses (`a`, `aj`, `ale`, `no`, `ani`, `či`,
    `lebo`, `pretože`, `takže`, `však`, `hoci`) starts a line and never ends
    one:

    ```text
    náprotivok pre bežiacu session,
    a v tejto session vracia prázdny výstup.
    ```

    A heading is the one place the glyph is still written, because a heading
    holds one line: `## Časť IV > Title`; the renderer splits it into the prefix
    and the title spans and echoes the `>` it was given. A `>` line carrying
    text is still an ordinary blockquote, and
    `tests/Content/MarkdownBlockParagraphTest.php` pins the sentence break, the
    soft wrap and the quote.

    It applies to everything written here: docs, articles, UI copy, commit
    messages and agent replies, in either language.

## Where the truth lives

| question | file |
|---|---|
| what this tree is, and the boundary rule | `README.md` |
| the PR loop, the Slovak glossary for articles | `CONTRIBUTING.md` |
| the tools, the gates, the pins, the CI jobs | `toolchain.json` · `make gates` |
| how a module is catalogued, installed, removed | `docs/modules.md` |
| the measured boot state, round by round | `docs/kernel-status.md` · `docs/kernel-status/` |
| what the public nativa-core tree carries, and what it drops | `bin/export/core/` · `make core-export-check` |
| what the private nativa-resp tree carries, and what it drops | `bin/export/resp/` · `make resp-export-check` |
| why a rule in `.gitignore` exists | `.gitignore` — every rule carries its reason |
| what is being built, and what a test holds | `openspec/specs/` (shared) · `openspec/changes/` (local plans) |
| why mago's scope and ceilings are what they are | `docs/mago-evaluation.md` |
| which names are owned, and every exemption | `docs/name-ownership.md` |
| the kernel's approved shape | `docs/kernel-shape.md` |

When this file and a gate disagree, the gate is right: these rules are the
reasoning behind it, and the reasoning belongs in the commit message of the
change that a gate's number moves.
