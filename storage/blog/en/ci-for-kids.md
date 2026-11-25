---
title: "GitHub Actions for kids ... eight jobs and one command at home"
date: "2026-10-08"
version: "1.0.3"
tags: ["ci", "github-actions", "testing", "browser", "architecture"]
excerpt: "Eight jobs on GitHub and one command at home. A gate that exists only on the server gets skipped at home ... and a gate that exists only at home rots. What our loop looks like, where it still duplicates, and why a test that reads a list does not prove the list."
author: "evan.mcdan@example.test"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "ci-jobs-pre-deti"
published: true
---

You are a PHP developer and you like it when things work.

You type `git push`, walk away from the desk, and come back twelve minutes later to a green tick or a red cross.

A green tick feels good.

But the feeling comes from something you did not run yourself, and that is where most of the trouble with CI starts.

This article is about how we do it in this repository: eight jobs on GitHub, one command at home, and two places where it is still not quite right.

## Eight jobs, two commands

Our workflow is the file `.github/workflows/ci.yml`.

It has 743 lines and eight jobs that run in parallel: `phpstan`, `code-style`, `rector`, `tests`, `acceptance`, `browser`, `supply-chain` and `lekalo`.

Five of them do nothing you should not run at home too:

| CI job | Command at home | What it catches |
|---|---|---|
| `phpstan` | `composer phpstan` | types, level 9, zero tolerance |
| `code-style` | `composer cs-fix-dry` | formatting nobody should be commenting on |
| `rector` | `composer rector-dry` | automated edits that should have happened |
| `tests` | `composer test` | 4535 tests, 33141 assertions |
| `lekalo` | `make lekalo-check` + `make lekalo-trace` | the semantic model and the trace manifests |

At home, one command calls all of it.

```sh
# Makefile — pre-commit is what CI runs, in one process
make pre-commit   # lint + analyze + test + lekalo-check + lekalo-trace
```

Why the same commands rather than two similar lists?

Because a list that exists twice will drift once.

When `composer test` and CI each had their own idea of the suite, the first person to add a test to one and forget the other learned the truth a week later — and the truth was a red `master`.

## A gate that exists only at home rots

Our acceptance suite lived in the Makefile for a long time and never ran in CI.

It was not bad.

It was a set of Codeception tests meant to run against a live server, and since almost nobody ran it, it rotted through months of theme changes.

When we finally ran it, it failed on three of four themes: the suite special-cased one theme and asserted markup the others never had.

Nobody noticed, because a file that never runs never reports anything.

Today acceptance is a job of its own in CI.

It is an expensive job — it boots a server, migrates the databases and seeds fixtures — and that is exactly why it belongs in CI: an expensive gate has to run without you remembering it.

## Three boots of the app ... and one duplication

Two of our jobs — `acceptance` and `browser` — need a running application.

The sequence is always the same: create the `storage` directories, run `php bin/cms migrate`, seed with `php bin/cms seed`, add `php bin/cms forms:seed`, then start the server and wait for it to answer.

```sh
# .github/workflows/ci.yml — acceptance and browser each start their own server
php -S 127.0.0.1:8000 -t public bin/dev/dev-server-router.php > storage/logs/browser-server.log 2>&1 &
for i in $(seq 1 30); do
  if curl -fsS -o /dev/null http://127.0.0.1:8000/ 2>/dev/null; then break; fi
  sleep 1
done
```

That sequence appears three times in the file: for `acceptance`, for `browser`, and once more for the debug server on port 8010.

Let us be honest: this is a minus.

Three copies of one truth are three places where `forms:seed` gets forgotten — and it shows up as a theme bug, not as a missing seed.

The plan is one script that owns the whole sequence, with CI only calling it with a port and a log path.

Until it exists, this is the biggest duplication in our CI.

## The browser that sees what the DOM cannot

The strangest job is `browser`.

It has 29 steps and 17 of them run a Node script talking to a real Chrome over the Chrome DevTools Protocol.

We do that because a DOM test sees the tree but not what the reader sees on the screen.

When a check is about a theme, we switch it with a command.

```sh
# .github/workflows/ci.yml — every check gets a deterministic theme
php bin/dev/set-theme.php starter
node bin/checks/toc-scrollspy-check.mjs http://127.0.0.1:8000/blog/<slug> st-article-toc__link
```

The theme is set 16 times inside the job, because a check without a deterministic theme measures something it did not choose.

What did the browser find that the tests could not see? The list is uncomfortably long:

