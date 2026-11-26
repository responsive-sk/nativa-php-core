# page-cache-invalidation Specification

## Purpose

TBD - created by archiving change marko-adoption. Update Purpose after archive.

## Requirements

### Requirement: A content write invalidates only the pages it changed

The system SHALL drop the cached public pages that depend on a write, not the
whole page cache: an article write invalidates `/`, `/blog` and `/authors` and
leaves every other cached page in place. A request path SHALL match on segment
boundaries, so `/blog` covers `/blog` and `/blog/{slug}` but never `/blogfeed`,
and `/` SHALL cover the homepage only, not every page. Every cached query
variant of an invalidated page SHALL be deleted with it.

#### Scenario: An article write keeps unrelated pages cached

- GIVEN `/`, `/blog`, `/blog/hello-world` and `/kontakt` are cached
- WHEN an article write invalidates `/blog`
- THEN `/blog` and `/blog/hello-world` are deleted
- AND `/` and `/kontakt` keep their entries
- AND the next `/kontakt` request is answered `X-Cache: HIT`

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::anArticleWriteKeepsEveryUnrelatedPageCached
- PINS: modules/PageCache/tests/PageCacheServiceTest.php::aFreshEntryIsServedWithoutRenderingThePageAgain

#### Scenario: A prefix does not reach a path that only starts with the same characters

- GIVEN `/blog` and `/blogfeed` are cached
- WHEN `/blog` is invalidated
- THEN `/blogfeed` keeps its entry

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::aPrefixDoesNotReachAPathThatOnlyStartsWithTheSameCharacters

#### Scenario: The root path is the homepage, not every page

- GIVEN `/`, `/blog` and `/kontakt` are cached
- WHEN `/` is invalidated
- THEN only the homepage entry is deleted

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::theRootPathInvalidatesOnlyTheHomepage

#### Scenario: Query variants share the page they belong to

- GIVEN `/blog` and `/blog?p=2` are cached under different keys
- WHEN `/blog` is invalidated
- THEN both entries are deleted

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::anArticleWriteKeepsEveryUnrelatedPageCached

### Requirement: A cache entry records the page it belongs to

`PageCacheService` SHALL store the request path beside every entry it writes,
and `PageCacheMiddleware` SHALL supply it from the request it rendered. An
entry that carries no path — written before the metadata existed, or unreadable
— SHALL be deleted by any invalidation rather than trusted, because the cost of
deleting an unrelated page is one re-render while the cost of serving a stale
article is the bug this cache exists to prevent.

#### Scenario: The middleware records the path

- GIVEN an anonymous `GET /blog` is stored
- WHEN the entry is read back
- THEN it carries `path: /blog`

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::theMiddlewareRecordsTheRequestPath

#### Scenario: A path-less entry is invalidated rather than trusted

- GIVEN an entry with no `path` key exists
- WHEN any path is invalidated
- THEN the entry is deleted

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::anEntryWithoutARecordedPathIsInvalidatedRatherThanTrusted

#### Scenario: A corrupt entry is deleted instead of served

- GIVEN a cache file whose contents are not JSON
- WHEN any path is invalidated
- THEN the file is deleted

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::aCorruptEntryIsDeletedInsteadOfLeftToBeServed

#### Scenario: Invalidating no paths deletes nothing

- GIVEN a cached page exists
- WHEN invalidation is called with no paths
- THEN nothing is deleted

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::invalidatingNoPathsDeletesNothing

### Requirement: The blog surface is invalidated as one list

Blog write paths (create, edit, delete, publish toggle, inline save, tag and
category edits) and the outbox article publish handler SHALL invalidate the
same list — `/`, `/blog`, `/authors` — and SHALL NOT call
`CacheInvalidator::flush()`; a flush stays reserved for writes whose effect
cannot be named as paths. The outbox handler SHALL spell the list locally
because Blog already imports Outbox (cover jobs), so importing `BlogPageCache`
back would make the catalogue closure graph cyclic; a test SHALL pin the two
lists as equal.

#### Scenario: Every blog write path invalidates

- GIVEN the seven Blog write actions and the outbox article handler
- WHEN their sources are read
- THEN each contains an invalidation call and none calls `flush()`

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::everyBlogWritePathInvalidatesItsOwnPagesInsteadOfFlushingTheCache
- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::theOutboxPublishHandlerInvalidatesTheSameArticlePagesAsTheBlogModule

#### Scenario: The homepage is never forgotten

- GIVEN the Blog page list
- WHEN it is read
- THEN it contains `/`, `/blog` and `/authors`

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::theBlogTagListCoversTheHomepageTheBlogAndAuthorProfiles

#### Scenario: Scheduled publishes invalidate the same pages

- GIVEN the outbox article publish handler
- WHEN it handles a publish event
- THEN it invalidates `/`, `/blog` and `/authors`
- AND the paths match `BlogPageCache::paths()`

- PINS: modules/PageCache/tests/PageCacheInvalidationTest.php::theOutboxPublishHandlerInvalidatesTheSameArticlePagesAsTheBlogModule
