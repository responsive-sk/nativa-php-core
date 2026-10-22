# Docs freshness — draft experiment, NOT published

> DRAFT. This file is the Task 0 experiment of
> `.ai-factory/plans/feature-docs-prose-structure.md` and is deliberately NOT
> listed in `DocsCatalog::PUBLISHED`, so `/docs` never serves it. It is read by
> an agent (`prose run`), not by the site.

---
kind: responsibility
---

## Goal

The `/docs` surface tells the truth: every published guide has a file, every
file it names exists, and nothing unpublished is reachable through it.

## Maintains

Fields held true by this node (material: a change re-runs the check):

- `published_have_files`: every key of `DocsCatalog::PUBLISHED` resolves to an
  existing file under `docs/` via `DocsCatalog::filePath()`. A key without a
  file is red — the map is skipped silently (`entries()` drops it), so only
  this fact keeps the map honest.
- `clanky_stay_out`: no key under `docs/clanky/` is in `PUBLISHED`. The archive
  is the Blog's past (`storage/blog/` is the source of truth); the two clanky
  without a blog pendant (model-bez-vazby, posledna-evolucia) migrate through
  the article flow, never through the map.
- `links_resolve`: every relative `.md` link inside a published guide rewrites
  to a published `/docs/…` URL or is left untouched (`rewriteLinks()`). A link
  that 404s somewhere new is worse than a link that plainly names a file.
- `frontmatter_parses`: every `storage/pages/` file parses through
  `PageFileStorage` (`title`, `published`, `translation:`, `aliases:` flow
  list). Tolerant reading (comma-scalar aliases) is allowed; strict writing is
  required.

## Requires

Nothing. This node reads the working tree as text (the way `KernelCouplingTest`
reads `use` lines and `ModelPayloadContractTest` reads the model) and loads no
module class, so it holds on a kernel-only checkout too.

## Continuity

Input-driven: re-run on demand (`prose run docs/prose/docs-freshness.prose.md`)
after any change to `DocsCatalog::PUBLISHED`, `docs/`, `storage/blog/` or
`storage/pages/`. No daemon, no schedule — the CI gate (`DocsCatalogContractTest`,
plan Task 6) is the enforced half; this contract is the authored half used to
judge whether the test checks the right things.

## Evaluated against (2026-10-22)

| fact | contract | `DocsCatalogContractTest` (plan Task 6) |
|---|---|---|
| `published_have_files` | declares | asserts (every PUBLISHED key has a file) |
| `clanky_stay_out` | declares | asserts (archive never in map + pendant check) |
| `links_resolve` | declares | asserts (only published `.md` rewritten) |
| `frontmatter_parses` | declares | nobody yet — candidate for a pages-side test |

Verdict so far: keep Lekalo for the semantic model, keep this file as the
authored checklist. Revisit only if a fact here stays unchecked for two rounds.
