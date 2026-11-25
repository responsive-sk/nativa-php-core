# Repositories > what is authored, what is generated

Five repositories serve this project. One is the source, one is the gallery
publisher, two are generated and one is a read-only showcase. This page states
the direction once, so a change lands in the tree that owns it instead of in
the copy that the next build replaces.

## The map

| repository | direction | what it is |
|---|---|---|
| `responsive-sk/nativa-php-core` | **authored** | the kernel, the module catalogue, the theme modules, the docs, the CI and the build tools |
| `responsive-sk/nativa-core` | generated | the public starting point: an export of a commit of this tree, written by `bin/build/publish-core.sh` |
| `responsive-sk/nativa-resp` | generated | the private client port: the site module set, Boson and the content seed, written by `bin/build/publish-core.sh --profile bin/export/resp` |
| `evan70/nativa-themes` | **authored: the gallery** | the theme modules and the static gallery, published at `https://evan70.github.io/nativa-themes/`; its slices were imported here on 2026-11-18 and the repository was archived the same day, then unarchived on 2026-11-22 to publish the gallery again |
| `evan70/nativa-minimal` | retired | the showcase; its kernel slice is the ancestor of this one, and `archive/nativa-minimal/` keeps the documents this tree cites |

One gallery has one publisher. It is `evan70/nativa-themes`, because that is
the tree holding the gallery as source: the hand-written pages, `scripts/pages.mjs`
and the two checkers that walk it. `themes/gallery/` here is a copy of that
source, and it stopped deploying on 2026-11-22: `gallery-pages.yml` and
`gallery-pages-check.yml` are disabled and the
`https://responsive-sk.github.io/nativa-php-core/` Pages site was removed. Two
publishers of one gallery diverge: measured the same day, the copy here was
already 42 generated pages behind a re-render of the repository.

## How each one gets its bytes

The authored tree is the only place a change is made. From it:

```
make core-export-check                  # the core export assembles, lints and passes its own suite
make core-export CORE_TO=../nativa-core # write the public tree from a commit
make publish-core PUBLISH_ARGS=--dry-run # what a publish would push
make publish-core PUBLISH_ARGS=--yes     # replace and push the public repository
make resp-export-check                  # the client export migrates and passes its own suite
make resp-export RESP_TO=../nativa-resp # write the private client tree
make publish-resp RESP_PUBLISH_ARGS=--dry-run # what the private publish would push
```

`bin/export/core/` and `bin/export/resp/` are the profiles a publish applies:
each carries its prune list with a reason per path, the Makefile and CI the
export ships, and the README and AGENTS.md the tree carries. The export writes
its own marker naming the source commit, so a published tree can always say
where it came from. Nothing in `responsive-sk/nativa-core` or
`responsive-sk/nativa-resp` is edited by hand.

The theme gallery is a consumer: it renders the class names the modules here
already define, and its own `sync:check` compared its `src/` against
`evan70/nativa-minimal` before that repository was retired. It is not part of
the exported tree and not part of
the deployable artifact; both the profile and `bin/build/build-dist.sh` leave
it out.

The showcase is where this kernel came from, not a second kernel source, and it
was retired on 2026-11-25. The byte comparison that measured the
divergence was retired on 2026-11-18
(`docs/kernel-status/28-retiring-the-extract-check.md`): this tree is the kernel
source now, so nothing here is generated from the showcase and no file records
a divergence that no longer has a second side to compare against.

## What follows for a change

- Kernel, catalogue, theme module, docs, CI, build tool: here.
- A file in either generated tree: here, then publish; never there.
- A gallery page or its rendering: the gallery under `themes/gallery/`.
- A showcase page or its content: `evan70/nativa-minimal`.

## Why the map is a page and not a convention

Every direction has a command that fails when it is ignored: a dirty source tree
refuses to publish, an unmatched prune entry fails the export, a hand edit in
the published tree is overwritten by the next publish, and the gallery's
`sync:check` fails when its copy of the frontend sources drifts. Writing the
map down makes the remaining silent failure, a change made in the wrong
repository, visible in review.
