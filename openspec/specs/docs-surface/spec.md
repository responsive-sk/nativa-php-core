# docs-surface Specification

Canonical spec for the `/docs` reading surface: the guides the repository
publishes, the query it answers, and the fields that lead a reader to it.

## Purpose

The guides are a catalogue, not a directory listing, and the search is not a
second list: both read through `DocsCatalog`, so a document nobody published
can neither be a page nor a hit. A reader standing in the guides searches the
guides, whether they type in the header's field or in the field on the index.

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

- PINS: tests/Http/DocsSearchTest.php::aQueryAnswersFromThePublishedGuides

#### Scenario: Syntax in the query is only punctuation

- GIVEN a query of FTS syntax around the same words as a plain query
- WHEN both are asked
- THEN both answer with the same number of guides

- PINS: tests/Http/DocsSearchTest.php::syntaxInTheQueryIsOnlyPunctuation

#### Scenario: The field keeps the query and escapes it

- GIVEN a query carrying markup
- WHEN the results render
- THEN the input carries the query back
- AND the markup is escaped rather than emitted

- PINS: tests/Http/DocsSearchTest.php::theFieldKeepsTheQueryAndEscapesIt

### Requirement: A miss says so in its own words

A query that matches nothing SHALL answer with its own sentence, not with the
index empty state: "no guides are published in this deployment" is true of a
deployment without `docs/` and false of a word that does not appear in them.

#### Scenario: A query with no hits says so

- GIVEN a word that appears in no published guide
- WHEN `GET /docs?q=` is asked for it
- THEN the page says no guide matches
- AND it does not say that no guides are published

- PINS: tests/Http/DocsSearchTest.php::aQueryWithNoHitsSaysSo

### Requirement: The search surface is one heading and one section

The count, the field and the hits SHALL be rendered by the kernel, in a single
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

- PINS: tests/Http/DocsSearchTest.php::theHitsFollowTheCountUnderOneHeading

### Requirement: The field follows the section

The header's search field SHALL submit to `/docs` while the reader is inside
`/docs`, and SHALL keep the theme's own destination everywhere else. The index
SHALL carry a field of its own, because the header's field sits behind a menu on
a phone and a page a reader lands on must be searchable from the page.

#### Scenario: The header field is routed by section

- GIVEN a request for `/docs` and a request for `/blog`
- WHEN the shell renders
- THEN the docs page submits its field to `/docs`
- AND the blog page keeps submitting to `/blog`

- PINS: tests/Http/DocsSearchTest.php::theHeaderFieldFollowsTheSection

#### Scenario: The index carries its own field

- GIVEN a request for `/docs` without a query
- WHEN the index renders
- THEN it carries a search field posting to `/docs`

- PINS: tests/Http/DocsSearchTest.php::theIndexCarriesItsOwnField
