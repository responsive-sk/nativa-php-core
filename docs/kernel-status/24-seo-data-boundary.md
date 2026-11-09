# Boot 61: SEO data belongs to the surface that owns it

SEO data had one home in `Core` and no owner. `SitemapAction` listed the kernel
rows and nothing else, `FrontendLayout` carried one literal description for
every page, and `WebsiteStructuredData` was the last Core builder whose only
callers were a module and the home page. This round gave each fact an owner
without adding an SEO module:

- a module owns the public rows it serves;
- `Settings` owns the site description;
- the kernel keeps `/robots.txt` and `/sitemap.xml` and the shared `Seo`
  primitives;
- `WebsiteStructuredData` moves to `src/Seo`, because Pages and the home page
  both use it.

## Before

- `src/Modules/Core`: **155 832 B / 38 files** (8 interface / 28 concrete).
- `KernelShapeTest::MAX_APPROVED`: **28**.
- Full source suite: **1101 tests / 7251 assertions / 1 skipped**.

## What moved

| Surface | Bytes | Old path | New path |
|---|---:|---|---|
| `WebsiteStructuredData` | 2 095 | `Core/Application/Services` | `src/Seo` |

The old path is `DROPPED` in `bin/build/kernel-divergence.txt`, and
`OWNED src/Seo` records the new root. `HomepageAction` and `PageViewAction`
import `Seo\WebsiteStructuredData`; the JSON-LD payload is unchanged.

The `Seo` root also holds the new sitemap primitives:
`SitemapEntry`, `SitemapContributor`, `AsSitemapContributor` and
`SitemapContributorRegistry`. They are kernel code, not a module.

## Sitemap contributions

`SitemapAction` builds the home and locale-root rows, then appends every row a
contributor returns. It deduplicates by path, keeps the first row (the kernel
wins `/`), sorts by path and escapes the joined URL in the XML. The response
shape, content type and trailing newline are unchanged.

`BlogSitemapContributor` contributes each locale's blog index and the published
articles from the same listing the blog index renders. `PagesSitemapContributor`
contributes each locale's published pages. Neither contributes a draft, an
alias, a feed, a tag, a category or an author URL. A contributor that throws is
logged once and skipped, so one module cannot turn the sitemap into a 500.

The `DocsSurfaceDiscoveryTest` split follows the new boundary: `llms.txt` still
names no module route, while the sitemap may name a module URL only because the
installed module contributes it. `/mark` and `/docs` stay absent from both.

## Site description

`ShellContextProvider` gained `siteDescription(): ?string`.
`SettingsShellContextProvider` reads the stored `site_description`, trims it
and abstains on a blank or missing row. `LocalesShellContextProvider` returns
null. `FrontendLayout::render()` now resolves an explicit description first,
then the shell port, then `FrontendLayout::DEFAULT_DESCRIPTION`. The resolved
value is escaped in the meta attribute. A page's `seo_description` still wins,
and `PageViewAction` no longer hardcodes `Nativa Minimal`.

## Wiring and gates

`composer.json`, `src/init.php`, `phpstan.neon`, `.php-cs-fixer.dist.php`,
`mago.toml` and `NameOwnershipContractTest::SCOPE` gained `src/Seo`. Mago lets
`Seo` read `Modules\Core` and global names, never a module.

- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- `make extract-check`: **186 identical / 102 recorded divergences**.
- Full source suite: **1132 tests / 7344 assertions / 1 skipped**.
- `make core-export-check`: **188 files / 192 tests / 428 assertions / 58
  skipped**.
- `make dist-check`: clean clone, **207 files**, no `vendor/`, smoke clean.
- `make kernel-size`: **154 346 B / 37 total files**; Core is **35 files**,
  with **8 interface / 27 concrete** types.
- Source CI: **16/16 jobs**, run `42715698265` on `4273690`. The kernel-only
  boot job and the module-installed PHPUnit job both passed.

## After

- `src/Modules/Core`: **154 346 B / 37 files** (8 interface / 27 concrete).
- `KernelShapeTest::MAX_APPROVED`: **28 -> 27**.
- Blog and Pages were reinstalled after their catalogue edits.

## HTTP check

With Blog and Pages installed, the source server answered `/` 200,
`/sitemap.xml` 200, `/robots.txt` 200, `/llms.txt` 200 and `/mark` 302. The
sitemap held 20 unique URLs: home, `/en/`, `/about`, `/contact`, `/blog`,
`/en/blog` and 14 published articles. It held no draft, alias, `/mark` or
`/llms.txt` URL. `/` carried the stored `site_description`; `/about` and
`/contact` carried their own `seo_description`; an article carried its excerpt.

A fresh export with no `vendor/` and only `KernelTheme` answered `/` 200,
`/sitemap.xml` 200 and `/robots.txt` 200; `/llms.txt` and `/mark` answered 404.
Its sitemap held only the kernel home row. Neither server logged a `[seo]`
line on a healthy request.

## Still open

- The optional SEO admin surface is not built. This round deliberately puts
  crawl endpoints and shared primitives in the kernel, not in a module that a
  kernel-only tree would have to serve without.
- `llms.txt` stays in `Docs`; it advertises the docs route, not the sitemap.
- Publish is a separate step. It was not run in this round.
