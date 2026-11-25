---
title: "Member bonus ... full-text search: SQLite FTS5 in memory, no extra server"
date: "2026-08-13"
version: "1.0.0"
tags: ["search", "sqlite", "fts5", "fulltext", "php", "security"]
excerpt: "Third member-only bonus. The search on /blog?q= runs without Elasticsearch, without Solr, without a single new file on disk ... the index lives in memory and is built in milliseconds. We look at FTS5, bm25 ranking and why a full-text query cannot be injected."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-fts-search-pre-deti"
published: true
---

> This is the third **member-only bonus**. The blog has search ...
> `/blog?q=…` ... and most people would deploy Elasticsearch, Solr or at
> least a new table in the database for it. This blog fits it all into
> **one virtual table in memory** that is built and destroyed on every
> search. Let's look at how it works and why it is enough.

## Search without infrastructure

When a blog grows to twenty articles, the question comes: "Do we need
search?" And right behind it: "What do we deploy for it?"

The answer in this repo is: **nothing.** No new server, no new
database, no extra file. Search uses what you already have ... SQLite ...
and one of its little-known features: **FTS5**.

FTS5 is a full-text index built directly into SQLite. You tell it
"index these text columns" and it builds a structure that can find
words far faster than `WHERE title LIKE '%word%'` (where SQLite must
scan every row and every character). And most importantly, it can
**rank relevance** ... something `LIKE` never could.

## An index in memory, built on demand

The most interesting decision: the index does not live on disk. It
lives in `sqlite::memory:` ... an in-memory database that is created,
populated and destroyed **on every search request**:

```php
$pdo = new \PDO('sqlite::memory:');
$pdo->exec('CREATE VIRTUAL TABLE blog_fts USING fts5(title, excerpt, tags, body, slug UNINDEXED)');
```

"Build the index on every request? That must be slow!" ... it is
surprisingly fast, because:

1. **The source is small.** Twenty Markdown articles are a few hundred
   kilobytes of text. FTS5 builds an index from that in milliseconds ...
   on a machine where Elasticsearch would run as a separate process
   you have to manage, update and monitor.
2. **No synchronization.** The index is **always current** because it
   is built from the current state of `storage/blog/`. Add an article
   and the next search finds it. No cron jobs for reindexing, no
   "the index is 3 days old".
3. **Consistency for free.** Search uses the same cards
   (`BlogArticlesService::all()`) as the blog index, so results can
   never diverge from the article list.

That is the same "no generated map" pattern as the rest of the repo:
nothing is stored, everything is derived on demand. Nothing to
invalidate.

## bm25: why the relevant result is on top

Searching "clean code" returns articles that contain the word. But
which one comes first? Here comes **bm25** ... a relevance-ranking
algorithm that FTS5 can compute directly in SQL:

```sql
SELECT slug FROM blog_fts
WHERE blog_fts MATCH :match
ORDER BY bm25(blog_fts, 10.0, 5.0, 3.0, 1.0)
LIMIT 20
```

The numbers after `bm25` are **column weights** in the order the
columns were defined: `title=10`, `excerpt=5`, `tags=3`, `body=1`.
That means:

- a word in the **title** weighs 10× more than in the body,
- in the **excerpt** 5×,
- in the **tags** 3×,
- in the **body** 1×.

Result: an article with the searched word in the title beats an
article that has it somewhere in the body. A test verifies this
directly ... two fixture articles, one word once in the title and once
in the body, and the assertion says the title one appears earlier in
the HTML:

```php
self::assertLessThan(
    strpos($html, 'href="/blog/zz-rank-body"'),
    strpos($html, 'href="/blog/zz-rank-title"'),
);
```

## Why a full-text query cannot be injected

Here comes the sneakiest detail. FTS5 `MATCH` has its own syntax ...
`AND`, `OR`, `NOT`, quotes, parentheses. And exactly that syntax is
the classic gateway to **query injection**: what if a user types
`"a" OR "b"` and changes the logic of the search? What if they
combine it with error output?

The solution in this repo is elegant ... **the query is never sent to
MATCH as it arrived.** First it is split into words, and each word is
wrapped as a quoted prefix phrase:

