# pagination Specification

## Purpose

Pagination owns the arithmetic of a page and nothing else: how many rows a page
holds, which rows those are, and whether a next page exists. The caller owns the
address, because a page number means one thing in the admin and another in an
API.

## Requirements

### Requirement: A paginator owns the arithmetic, the caller owns the address

The system SHALL provide two implementations of one `Paginator` contract in
`modules/Pagination/`: `OffsetPaginator` for numbered pages over
`LIMIT`/`OFFSET`, and `CursorPaginator` for keyset pages over a key comparison.
Both SHALL be transport-free: neither SHALL read a request, a router or a
database. `toArray()` SHALL answer `items`, `meta` and `links`, and take a
closure turning a page value into a URL, so the caller names pages and the
paginator never spells a query key.

#### Scenario: The same page means the same thing in two surfaces

- GIVEN an `OffsetPaginator` over 137 rows at 20 per page, on page 1
- WHEN the caller renders it through a `/mark` closure and through an API closure
- THEN both receive `meta.total = 137`, `meta.current_page = 1` and `meta.last_page = 7`
- AND each receives the URL its own closure built, because the paginator passed only `2`

- PINS: modules/Pagination/tests/OffsetPaginatorTest.php::theSamePageMeansTheSameThingInTwoSurfaces

#### Scenario: A cursor stays opaque

- GIVEN a keyset page whose cursor is an id
- WHEN the page is rendered
- THEN the cursor reaches the closure as the string it came in as, with no encoding, decoding or ordering applied

- PINS: modules/Pagination/tests/CursorPaginatorTest.php::aCursorReachesTheClosureUnchanged

### Requirement: A page number outside the list still shows a page

A requested page SHALL be clamped to `[1, lastPage()]`, and the offset SHALL be
derived from the clamped page, so a hand-edited `?page=99999` on a seven-page
list reads the last page with rows rather than an empty table with a broken
pager. A page size below one SHALL be clamped to one rather than raised. An empty
collection SHALL report a range of 0–0, not 1–0.

#### Scenario: A page past the end reads the last page

- GIVEN 137 rows at 20 per page and a request for page 99
- WHEN the page is built
- THEN the reported page is 7 and the offset is 120
- AND the query returns rows, and there is no next page

- PINS: modules/Pagination/tests/OffsetPaginatorTest.php::aPageBeyondTheEndReadsTheLastPage

#### Scenario: A page size of zero does not divide by zero

- GIVEN a page size of 0 and a request for page 2 over 3 rows
- WHEN the page is built
- THEN the page size is 1, the last page is 3, and the range is 2–2

- PINS: modules/Pagination/tests/OffsetPaginatorTest.php::aPageSizeBelowOneIsOne

#### Scenario: An empty collection has no first row

- GIVEN no rows
- WHEN the page is built
- THEN `meta.from` and `meta.to` are both 0
- AND no previous or next link is offered

- PINS: modules/Pagination/tests/OffsetPaginatorTest.php::anEmptyCollectionHasNoRange

### Requirement: A cursor page is decided by one extra row

`CursorPaginator::fromRows()` SHALL take at most `limit + 1` rows in cursor
order, SHALL keep exactly `limit` of them, and SHALL use the extra row only to
decide that a next page exists and to name its cursor. A fetch that returned a
full page without the extra row SHALL be the last page: the paginator SHALL NOT
invent a cursor for a row it was never given. A page SHALL answer the cursor it
was requested with as its previous cursor.

#### Scenario: The extra row becomes the next cursor without appearing

- GIVEN a fetch of four rows at a limit of three, requested after cursor `10`
- WHEN the page is built
- THEN the page holds the first three rows only
- AND the next cursor is the id of the last kept row, and the previous cursor is `10`

- PINS: modules/Pagination/tests/CursorPaginatorTest.php::theExtraRowBecomesTheNextCursorWithoutAppearing

#### Scenario: A full page without the extra row ends the list

- GIVEN a fetch of exactly `limit` rows
- WHEN the page is built
- THEN there is no next page and no next cursor

- PINS: modules/Pagination/tests/CursorPaginatorTest.php::aFullPageWithoutTheExtraRowIsStillTheLastPage

### Requirement: The inbox is paged through the shared paginator

`GET /mark/notifications` SHALL page the signed-in user's inbox through the
shared offset paginator: it SHALL count the total, then fetch only the clamped
page's rows. Page one SHALL be the bare `/mark/notifications`; every other page
SHALL add `?page=N`. The pager SHALL report the shown range ("Showing 21–25 of
25") and render an unavailable previous/next as an inert control. A list that
fits on one page SHALL render no pager.

#### Scenario: Page one links forward and cannot go back

- GIVEN 25 rows at 20 per page
- WHEN page one is opened
- THEN it shows rows 25–6, reports "Showing 1–20 of 25", and its next link addresses page two
- AND its previous control is rendered disabled

- PINS: modules/Cardboard/tests/NotificationsPageTest.php::theInboxIsPagedThroughTheSharedPaginator

#### Scenario: Following the rendered link opens the next page

- GIVEN page one rendered a next link
- WHEN that href is opened
- THEN the page shows rows 5–1, reports "Showing 21–25 of 25", and its previous link is the bare list
- AND its next control is rendered disabled

- PINS: modules/Cardboard/tests/NotificationsPageTest.php::theInboxIsPagedThroughTheSharedPaginator

#### Scenario: A hand-edited page number still shows rows

- GIVEN 25 rows in the inbox
- WHEN `/mark/notifications?page=99` is opened
- THEN the last page is shown with its rows, not an empty list

- PINS: modules/Cardboard/tests/NotificationsPageTest.php::aPageBeyondTheEndShowsTheLastPageInsteadOfNothing

#### Scenario: One page of rows has no pager

- GIVEN a single notification
- WHEN the inbox is opened
- THEN the row is shown and no pager markup is rendered

- PINS: modules/Cardboard/tests/NotificationsPageTest.php::aSinglePageOfRowsRendersNoPager
