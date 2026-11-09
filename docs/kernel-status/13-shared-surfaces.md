# Kernel status 13 > shared surfaces leave the kernel

## Boots 49–50: the registry, upload store, mail port and Markdown get owners

The Docs round took a route out of the kernel. This round asks the next
question: which classes are still in `Modules\Core` although no kernel boot,
dispatch or render path calls them? The answer is measured, not guessed, and
four surfaces left.

### Before

- `src/Modules/Core`: **397 392 B / 78 files** (12 interface / 64 concrete).
- `KernelShapeTest::MAX_APPROVED`: **63**.
- Export: **204 files / 1 271 313 B**; clean clone **200 tests / 711
  assertions / 0 skipped**.

### What moved

| Surface | Bytes | New owner | Consumers |
|---|---:|---|---|
| `LocalStorageProvider` | 8 309 | `modules/Uploads` | Blog, Cardboard, Media |
| `NavBadgeRegistry` + contract + attribute | 8 728 | `modules/Navigation` | Cardboard, Forms, Notifications, Questions |
| `Mailer` | 1 895 | `modules/Users` | Users only |
| `MarkdownRenderer` + blocks | 31 920 | `modules/Markdown` | Blog, Docs, Forms, Pages, Jednotka |

The upload store has no route and no database of its own: it is the validation
and path rule its consumers share. The nav badge registry is the same: the
kernel's `FrontendLayout` never asks for a badge; the admin layout does.

`Mailer` did not get a new module. Its only consumer is `Users`, whose
`MailerFactory` and transports already live there. A future second consumer
would turn it into a `Mail` module, not into a kernel port.

Markdown was the largest cut and had an external prerequisite. `JednotkaTheme`
is a byte-copy from `nativa-themes` and calls `MarkdownRenderer::slugify()`.
The theme repository changed its import first; this tree synced the copy and
then moved the renderer, block context, block interface, registry and
`AsMarkdownBlock` attribute. The two renderer test files moved with them, and
the `prose-separator` spec pins follow the new paths.

### The bug the navigation move exposed

`NavBadgeRegistry` still used the pre-Boot-23 resolver: the first mention of
`class` anywhere in a file. Adding it to `TextFirstFqcnTest`'s registry list
made the test resolve `Modules\Wrong\Place\says` out of a docblock sentence and
skip the provider silently: the same defect the other registries had already
been fixed for, hidden in a registry the test had never listed.

The moved copy now uses the declaration-start resolver: namespace and type
declarations only, in lines or after `;`. The test keeps all registries,
including `NavBadgeRegistry` and `MarkdownBlockRegistry`, in one list so the
next copy cannot drift unnoticed.

### After

- `src/Modules/Core`: **346 540 B / 68 files** (9 interface / 57 concrete).
- `KernelShapeTest::MAX_APPROVED`: **60 → 56** (63 before the round).
- Source suite: **1099 tests / 7272 assertions / 1 skipped**.
- `make extract-check`: **199 identical, 89 recorded divergences**; the moved
  upstream paths are recorded as `DROPPED`, with the owning module named.
- `make lekalo-check`: **33 modules** validated.
- `make themes-check THEMES_ROOT=../nativa-themes`: **23 identical, 0 diverged**
  against the local theme commit `7842772`.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.

Export measured from `ece318a`: **192 files / 1 216 190 B**. The export check
runs **191 tests / 525 assertions / 52 skipped** without modules; a clean clone
after `make modules-install` and `php bin/cms migrate` answers **191 tests /
674 assertions / 0 skipped**. The four module directories and the moved test
files are pruned, so the kernel suite is the same shape it was before the round
minus the files each side no longer shares.

### Still open

- The `nativa-themes` commit `7842772` is local and has not been pushed. The
  default `make themes-check` therefore still compares the core copy against
  the old remote HEAD (`e6d71db`) and reports Jednotka as diverged. The local
  `THEMES_ROOT=../nativa-themes` check is green; the public CI needs the theme
  commit pushed first.
- `StructuredDataService`, `ContentServices`, the page cache and the theme
  resolver stay in the kernel: each is on the boot/render path, and moving one
  would require an inversion, not another module directory.
