# seo-crawl Specification

## Purpose

The public crawl policy must let a crawler fetch the assets a rendered page
needs and must let a shared cache answer repeated `/robots.txt` requests,
without turning the file into an access-control list.

## Requirements

### Requirement: Public crawl assets stay fetchable

`/robots.txt` SHALL allow the public asset paths the shell serves: `/dist/`,
`/fonts/`, `/favicon.ico`, `/favicon.svg`, `/apple-touch-icon.png` and
`/robots.txt`. The catch-all `Disallow: /` SHALL stay first, and `/mark`,
`/debug` and `/storage/uploads` SHALL NOT gain an allow rule.

#### Scenario: A crawler can fetch the deferred bundle and the fonts

- GIVEN the public shell emits a deferred bundle under `/dist/` and a font under
  `/fonts/`
- WHEN a crawler reads `/robots.txt`
- THEN both prefixes are allowed
- AND the favicon paths are allowed
- AND `/mark`, `/debug` and `/storage/uploads` have no allow rule
- PINS: tests/Http/RobotsActionTest.php::itAllowsThePublicAssetPathsTheShellEmits

### Requirement: Crawl policy is cacheable for one hour

`/robots.txt` SHALL answer with `Cache-Control: public, max-age=3600`, so a
shared cache can serve repeated crawler requests without entering PHP. The
policy body SHALL stay byte-identical across a cache hit, and the header SHALL
NOT make the response private.

#### Scenario: A second crawler request is answered by the edge

- GIVEN a successful `/robots.txt` response
- WHEN a shared cache stores it and a second crawler requests it
- THEN the response is cacheable for 3600 seconds
- AND the body is unchanged
- PINS: tests/Http/RobotsActionTest.php::itCachesTheCrawlPolicyForOneHour