```php
// input:   hello OR world
// tokens:  ["hello", "OR", "world"]
$match = implode(' ', array_map(
    static fn (string $token): string => '"' . $token . '"*',
    $tokens,
));
// MATCH:   "hello"* "OR"* "world"*
```

FTS5 operators (`AND`, `OR`, `NOT`, quotes, parentheses, punctuation)
are **stripped** by tokenization ... only the clean word remains. `"OR"`
no longer behaves as an operator, but as an ordinary word to search
for. A test verifies this with exactly the most dangerous example:

```php
$response = (new BlogIndexAction())->handle(Request::create('GET', '/blog', ['q' => 'hello OR world']));

// The "hello-world" article contains hello AND world ... a real OR would have returned it.
self::assertStringNotContainsString('href="/blog/hello-world"', $response->getContent());
```

The asterisk after the word is **prefix search**: `"hello"*` also
finds `hello-world`, `helloed`, `helloworld`. So abbreviations and
inflections work without writing your own lemmatizers.

## Diacritics: "cisty" finds "čistý"

Slovak has háčeks and long vowels. A user typing "cisty kod" (instead
of "čistý kód") would expect to find results. FTS5 with the
**unicode61** tokenizer handles this for free ... it strips diacritics
both when indexing and when searching, so "cisty" and "čistý" behave
like the same word:

```php
$html = (new BlogIndexAction())->handle(Request::create('GET', '/blog', ['q' => 'cisty']));

self::assertStringContainsString('href="/blog/co-je-cisty-kod-pre-deti"', $html);
```

No custom transliteration table, no `iconv` tricks ... the tokenizer
does it all. One thing unicode61 does **not** distinguish is letter
case: search is case-insensitive, which for full-text is almost always
what you want.

## What about members-only articles

In the [previous bonus article](/en/blog/bonus-members-only) we
showed how content gets locked. What about search? It indexes
members-only articles ... does that "give away" their secret body?

Yes, it indexes them ... and **no**, it doesn't give anything away. The
gated body is only used for ranking inside the per-request in-memory
index, which is destroyed right after the response. Results return
only **slugs**, and the results page renders the standard cards
(title + excerpt) ... exactly like the feed. The article page itself
enforces the login gate. The code documents this as a commitment: if
body snippets were ever shown in results, gated bodies must be
excluded from the index **before** that happens.

## A rule without a test is not followed

Search is covered by a whole set of tests that verify the promises
hold:

| Test | What it guards |
|---|---|
| `testSearchFindsArticlesByTitle` | search by title |
| `testSearchFindsArticlesByBodyText` | search by article body |
| `testSearchFoldsDiacritics` | "cisty" → "čistý" |
| `testSearchMatchesPrefixes` | "chunky" → "chunky-rule..." |
| `testSearchRanksTitleAboveBody` | bm25 weights (title > body) |
| `testSearchDoesNotInjectFtsOperators` | `OR` does not behave as an operator |
| `testSearchQuotesAndParensAreHarmless` | quotes/parens cannot hurt |
| `testSearchKeepsEscapedQueryInInputValue` | `<script>` in the query is escaped |
| `testSearchEmptyQueryShowsFullIndex` | empty `q` = the normal index |

The last one is a nice detail: when `q` is missing or empty, the page
behaves like the plain blog index ... no "0 results" without a reason.

## What to take away

1. **Full-text does not have to mean Elasticsearch** ... SQLite FTS5
   handles small datasets with zero infrastructure.
2. **An index in memory is always current** ... it is built on demand,
   so there is nothing to synchronize or invalidate.
3. **bm25 weights control the order** ... title 10×, body 1×; relevance
   is tested, not trusted.
4. **Sanitize MATCH, not just SQL** ... full-text syntax is injectable
   too; tokenize the query into quoted prefix phrases.
5. **Diacritics for free** ... the unicode61 tokenizer strips háčeks
   and long vowels when indexing and when searching.

And next time someone says "we need Elasticsearch for search" ... you
know that for twenty articles, one virtual table in memory is enough.


## Sources

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definition
- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
