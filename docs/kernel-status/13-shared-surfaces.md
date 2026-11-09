# Kernel status 13 > shared surfaces leave the kernel

## Boot 49: the registry, the upload store and the mail port get owners

The Docs round took a route out of the kernel. This round asks the next
question: which classes are still in `Modules\Core` although no kernel boot,
dispatch or render path calls them? The answer is measured, not guessed, and
three surfaces left.

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

The upload store has no route and no database of its own: it is the validation
and path rule its consumers share. The nav badge registry is the same: the
kernel's `FrontendLayout` never asks for a badge; the admin layout does.

`Mailer` did not get a new module. Its only consumer is `Users`, whose
`MailerFactory` and transports already live there. A future second consumer
would turn it into a `Mail` module, not into a kernel port.

### The bug the move exposed

`NavBadgeRegistry` still used the pre-Boot-23 resolver: the first mention of
`class` anywhere in a file. Adding it to `TextFirstFqcnTest`'s registry list
made the test resolve `Modules\Wrong\Place\says` out of a docblock sentence and
skip the provider silently: the same defect the other registries had already
been fixed for, hidden in a registry the test had never listed.

The moved copy now uses the declaration-start resolver: namespace and type
declarations only, in lines or after `;`. The test keeps all registries,
including `NavBadgeRegistry`, in one list so the next copy cannot drift
unnoticed.

### After

- `src/Modules/Core`: **378 460 B / 73 files** (10 interface / 61 concrete).
- `KernelShapeTest::MAX_APPROVED`: **60**.
- Source suite: **1099 tests / 7266 assertions / 1 skipped**.
- `make extract-check`: **202 identical, 86 recorded divergences**; the four
  upstream paths are recorded as `DROPPED`, with the owning module named.
- `make lekalo-check`: **32 modules** validated.
- `make themes-check`: **23 identical, 0 diverged**.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.

Export measured from `835db7d`: **199 files / 1 252 987 B**. The export check
runs **200 tests / 555 assertions / 52 skipped** without modules; a clean clone
after `make modules-install` and `php bin/cms migrate` answers **200 tests /
704 assertions / 0 skipped**. The three module directories and the moved test
files are pruned, so the kernel suite is the same shape it was before the
round minus the five files each side no longer shares.

### Still open

- `MarkdownRenderer` and its block registry (31 920 B / 5 files) are the next
  clean cut, but `JednotkaTheme` is a byte-copy from `nativa-themes` and calls
  `MarkdownRenderer::slugify()`. The theme repository must change first; until
  then `make themes-check` would correctly refuse a local-only import edit.
- `StructuredDataService`, `ContentServices`, the page cache and the theme
  resolver stay in the kernel: each is on the boot/render path, and moving one
  would require an inversion, not another module directory.
