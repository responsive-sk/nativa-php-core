# docs-surface Specification

Canonical spec for the `/docs` reading surface: the guides the repository
publishes, the query it answers, and the fields that lead a reader to it.

## Purpose

The guides are a catalogue, not a directory listing, and the search is not a
second list: both read through `DocsCatalog`, so a document nobody published
can neither be a page nor a hit. A reader standing in the guides searches the
guides: from the shell's field where the theme ships one, and from the index's
own where it does not.

## Requirements

### Requirement: The guides answer their own query

`GET /docs?q=` SHALL answer from the published catalogue and from nothing else:
every result SHALL be a key of `DocsCatalog`, ranked with a title hit worth more
than a body hit, and a tie SHALL keep catalogue order. The query SHALL be
reduced to lowercase word tokens before anything matches it, so quotes,
parentheses and operators are punctuation rather than syntax. A query with no
token SHALL fall back to the index rather than to a result list.

#### Scenario: A query answers from the published guides

- GIVEN a word that appears in a published guide
- WHEN `GET /docs?q=` is asked for it
- THEN the page links that guide
- AND every link it renders belongs to `DocsCatalog::keys()`

- PINS: modules/Docs/tests/DocsSearchTest.php::aQueryAnswersFromThePublishedGuides

#### Scenario: Syntax in the query is only punctuation

- GIVEN a query of FTS syntax around the same words as a plain query
- WHEN both are asked
- THEN both answer with the same number of guides

- PINS: modules/Docs/tests/DocsSearchTest.php::syntaxInTheQueryIsOnlyPunctuation

#### Scenario: The query comes back escaped

- GIVEN a query carrying markup
- WHEN the results render
- THEN the query is carried back in the sentence
- AND the markup is escaped rather than emitted

- PINS: modules/Docs/tests/DocsSearchTest.php::theQueryComesBackEscaped

### Requirement: A miss says so in its own words

A query that matches nothing SHALL answer with its own sentence, not with the
index empty state: "no guides are published in this deployment" is true of a
deployment without `docs/` and false of a word that does not appear in them.

#### Scenario: A query with no hits says so

- GIVEN a word that appears in no published guide
- WHEN `GET /docs?q=` is asked for it
- THEN the page says no guide matches
- AND it does not say that no guides are published

- PINS: modules/Docs/tests/DocsSearchTest.php::aQueryWithNoHitsSaysSo

### Requirement: The search surface is one heading and one section

The count and the hits SHALL be rendered by the kernel, in a single
section, and the hits SHALL follow the count without a theme page block between
them. `DocsPageTheme::docsIndex()` SHALL NOT compose the results: it is the
index surface, so it opens with a page head reading "Documentation" whatever
was asked, carries the empty state for a deployment without guides, and its own
paddings put a second top-level heading and more than three hundred pixels of
air between a count and the first row.

#### Scenario: A query renders one heading and the hits below the count

- GIVEN a theme that composes the document index
- WHEN a query returns guides
- THEN the page carries exactly one `<h1>`
- AND the hits render after the count
- AND the theme's index head and cards do not appear

- PINS: modules/Docs/tests/DocsSearchTest.php::theHitsFollowTheCountUnderOneHeading

### Requirement: The header field is routed by section

The header's search field SHALL submit to `/docs` while the reader is inside
`/docs`, and SHALL keep the theme's own destination everywhere else. The answer
SHALL come from the shell the kernel is about to render, because the catalogues
disagree: Boson ships a header field and a second copy behind the menu toggle,
while a theme without either leaves `/docs` searchable only by typing the URL.

#### Scenario: The header field is routed by section

- GIVEN a request for `/docs` and a request for `/blog`
- WHEN the shell renders
- THEN the docs page submits its field to `/docs`
- AND the blog page keeps submitting to `/blog`

- PINS: modules/Docs/tests/DocsSearchTest.php::theHeaderFieldFollowsTheSection

### Requirement: The index composes a field only when the shell has none

The index SHALL compose a field of its own only when the theme's shell carries
none: on `/docs` a shell's field already posts `q` to the same URL, so a second
one would be the same input twice.

#### Scenario: The index adds no field beside the shell's

- GIVEN a theme whose shell carries a search field
- WHEN the index renders
- THEN the page carries exactly the shell's fields
- AND the kernel's section carries no form

- PINS: modules/Docs/tests/DocsSearchTest.php::theIndexAddsNoSecondField

#### Scenario: A theme without a shell field gets one on the index

- GIVEN a theme whose shell carries no search field
- WHEN the index renders
- THEN the kernel's section carries a field posting to `/docs`

- PINS: modules/Docs/tests/DocsSearchTest.php::aThemeWithoutAShellFieldGetsOneOnTheIndex

#### Scenario: A miss stays refinable without a shell field

- GIVEN a theme whose shell carries no search field
- WHEN a query carrying markup returns no hits
- THEN the field carries the query back escaped

- PINS: modules/Docs/tests/DocsSearchTest.php::aMissIsStillRefinableWithoutAShellField

#### Scenario: The index is searchable whatever the theme

- GIVEN an active theme, with or without a field in its shell
- WHEN a request for `/docs` without a query renders
- THEN the page carries a search field posting to `/docs`

- PINS: modules/Docs/tests/DocsSearchTest.php::theIndexIsAlwaysSearchableFromItsPage
