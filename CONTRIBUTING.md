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

## Slovak loanwords in articles (rod a tvar)

The article corpus under `storage/blog/` is Slovak, and an English technical
term is fine — but it has **one gender and one set of forms** across the
articles. This table is that ledger. A word missing here gets its row in the
same PR that introduces it, not a second variant in the prose.

| slovo | rod | tvary | poznámka |
|---|---|---|---|
| **build** | m. | ten build · z buildu · dva buildy | nie „builda“ (ž.) |
| **deploy** | m. | ten deploy · z deploya · dva deploye | v próze radšej „nasadenie“ |
| **release** | m. | ten release · z releasu · dva releasy | v próze radšej „vydanie“ |
| **feature** | ž. | tá feature · z feature · dve feature | nesklonné; v próze „funkcia“/„vlastnosť“ |
| **commit** | m. | ten commit · z commitu · dva commity | |
| **session** | ž. | tá session · z tej session · dve session | nesklonné |
| **cache** | ž. | tá cache · z cache · dve cache | nesklonné |
| **workspace** | m. | ten workspace · z workspace · dva workspace | nesklonné |
| **worktree** | m. | ten worktree · z worktree · dva worktree | nesklonné |
| **pane** | m. | ten pane · z panu · dva pany | terminálové okno v Herdr; gen. pl. `panov`, lok. pl. `panoch` — nie „to pane“ (n.) |
| **checkout** | m. | ten checkout · z checkoutu · dva checkouty | |
| **request** | m. | ten request · z requestu · dva requesty | |
| **handler** | m. | ten handler · z handlera · dva handlery | |
| **payload** | m. | ten payload · z payloadu · dva payloady | |
| **test** | m. | ten test · z testu · dva testy | |
| **log** | m. | ten log · z logu · dva logy | |

Where the prose already has a Slovak word (`fronta` for queue, `nasadenie`
for deploy), use it; the English term belongs in code and in the sentence that
names the code. A term that appears once with each gender is a content bug,
not a style choice — fix the article rather than widening this table.

## Boundaries most often broken

- **Catalogue vs. installation.** The source is `modules/<Name>/`;
  `src/Modules/<Name>` is gitignored operator state — re-sync after editing
  a module or you test stale code.
- **Themes are edited in `nativa-themes`.** `modules/Themes/<Name>/` here is
  that repository's copy (the catalogue `module:add` installs), not a source:
  change the theme there, run `make themes-sync`, then
  `php bin/cms module:add <Name>`. `make themes-check` fails on a hand edit
  here, and its message names the file.
- **Kernel boundary.** A kernel file may not `use Modules\<X>\…`; module
  dependencies come from `use` lines (`module:show X` → `closure:` must stay
  acyclic). Exceptions go FQ-inline with a docblock reason, never silently.
- **Internal tooling** (`.agents/`, `.ai-factory.json`) stays out — never in
  a PR.
- Larger changes earn an entry in the boot log: a part under
  `docs/kernel-status/` (the newest one while it is still the current round of
  work — and its row in `docs/kernel-status.md`, the index). A boot record of
  measured states, not a plan.
