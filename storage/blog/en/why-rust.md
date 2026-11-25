---
title: "Rust and Lekalo ... part 1: why Rust matters to a PHP developer"
series: "Rust & Lekalo"
series-part: "1"
date: "2026-09-06"
version: "1.4.0"
tags: ["rust", "lekalo", "architecture", "tooling", "beginners"]
excerpt: "A new series. Rust is the language behind Lekalo — the semantic-model tool that analyzes this very PHP project. Part 1 explains what Rust actually is (no GC, no runtime, a compiled binary), why a PHP developer should care, and maps the whole six-part series."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "preco-rust-pre-deti"
published: true
---

You're a PHP developer. You build web applications. Someone mentions Rust and
your brain quietly files it under "systems programming, not for me". This
series exists to question that filing — because the most interesting thing
happening in this repository right now is analyzed by a Rust tool.

That tool is **Lekalo**, a semantic application model and compiler for
AI-assisted development. We used it to build a model of this kit's Users
module (auth, RBAC, password reset) and to answer questions like *"what breaks
if I change the `User` entity?"* — without reading a single line of code. Part
6 is a full deep dive into it. Parts 1 to 5 are the bridge that makes that
deep dive make sense to a PHP audience.

Here's the map of the whole series:

1. **Why Rust matters to a PHP developer** (this part) — what Rust actually
   is, and when it's worth your time.
2. [**Ownership and the borrow checker**](/en/blog/rust-ownership) — the one
   mental model shift that changes everything.
