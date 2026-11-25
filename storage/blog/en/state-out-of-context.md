---
title: "State out of context ... when memory lives in files"
date: "2026-09-17"
version: "1.0.0"
tags: ["agents", "git", "testing", "architecture", "workflow"]
excerpt: "An AI assistant's context window is mechanics, not memory ... it dies between sessions and burns tokens like fuel. So we externalize state: a revision report from the git content model, digests instead of mtimes, gate records that let the next iteration resume. On real tools from this repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "stav-mimo-kontextu"
published: true
---

You're a developer working with an AI assistant. In the morning you type
"continue".

And it knows nothing. Yesterday is gone, the context window closed and
every thought that lived in it died with it.

Or worse: it didn't close all the way. The assistant carries half of
yesterday mixed with half of last week ... and every new step pays tokens
for memory that no longer solves anything.

This repository has a rule for that: **state doesn't live in context. It
lives in files.**

## Context is mechanics, not memory

The assistant's window looks like memory and behaves like fuel.

Every iteration burns it. The longer the conversation, the more expensive
every next thought ... and at some point it's full. The honest comparison:

| | context window | file on disk |
|---|---|---|
| survives a session | no | yes |
| cost grows with length | yes | no |
| can you share it | no | `git push` |
| can you hash it | no | `sha256` |

That's why two tools live in this repo that write state out,
`bin/revision-report.php` and `bin/gate-run.php`. Both were born from one
sentence in `AGENTS.md`: *never re-scan the tree to re-derive what
changed*.

## Truth carries an address, not a time

The first question after interrupted work: what actually changed?

The tempting answer is the file's time. `mtime`: when it was last
touched.

And that's the trap. `mtime` says when something touched the file, a
checkout, `touch`, a tree sync, all rewrite it. It says nothing about
**what** is in the file. A false random trail, exactly like a boolean
without a contract: `true` ... but where from, since when, does it still
hold?

`revision-report` reads a different source, the **git content model**.
It sorts the change list (`git status --porcelain`), buckets it by module
and hashes the result:

```php
// bin/revision-report.php — the tree state's identity token
$digestInput .= $change['status'] . "\t" . $change['path'] . "\n";
$digest = hash('sha256', $digestInput);
```

The contract stands in the docblock: *identical working tree + identical
agent state → byte-identical output. Never mtimes.*

A digest is a truth token with a type, bound to bytes, not to time. Two
identical changes produce the same hash ... just like the frozen truth
from [part 1 of the series](/en/blog/why-rust).

## The gate that remembers its pain

The second tool solves resuming after a failure.

Gates run through `bin/gate-run.php`: and when one fails, the record is
not written into the conversation's context. It's written to disk:

```text
// storage/cache/agent-state/<gate>.json
{ "gate": "test", "exit": 1, "digest": "b48a5dc0…", "tail": "…" }
```

Three fields, each carrying one type of truth. `exit` says *that*,
`tail` says *what*, `digest` binds both to the exact bytes of the output.

So the next iteration doesn't rerun gates blindly. It reads the record:

```bash
php bin/gate-run.php --output test
```

And gets precisely the piece of world that killed the gate, the output
tail, without repeating the whole suite.

It happened for real, just yesterday. The test gate fell with 190 errors,
and the record's tail held one sentence: the provider appends an
extension to the slug, the comparison didn't. 190 false failures, one
true root.

Without the record, the next iteration would either repeat the whole
suite (tokens) or read the failure from a dying context (hallucination).
With the record, it walked straight to the line with the bug.

A green rerun deletes the record. The gate's memory is therefore always
today.

## Why sorted ... and why JSON

Both choices in the tools are not cosmetics.

Sorting before hashing guarantees that the order of lines in `git status`
never changes the identity of a state. The same tree produces the same
digest no matter the order git printed the files in.

The JSON record format is a contract between writer and reader,
`RevisionState` is the only module that knows the format, so it cannot
drift between the tools. One writer, several readers
(`revision-report`, agents, humans), zero drift.

## When it doesn't hold (the mandatory honesty)

The report and the records are **information, not substitution**.

They replace re-scanning the tree and blind reruns. They don't replace
gates. `make pre-commit` in this repo always runs in full, lint,
analyze, test, lekalo, regardless of what the cache says. A record
without a run isn't green, it's just unlisted.

The second honesty: a digest identifies **a state, not a truth**. If the
tree changes after the record was written, its tail describes a world
that no longer exists. A token carries its own time, that's why a green
run deletes the record instead of letting it age.

And the third: backward archaeology, digging through history to a
contract's roots via `git log -S`: is a one-off cached action here, not
a living process. It costs tokens and runs when needed. Being needed
constantly isn't one of those times.

## Practically

1. **Start the iteration with the report** ... `make revision-report`
   (or `--json`), read only the reported files, never re-scan the tree.
2. **Run gates through `gate-run`** ... a failed gate writes a record,
   resume the next run from `--output <gate>`, not blindly.
3. **Carry it to `make pre-commit`** ... the report and records are the
   map, not the terrain. Quality is verified in full, always.

State then answers the question "where are we" ... even when the context
died long ago.

## Sources

- [Git, pickaxe, -S](https://git-scm.com/docs/git-log) ... searching occurrence-count changes through history
- [SHA-256, NIST FIPS 180-4](https://nvlpubs.nist.gov/nistpubs/FIPS/NIST.FIPS.180-4.pdf) ... the hash that carries byte identity
- [Unix, mtime](https://en.wikipedia.org/wiki/Stat_(system_call)) ... a file's time trail and its traps
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
- [docs/blog-voice.md](/docs/blog-voice) ... the voice guide whose mechanical half the test guards