- a scrollspy that watched the scroll and never moved the active item,
- a theme toggle that was bound twice, so the first click did nothing,
- a burger in the `magazine` theme sitting 78px from the right edge,
- a menu that ignored Escape and never returned focus,
- a card link nested inside another link,
- a web font that moved the hero 50px after the swap — 0.336 CLS,
- an editor that opened as a sheet only on the second click.

Every one of those passed all the PHP tests, because PHPUnit knows about CSS and focus exactly as much as it knows about the weather.

That is why we also have a check without JavaScript: `bin/checks/nojs-check.mjs` disables script execution through `Emulation.setScriptExecutionDisabled` and verifies the disclosure still opens and the form still submits.

## A test that reads a list does not prove the list

This is the lesson we got twice in one week.

We went through a change that touched more than a hundred files mentioning scripts under `bin/`.

We moved the scripts into role directories, and we have tools for it: `BinLayoutTest` and `CheckScriptsWiredTest`.

And still, fourteen check scripts were broken — they imported `'./lib/cdp.mjs'`, which after the move means `bin/checks/lib/cdp.mjs`.

At runtime Node would throw `ERR_MODULE_NOT_FOUND`.

But `composer test` does not run a single check.

`CheckScriptsWiredTest` asserts a script is listed in CI — a string in a file, not whether it can run.

`CheckScriptsShareCdpTest` asserts a check uses the shared library — not whether it can load it.

Every gate was green and fourteen scripts were dead.

We had the same bug one layer lower: sixteen scripts computed the path to the repository as `dirname(__DIR__)` or `$(dirname "$0")/..`.

While they sat directly in `bin/`, that was true.

After the move it was `bin/`.

The quiet half is worse than the loud one: a script that **reads** from that path crashes.

A script that **writes** to it builds a second tree beside itself and exits with zero.

So `bin/build/build-dist.sh` pointed `DIST_DIR` at `bin/dist`, `mkdir -p` created it, and the build reported success.

Today nothing computes its way to the repository root — the marker `src/init.php` does, through `bin/lib/repo-root.mjs`, `repo-root.php` and `repo-root.sh`.

And `BinLayoutTest`, besides the directory map, resolves every static relative reference under `bin/`.

Every new gate of ours is proven by a mutation that MUST fail.

A guard we have never watched fail is a comment.

That is exactly what happened to our method-length rule: it was green for months because it counted braces, and in PHP `{$row['id']}` is a token that looked like the end of a body. The method had 115 lines and the test saw 43.

## When it pays off (and when it does not)

The browser job is not part of `make pre-commit`, and that is deliberate.

`make pre-commit` runs in two minutes.

The browser job needs a real Chrome, a running server and seeded databases — and takes far longer.

You buy a measurement with that cost. For one edited paragraph in an article, it is wasted time.

For a change in a theme's chrome, entry point or markup, it is the only thing that sees the thing at all.

That is why the browser job always runs in CI, while at home you run it when you change what it measures.

The second boundary: even in CI we do not run everything against everything.

The reader-questions check spends one of the five questions a single IP may ask in five minutes.

So its submit round trip runs in CI on two themes and the rest get `QUESTIONS_SKIP_SUBMIT=1`.

Had we run it on all six, we would have tripped the limiter and reported a UI bug that does not exist.

## Practically

1. Before you commit, run `make pre-commit`. It is the same set of commands CI runs, only on your machine and without the wait.

2. When you change a theme's chrome, entry point or markup, boot the server and run the matching check by hand. `php bin/dev/set-theme.php <theme>` gives you a deterministic theme and `node bin/checks/<check>.mjs` tells you what the browser saw.

3. When you add a check, add the gate that fails when it is wired wrong — and prove it with a mutation. A loop you cannot break is a loop you cannot trust.

## Sources

- [GitHub Actions — Workflow syntax](https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions) — jobs, steps and `timeout-minutes`
- [GitHub Actions — Running jobs in parallel](https://docs.github.com/en/actions/how-tos/writing-workflows/choosing-what-your-workflow-does/using-jobs-in-a-workflow) — why we have eight jobs instead of one
- [shivammathur/setup-php](https://github.com/shivammathur/setup-php) — PHP 8.5 and extensions in every job
- [Chrome DevTools Protocol](https://chromedevtools.github.io/devtools-protocol/) — how `bin/checks/*-check.mjs` talk to the browser
- [PHPUnit — Assertions](https://docs.phpunit.de/) — what `composer test` actually proves
- [Nativa Minimal Documentation](/docs/getting-started) — the official guide