3. [**Rust tooling**](/en/blog/rust-cli-tooling) — cargo, CLIs, and
   deterministic output (Lekalo's CLI as the case study).
4. [**Rust quality**](/en/blog/rust-testing-quality) — tests, clippy, MSRV,
   contract checkers.
5. [**Semantic application models**](/en/blog/semantic-application-models) —
   what a model of an application actually is, and why it helps AI-assisted
   development.
6. [**Lekalo deep dive**](/en/blog/lekalo-semantic-model) — the finale: a
   real Rust tool analyzing a real PHP project, end to end.

## What Rust actually is

Rust is a **compiled** language. `rustc` (or cargo, its build tool) turns your
source into a native binary — a single executable file with no interpreter
required on the target machine. Two properties follow from that, and both are
the opposite of what PHP gives you:

**No garbage collector.** PHP frees memory for you — reference counting and a
cycle collector, invisibly, at unpredictable moments. Rust frees memory too,
but at **compile time**: every value has exactly one owner, and the moment the
owner goes out of scope, the memory is freed. Deterministically. No pauses,
no surprises, nothing to tune.

**No runtime.** When a PHP request arrives, the Zend engine boots, loads the
code, executes it, and tears everything down. The runtime is always with you.
A Rust binary doesn't have a runtime — the "runtime" was compiled away during
the build. The executable *is* the application.

## The runtime story, from this very repo

This kit is built on a philosophy that suddenly makes Rust feel familiar:
zero-dependency, self-contained, minimal. Look at `src/init.php` — a PSR-4
`autoload_register` fallback that boots the whole application **without** a
Composer autoloader. No framework runtime to install, no vendor directory to
deploy, just PHP and a few files.

```php
// src/init.php — the kit's "runtime" is a few hundred lines of PHP
spl_autoload_register(static function (string $class): void {
    // resolve Modules\* and Templates\* from src/ and Templates/
});
```

Rust's answer to "can I deploy without a runtime?" is more radical: there is
no runtime to deploy, because the compiler baked everything into one static
binary. You copy one file to the server, and it runs. The spirit of
`src/init.php` — "the smallest viable foundation" — is Rust's default mode,
not a hard-won optimization.

This is also the mindset behind `bin/verify-build.php` in this repo: a build
verification script that *contracts* the output (no `@layer`, ES2015,
self-hosted assets) instead of trusting it. Rust projects live on that same
instinct — contracts, verified at build time, fail loudly. More on that in
part 4.

## Truth carries a type

Ownership guards memory. Rust guards something deeper ... **truths**.

In C, a boolean is a bit. `true` or `false` — with no question of *when* it
holds, under what state, where it came from. A mysterious truth that holds
because it holds.

PHP has its own version of the same disease ... loose comparison, where
`"0" == false`. Truth without a declared type.

Rust refuses to compile such a truth. Instead of a bit it gives an enum and
a `match` that must be exhaustive — a forgotten state isn't a warning, it's
a compile error:

```rust
// truth with a contract — when and under which state
match session {
    Session::Fresh { .. } => Outcome::Allow,
    Session::HalfOpen { remaining } => Outcome::Challenge(remaining),
    Session::Expired => Outcome::Deny,
}
```

| | a bit in C | an enum in Rust |
|---|---|---|
| what it carries | a value | value + state + source |
| when it holds | until overwritten | in the scope that declares it |
| forgotten case | silently passes | the compiler screams |

This repository carries both sides of the story. `Period`
(`src/Modules/Cardboard/Domain/Model/Period.php`) is an enum ... a choice
with a type. `Comment::STATUS_PENDING`
(`src/Modules/Comments/Domain/Entities/Comment.php`) is a string constant
... truth without a compiler: PHP won't reject an unknown status, a test
(`CommentsTest`) insures it instead.

Where the language won't give truth a type, the contract is supplied by a
test. The same work, split cost.

**How long a truth holds.** In PHP the length of a truth is hidden — a
request arrives, the context lives, the process dies and every question dies
with it. Rust declares that length: a **lifetime** (`'a`) is a parameter
saying how long a reference may live. "How far out of context are we" stops
being a feeling ... it becomes an edge the compiler reads.

**A lock as frozen truth.** `Cargo.lock` and `lekalo.lock` are not a
catastrophe ... they're an advantage. The versions in the graph freeze and
every further build verifies against the frozen truth. This repository does
the same: `lekalo.lock` is committed, `lekalo-check` is a gate, and
`composer audit --locked` runs in CI. An audit without a freeze audits fog.

The price? Extra vocabulary. Stronger truth isn't free to write ... but it's
the same trade the tooling of part 3 makes.

## When Rust pays off (and when it doesn't)

Be honest: Rust is a poor fit for typical CRUD web applications. PHP's model —
request in, HTML out, shared-nothing processes — is *exactly* right for that
job. The starter kit exists because PHP is still the best tool for building
web apps fast.

Rust pays off in a different spot: **tooling**. Anything that is

- long-running (a server, a watcher, a daemon),
- compute-heavy (an analyzer, a compiler, a search index),
- or safety-critical (parsing untrusted input, handling secrets),

benefits from Rust's combination of performance and compile-time guarantees.
That's the classic "replace the shell script with a real tool" territory —
and it's exactly where **Lekalo** lives: a CLI that loads a model, builds a
graph, answers impact questions, and guarantees byte-identical output. The
kind of tool you'd hesitate to write in PHP (it would need a runtime, a
long-running process, careful memory handling) and that Rust makes boringly
safe.

## What's next

Before the tooling and the models, you need the one concept that makes Rust
*safe without a runtime*: **ownership**. It's the difference between "the
runtime checks this at runtime" and "the compiler checks this at compile
time" — and it's the thing most PHP developers find confusing on day one,
because PHP's arrays quietly copy themselves and Rust's values quietly move.
That's [part 2](/en/blog/rust-ownership).

And at the end of the road, [part 6](/en/blog/lekalo-semantic-model) shows
the payoff: Lekalo analyzing *this* project's Users module — 53 semantic
definitions, 106 dependency edges, and an impact report that tells us what
touches the `User` entity before we touch any code.

## Sources

- [The Rust Programming Language](https://doc.rust-lang.org/book/) — official Rust book
- [Rust: what and why](https://www.rust-lang.org/what/rust) — official introduction
- [The Cargo Book](https://doc.rust-lang.org/cargo/) — build tool and package manager
- [Nativa Minimal Documentation](/docs/getting-started) — this kit's zero-dependency bootstrap background
