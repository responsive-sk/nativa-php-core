# Contributing via pull request

Short version: branch off `master`, keep five local gates green, open the PR.
If those five pass locally, CI passes too.

## Once per clone

```sh
git clone https://github.com/responsive-sk/nativa-php-core.git
cd nativa-php-core
cp bin/git-hooks/commit-msg .git/hooks/commit-msg
composer install
```

The hook enforces the repo convention of **no agent footer** in commit
messages (`.git/hooks` is not versioned, so every fresh clone needs the copy).
`composer install` is for dev tooling only — the runtime needs no `vendor/`.

## Everyday loop

```sh
git checkout -b feat/short-name
# ... changes ...
make lint && composer phpstan
composer test
composer cs-fix-dry && composer rector-dry
git commit
git push -u origin feat/short-name
```

Describe **what** and **why** in the PR, not how — the diff already says how.

## What CI checks

1. **Lint + PHPStan** (`make lint`, `composer phpstan`)
2. **Code style** (`cs-fix-dry`, `rector-dry`)
3. **PHPUnit** (`composer test`), including catalogue tests under
   `modules/*/tests/` — a module test **skips** when its module is not
   installed, it never fails (guard `tearDown()` too: PHPUnit runs it even
   after a skipped `setUp()`; see `TaxonomyRegistryTest`)
4. **Assets** — `Frontend/packages` must build, the manifest must carry
   `mark` / `mark-switch` / `moonshine`, no `@layer` in built CSS
5. **Boot without vendor/** — the kernel boots with no `composer install`

## Boundaries most often broken

- **Catalogue vs. installation.** The source is `modules/<Name>/`;
  `src/Modules/<Name>` is gitignored operator state — re-sync after editing
  a module or you test stale code.
- **Kernel boundary.** A kernel file may not `use Modules\<X>\…`; module
  dependencies come from `use` lines (`module:show X` → `closure:` must stay
  acyclic). Exceptions go FQ-inline with a docblock reason, never silently.
- **Internal tooling** (`.agents/`, `.ai-factory.json`) stays out — never in
  a PR.
- Larger changes earn an entry in the boot log: a part under
  `docs/kernel-status/` (the newest one while it is still the current round of
  work — and its row in `docs/kernel-status.md`, the index). A boot record of
  measured states, not a plan.
