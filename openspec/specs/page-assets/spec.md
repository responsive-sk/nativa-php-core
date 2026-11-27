# page-assets Specification

Canonical spec for the CSS and scripts a page carries: the surface it declares,
the assets the shell inlines for that surface, and the recorded ceiling that
keeps a page from growing past it.

## Purpose

A page carries the CSS and the script of the surface it is, and nothing that
belongs to another surface. The surface is declared by the code that composes
the page, the shell inlines the base assets plus that surface's entries, and a
recorded ceiling turns a heavier page into a failing check.

## Requirements

### Requirement: A page declares its surface

The shell SHALL accept a surface key from the composing action and SHALL treat
an absent key as `page`, the key that means the theme's complete sheet. Every
key SHALL come from `Modules\Core\Domain\PageSurface`.

#### Scenario: An article page carries only the article assets

- GIVEN the blog article action composes a page
- WHEN the shell renders it
- THEN the inlined CSS equals the base sheet followed by the article sheet
- AND the tagged scripts are the base script plus the article script
- PINS: tests/Theme/PageAssetBudgetTest.php::anArticlePageCarriesOnlyTheArticleAssets

#### Scenario: A listing carries no contents rail

- GIVEN the blog index action composes a page
- WHEN the shell renders it
- THEN no contents-rail or comment rule is inlined
- AND no rail script is tagged
- PINS: tests/Theme/PageAssetBudgetTest.php::aListingPageCarriesNoContentsRail

### Requirement: The homepage carries no article rules

The homepage SHALL inline the shared kernel files and the homepage rules only.
The contents-rail, comment and member stylesheets SHALL NOT appear in its
document, inlined or linked.

#### Scenario: The homepage is rendered with its own assets

- GIVEN the homepage action composes a page
- WHEN the shell renders it
- THEN the inlined CSS names no article sheet
- AND the document contains no article-only script
- PINS: tests/Theme/PageAssetBudgetTest.php::theHomepageCarriesNoArticleRules

### Requirement: A missing surface entry keeps the theme's page

When the build emits no entry for a declared surface, the shell SHALL inline
the theme's own sheet and tag the theme's own script. A theme that declares no
surface SHALL render the bytes it renders today, in both files and scripts.

#### Scenario: A single-sheet theme keeps its page

- GIVEN a theme whose package emits one CSS entry and one script entry
- WHEN a page of that theme renders with any surface key
- THEN the inlined CSS and the script tags are byte-identical to the page
  rendered without a surface
- PINS: tests/Theme/PageAssetBudgetTest.php::aThemeWithoutSurfaceEntriesKeepsItsPage

### Requirement: Every surface has a ceiling and stays under it

`config/page-budget.json` SHALL own a CSS ceiling and a JS ceiling per theme
and surface. The suite SHALL fail when a declared surface has no ceiling, and
when a rendered page exceeds the ceiling of its theme and surface.

#### Scenario: A surface without a ceiling fails

- GIVEN a surface key that a theme declares
- WHEN the suite runs
- THEN the budget test fails and names the missing theme and surface
- PINS: tests/Theme/PageAssetBudgetTest.php::everySurfaceHasACeiling

#### Scenario: A heavier page fails

- GIVEN a surface sheet has grown by one rule
- WHEN the suite runs
- THEN the budget test fails and names the theme, the surface, the ceiling and
  the actual bytes
- PINS: tests/Theme/PageAssetBudgetTest.php::noPageExceedsItsInlinedCeiling
