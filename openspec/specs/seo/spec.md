# seo Specification

Canonical spec for the crawl surfaces and the SEO primitives the kernel owns:
`/robots.txt`, `/sitemap.xml`, the site description in the shell and the shared
Organization and WebSite JSON-LD.

## Purpose

The kernel answers the crawl surfaces without importing a module. Content
metadata stays with the module that owns the content, a module contributes its
URLs through one port, and shared structured data has a single builder.

## Requirements

### Requirement: SEO data has one owner per layer

Content metadata SHALL be owned by the module that owns the content. Site-wide
defaults SHALL be owned by `Settings`. The kernel SHALL own `/robots.txt`,
`/sitemap.xml` and the shared SEO primitives. No kernel file SHALL import a
module to answer an SEO surface.

#### Scenario: Content metadata stays with content

- GIVEN a page with `seo_title` and `seo_description`
- WHEN the page is rendered
- THEN the page's own values are used
- AND the kernel reads them through the Pages render path, not a second parser

- PINS: modules/Pages/tests/PageTitleBrandTest.php::thePageSeoFieldsWinTheTitleAndTheDescription

#### Scenario: Kernel-only crawl surfaces still answer

- GIVEN a checkout with no feature module installed
- WHEN `GET /sitemap.xml` and `GET /robots.txt` run
- THEN both answer 200
- AND the sitemap lists only the kernel's home and locale roots

- PINS: tests/Http/SitemapActionTest.php::anEmptyContributorRegistryStillServesTheKernelSitemap
- PINS: tests/Http/RobotsActionTest.php::itAllowsThePublicAssetPathsTheShellEmits

#### Scenario: No kernel file imports a module for SEO

- GIVEN the source tree with Blog and Pages installed
- WHEN the architecture import gate runs
- THEN no file under `src/Seo` or `src/Modules/Core` imports `Modules\Blog` or
  `Modules\Pages`

- PINS: tests/Architecture/KernelCouplingTest.php::testEveryEdgeIsClassifiedAndEveryRowNamesARealEdge

### Requirement: Sitemap contributions are complete and truthful

`/sitemap.xml` SHALL list every public indexable URL the installed tree serves,
in addition to the kernel's home and locale roots. A module SHALL contribute its
own URLs through `Seo\SitemapContributor`. A draft, an alias, a `noindex` surface
or a URL the tree does not serve SHALL NOT appear.

#### Scenario: Published content appears once

- GIVEN Blog and Pages are installed with published content
- WHEN `GET /sitemap.xml` runs
- THEN every published article and page URL appears
- AND every URL appears exactly once

- PINS: modules/Blog/tests/BlogSitemapContributorTest.php::itListsTheBlogIndexAndPublishedArticlesForTheDefaultLocale
- PINS: modules/Pages/tests/PagesSitemapContributorTest.php::itListsPublishedPages

#### Scenario: Drafts and aliases are absent

- GIVEN a draft article, a draft page and a page alias
- WHEN `GET /sitemap.xml` runs
- THEN none of the three URLs appears
- AND the alias target still appears if it is published

- PINS: modules/Blog/tests/BlogSitemapContributorTest.php::aDraftArticleIsAbsent
- PINS: modules/Pages/tests/PagesSitemapContributorTest.php::aPublishedPageIsListedButItsAliasAndDraftAreNot

#### Scenario: A contributor collision is deterministic

- GIVEN two contributors return the same path
- WHEN the sitemap renders
- THEN the path appears once
- AND the output order is the same on every request

- PINS: tests/Http/SitemapActionTest.php::theKernelHomeWinsADuplicatePath

### Requirement: Contributor failure degrades, never breaks the sitemap

A contributor that cannot be loaded, does not implement the port or throws SHALL
be skipped. The kernel entries and every healthy contributor SHALL still render.
An invalid path SHALL be rejected and logged, never emitted.

#### Scenario: A broken contributor still leaves a 200

- GIVEN one contributor throws during discovery
- WHEN `GET /sitemap.xml` runs
- THEN the response is 200
- AND the kernel entries are present
- AND a bounded `[seo]` warning names the skipped class

- PINS: tests/Http/SitemapActionTest.php::aThrowingContributorStillLeavesTheKernelRows

#### Scenario: An unsafe path is not advertised

- GIVEN a contributor returns `https://example.test/page`
- WHEN the sitemap renders
- THEN that URL is absent
- AND the response still contains the valid entries

- PINS: tests/Seo/SitemapEntryTest.php::anUnsafePathIsRejected

### Requirement: Site description follows an explicit precedence

The public shell SHALL use an explicit page description first, the stored
`site_description` second, and the kernel fallback last. A blank stored value
SHALL abstain rather than render an empty description.

#### Scenario: The stored description reaches the home

- GIVEN `site_description` is stored and the home passes no explicit value
- WHEN `GET /` runs
- THEN `<meta name="description">` carries the stored value

- PINS: modules/Settings/tests/SettingsShellContextProviderTest.php::theLayoutUsesTheStoredDescriptionWhenTheActionDoesNotSupplyOne

#### Scenario: A page description wins

- GIVEN a page has `seo_description` and the site has a different description
- WHEN `GET /{slug}` runs
- THEN the page's description is rendered
- AND the stored site description is not

- PINS: modules/Settings/tests/SettingsShellContextProviderTest.php::anExplicitDescriptionWinsOverTheStoredOne

#### Scenario: A blank setting does not empty the tag

- GIVEN `site_description` is blank
- WHEN the home renders
- THEN `<meta name="description">` carries the kernel fallback
- AND the attribute is not empty

- PINS: modules/Settings/tests/SettingsShellContextProviderTest.php::aBlankStoredDescriptionFallsBackToTheKernelLiteral

### Requirement: Shared structured data stays kernel-owned

The Organization and WebSite JSON-LD builder SHALL live in the kernel-owned
`Seo` namespace. The homepage and a published page SHALL emit the same
Organization and WebSite payload, including the Blog `SearchAction`. The move
SHALL NOT change the payload.

#### Scenario: The home still announces the site

- GIVEN a kernel-only tree
- WHEN `GET /` runs
- THEN the page carries Organization and WebSite JSON-LD
- AND the WebSite block carries the `/blog?q=` SearchAction

- PINS: tests/Http/HomepageJsonLdTest.php::theHomepageAnnouncesTheSiteWithOrganizationAndWebSite

#### Scenario: A page uses the same builder

- GIVEN a published page
- WHEN the page renders
- THEN its JSON-LD is built by `Seo\WebsiteStructuredData`
- AND Pages does not import a module for it

- PINS: modules/Pages/tests/PageTitleBrandTest.php::thePageUsesTheKernelStructuredDataBuilder
