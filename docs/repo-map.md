# Repositories > what is authored, what is generated

Four repositories serve this project, and only one of them is authored. This
page states the direction once, so a change lands in the tree that owns it
instead of in the copy that the next build replaces.

## The map

| repository | direction | what it is |
|---|---|---|
| `responsive-sk/nativa-php-core` | **authored** | the kernel, the module catalogue, the theme modules, the docs, the CI and the build tools |
| `responsive-sk/nativa-core` | generated | the public starting point: an export of a commit of this tree, written by `bin/build/publish-core.sh` |
| `evan70/nativa-themes` | gallery | the static theme gallery, now under `themes/gallery/` here; its module slices were imported on 2026-11-18 |
| `evan70/nativa-minimal` | showcase | a full site, private; its kernel slice is the ancestor of this one, not a source |

## How each one gets its bytes

The authored tree is the only place a change is made. From it:

```
make core-export-check                  # the export assembles, lints and passes its own suite
make core-export CORE_TO=../nativa-core # write the public tree from a commit
make publish-core PUBLISH_ARGS=--dry-run # what a publish would push
make publish-core PUBLISH_ARGS=--yes     # replace and push the public repository
```

`bin/export/core/` is the profile a publish applies: the prune list with a
reason per path (`bin/export/core/prune.txt`), the Makefile and CI the export
ships, and the README and AGENTS.md the public tree carries. The export writes
its own marker naming the source commit, so the published tree can always say
where it came from. Nothing in `responsive-sk/nativa-core` is edited by hand.

The theme gallery is a consumer: it renders the class names the modules here
already define, and its own `sync:check` compares its `src/` against
`evan70/nativa-minimal`. It is not part of the exported tree and not part of
the deployable artifact; both the profile and `bin/build/build-dist.sh` leave
it out.

The showcase is where this kernel came from and remains a site that installs
modules, not a second kernel source. The byte comparison that measured the
divergence was retired on 2026-11-18
(`docs/kernel-status/28-retiring-the-extract-check.md`): this tree is the kernel
source now, so nothing here is generated from the showcase and no file records
a divergence that no longer has a second side to compare against.

## What follows for a change

- Kernel, catalogue, theme module, docs, CI, build tool: here.
- A published-tree file: here, then publish; never there.
- A gallery page or its rendering: the gallery under `themes/gallery/`.
- A showcase page or its content: `evan70/nativa-minimal`.

## Why the map is a page and not a convention

Every direction has a command that fails when it is ignored: a dirty source tree
refuses to publish, an unmatched prune entry fails the export, a hand edit in
the published tree is overwritten by the next publish, and the gallery's
`sync:check` fails when its copy of the frontend sources drifts. Writing the
map down makes the remaining silent failure, a change made in the wrong
repository, visible in review.
