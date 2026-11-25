---
title: "Rust and Lekalo ... part 3: rust tooling, workspaces, CLIs and output you can trust"
series: "Rust & Lekalo"
series-part: "3"
date: "2026-09-06"
version: "1.0.0"
tags: ["rust", "lekalo", "tooling", "cli", "architecture"]
excerpt: "Part 3 of the Rust series. Ownership was the mental model; tooling is how you live with it. We dissect the Lekalo CLI, a two-crate cargo workspace, clap parsing, an exit protocol (0/1/3/4/5) and byte-identical canonical output, and why deterministic output is the point."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "rust-cli-pre-deti"
published: true
---

In [part 2](/en/blog/rust-ownership) we learned the mental model: values are
owned, borrowed, and freed at scope end. That's the theory. This part is
about the practice, what a real Rust command-line tool looks like when you
open it up. And we have a perfect specimen at hand: **Lekalo**, the tool
that models this very project.

A warning before we start: you don't need to understand all of Lekalo to
follow this part. You need one idea, that a CLI can be built so that its
output is *trustworthy* in a way PHP tooling rarely is. Everything here is
in service of that idea.

## Cargo: build tool and package manager in one

PHP has Composer for dependencies and, well, PHP for everything else. Rust
has **cargo**, which is both: it resolves dependencies, builds, tests, runs,
and packages. You rarely touch `rustc` directly; you type `cargo build` and
cargo orchestrates the compiler.

Lekalo is a **cargo workspace**: one repository, multiple crates (Rust
packages), one shared lockfile and build directory. It has exactly two
crates:

- **`lekalo-core`**: where every decision lives: loading models, building
  the IR, constructing graphs, projecting impact. The core owns the rules.
- **`lekalo-cli`**: deliberately thin: it selects a command, renders the
  output, and maps exit codes. It contains no domain logic.

The division is architectural, not accidental. If the CLI is dumb, then every
behavior, graph traversal, impact projection, validation, is testable
without invoking the command line, and every command line is just a thin
projection of the same core. This mirrors what this repo does with its
modules: `Modules\Core` owns the kernel, and each feature module is a thin
slice on top. Same instinct, different language.

## Clap: parsing that never surprises you

Rust's de-facto CLI parser is **clap**. Lekalo uses it with a specific
choice documented in its CLI contract: *clap parsing always uses its
non-terminating parse path*. That means argument errors don't bubble up as
unexpected behavior, they're mapped to a defined failure: **exit 1**, never
clap's default exit 2.

Why does a detail like that matter? Because a tool that is *predictable
about failure* is a tool you can script. CI pipelines, editors, AI agents,
all of them branch on exit codes. If your tool sometimes exits 2 and
sometimes 1 depending on which layer caught the error, every consumer has to
handle both. Lekalo's contract collapses that to one stable answer: syntax
failure is exit 1, period.

## The exit protocol: 0/1/3/4/5

Speaking of exit codes, Lekalo defines a closed set, and it's worth reading
because it shows the level of intentionality this kind of tooling has:

| Exit | Meaning | Stream |
|---|---|---|
| `0` | valid / success | stdout |
| `1` | invalid (malformed input, usage) | stderr |
| `3` | well-formed but *denied* (policy denial) | stdout |
| `4` | recognized capability unavailable in this build | stdout |
| `5` | unsupported model contract version | stderr |

Two details stand out. First, **status owns the exit**: severity of a
diagnostic never computes the exit code, the DomainResult does. Second, a
*denial* (exit 3) is deliberately distinct from *invalid* (exit 1): a
well-formed request that violates policy is a different outcome from a
malformed one, and it goes to stdout with structured JSON, not stderr noise.
That distinction, invalid ≠ denied, is the kind of precision PHP
applications usually leave to convention. Here it's a contract.

## Output you can trust: canonical bytes

Here's the idea that makes everything click. Lekalo's core promise is
**deterministic output**: for the same input, the canonical model, the graph
export, the impact projection are *byte-identical* across reruns, across
frontends (YAML vs JSON), across platforms. `lekalo graph export --json`
produces exactly the same bytes every time.

The JSON wire contract spells out how: UTF-8, two-space indentation, exactly
one trailing line feed, and, critically, *no ANSI escapes, no timestamps,
no absolute paths, no echo of raw arguments*. Output is a pure function of
input.

Now think about what this repository does in `bin/verify-build.php`: it
*contracts* the build output (no `@layer`, ES2015, self-hosted assets) and
fails loudly when the contract is broken. Lekalo does the same to its own
output, but as a core property of the tool, not a post-build check. If two
runs of a tool disagree, you can't cache, can't diff, can't trust it in a
pipeline. If they always agree, the output becomes a value you can compare,
hash, and reason about, which is exactly what lets an AI agent treat
`lekalo graph export` as ground truth.

## The MSRV contract

One more deliberate choice: Lekalo pins its **MSRV** (minimum supported Rust
version) to `1.80.0` in `Cargo.toml`. The toolchain can move on; the project
won't silently require a newer one. That's the same instinct as this kit's
Chrome 70 baseline or its PHPStan level 9 gate: you declare the floor, test
against it, and never drift below it without a deliberate decision.

For a PHP developer the closest cousin is the `composer.json` `require`
section or a CI matrix that pins `php-version: 8.5`. Rust just makes the
floor part of the manifest itself.

## What this buys you

Put it together: a thin CLI on top of a tested core, clap parsing that never
surprises, a closed exit protocol, byte-identical canonical output, and a
pinned MSRV. Each is small; together they produce a tool that a machine can
drive blindly, and that's the entire point of the series. Lekalo is built
to be consumed by AI agents and pipelines that need *guarantees*, and every
one of those guarantees is a compile-time or contract-level decision, not a
hope.

Next part looks at how those guarantees are kept honest: tests, clippy,
MSRV verification and contract checkers, Rust quality in practice.

[next: Rust quality](/en/blog/rust-testing-quality)

## Sources

- [Lekalo, CLI contract](https://github.com/ichinya/lekalo/blob/main/docs/cli.md): commands, exit codes, JSON wire
- [The Cargo Book](https://doc.rust-lang.org/cargo/): workspaces and reproducible builds
- [clap](https://github.com/clap-rs/clap): Rust CLI argument parser
