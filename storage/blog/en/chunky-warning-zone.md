---
title: "Chunky rule in practice ... a warning zone that refuses silent growth"
date: "2026-09-28"
version: "1.0.0"
tags: ["php", "architecture", "clean-code", "testing", "ai-agents"]
excerpt: "We had the 60-line rule and a test for it ... and still seven methods stood right at the limit. How a warning zone with an allowlist turned silent cap-crawling into conscious, documented decisions."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "chunky-warning-zona-pre-deti"
published: true
---

You have a rule. You have a test for it. The test is green in every CI run.

The question is ... how many methods in the codebase stand **exactly at
the limit** of that rule?

In this starter kit the answer was seven. Seven methods had bodies of 56
to 60 lines ... and the largest one exactly 60. The cap was 60. Headroom
zero. Any edit to such a method ... one added condition, one parameter ...
would have failed the test.

## What the test said ... and what it didn't

The chunky rule lives in `CorePatternTest::testNoOversizedMethods`
(`tests/Architecture/CorePatternTest.php`). It tokenizes PHP, measures
every function body from the opening to the closing brace ... and fails
everything above 60 lines.

The test says **yes/no**. It does not say **close**.

A 59-line method passes. Tomorrow it has 61. The test fails ... and at
that moment somebody must quickly understand why it grew and what to do.
Jumping from green CI to a broken build is a bad place for that decision.

The second half of the truth ... a test with a failure threshold knows
nothing about the **trend**. Crawling toward the cap is invisible until
the cap is crossed.

## Shrinking ... a concrete example

The largest method sitting on the cap was
`AdminNavService::hierarchicalItems` ... orchestration of the hierarchical
admin navigation. Six linear blocks ... index the items, resolve the
active one, decide expanded groups, attach children, collect top-level,
stamp `is_expanded`. Each block has a name. The rule forces that name
into the code:

```php
public function hierarchicalItems(string $activeSection = ''): array
{
    $index = $this->indexItems($this->activeItems());

    $activeId = $this->resolveActiveId($index, $activeSection);
    $expandedGroups = $this->expandedGroups($index, $activeId);
    $tree = $this->buildTree($index['byId']);

    foreach ($tree as &$group) {
        $group['is_expanded'] = isset($expandedGroups[$group['id']]);
    }
    unset($group);

    return $tree;
}
```

The result ... a 14-line orchestrator and four helpers (`indexItems`,
`resolveActiveId`, `expandedGroups`, `buildTree`), each well below the
cap. The same pattern went through `Kernel::dispatch` (59 → 6 ...
`dispatchMatched`, `runAction`, `normalizeResult`, `renderThrowable`)
and `SettingsDatabaseMigrator::seedDefaultMenu` (58 → 16 ... the data
literal moved to a `DEFAULT_MENU_ITEMS` constant). Commits `cf0acd0` and
`c85f434`.

## The warning zone ... mechanics

The new test `testNoNearCapMethods` takes every body with headroom up to
10 lines (51–60) and asks for a record in `NEAR_CAP_ALLOWLIST`:

```php
private const NEAR_CAP_HEADROOM = 10;

private const NEAR_CAP_ALLOWLIST = [
    'src/Modules/Pages/Interfaces/HTTP/Actions/PagesIndexAction.php::handle'
        => 'index action orchestration',
    'src/Modules/Core/Application/Services/MarkdownRenderer.php::extractFrontMatter'
        => 'frontmatter parser loop',
    // ...
];
```

A record is not an excuse. It is a **documented decision** ... the path
`path.php::method` and a short reason why that code sits there. A failure
reads like this ... a new 52-line method without a record prints:

> `PagesIndexAction.php::handle (52 lines, headroom 8) — declare it in
> NEAR_CAP_ALLOWLIST with a reason, or shrink it`

Three properties follow:

- **It cannot grow silently** ... every new zone member must be
  consciously written down and justified.
- **It licenses no growth** ... an allowlisted method that grows past 60
  still fails the hard-cap test. The zone is not a second, higher limit.
- **It is shrink-only in spirit** ... shrink the method, delete the row.
  The same policy as `LEGACY_BASELINE` in `ThemeFileSizeTest` or
  `SPECULATIVE_INTERFACE_BASELINE` in the same file.

One honest limitation ... stale records (a method is gone or shrank, the
row stayed) are not caught. The test reports undeclared members, not
unused ones. An unused row is just noise ... and you clean noise the next
time you touch the file.

## Why not a real warning

The first idea is usually ... let the test just warn. `phpunit.xml` in
this kit has:

```xml
failOnWarning="true"
failOnRisky="true"
```

A real PHPUnit warning would fail CI just like a failure. That leaves
echo ... and echo disappears. In `--no-output` runs, in CI logs, in
headless agents ... nobody reads three lines between green checkmarks.
A silent warning is `mixed` ... it looks like a message and is worth
nothing.

So the zone does not warn ... it **forces documentation**. A failure is a
message that cannot be ignored ... and the allowlist is the place where
the message keeps living.

## What it gave us

The state after the whole series (four commits, pushed as
`8b302fc..23ff3b6`):

| | Before | After |
|---|---|---|
| Largest body | 60 lines (headroom 0) | 54 lines (headroom 6) |
| Methods in the 51–60 zone | 7 invisible | 13 tracked in the allowlist |
| Allowlist | — | 13 records with a reason |
| Gates | — | phpstan, test (3239), cs-fix, rector, lekalo ... green |

The numbers are honest ... the zone has 13 members, not zero. That is
the point. Zero would mean we split code where splitting makes no sense,
just to make the numbers look pretty. Parser loops and data tables earn
their lines ... and now that is not an opinion, it is written down.

The last layer ... the policy is documented in
`.ai-factory/ARCHITECTURE.md` (Anti-Patterns), so the next AI session
entering the repo without context will find it too.

## When it does not apply

A warning zone is not a universal tool. Three situations where it adds
nothing:

- **A small team without architecture tests** ... the zone lives from the
  test running in CI on every commit. Without that, the allowlist is just
  another list nobody reads.
- **Code that is still moving** ... on a branch where a method gets
  reworked for a week, every other run is red. Add the zone once the code
  settles.
- **A larger cap** ... if your functions naturally live at 120 lines, a
  zone of 111–120 protects nothing. Settle the cap first, then the zone.

And one thing it is not ... it is no replacement for code review. The
test measures lines. It does not measure whether the code is
understandable.

## Practically

If your repository has a rule with a failure threshold:

1. **Measure how many bodies stand within 10 of the cap** ... a one-off
   script with `token_get_all`, the same metric as in the test.
2. **Split the ones on the cap** ... each is a future CI failure, helpers
   are cheaper than incidents.
3. **Add a zone with an allowlist** ... and write down what remains, with
   a reason.

The rule then answers a different question ... not "how many lines are
too many", but "which long functions did we consciously keep, and why".

## Sources

- [PHP Manual — Tokenizer](https://www.php.net/manual/en/book.tokenizer.php) — `token_get_all()` and friends
- [PHPStan — Rule levels](https://phpstan.org/user-guide/rule-levels) — how level 9 works
- [PHPUnit — XML Configuration](https://docs.phpunit.de/en/12.0/configuration.html) — `failOnWarning`, `failOnRisky`
- [The Zen of Python](https://peps.python.org/pep-0020/) — "Explicit is better than implicit"
- [Nativa Minimal Documentation](/docs/getting-started) — the official guide
