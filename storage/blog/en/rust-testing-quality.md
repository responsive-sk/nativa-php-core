---
title: "Rust and Lekalo ... part 4: rust quality, tests, clippy, MSRV and contracts you can check"
series: "Rust & Lekalo"
series-part: "4"
date: "2026-09-06"
version: "1.0.0"
tags: ["rust", "lekalo", "testing", "quality", "architecture"]
excerpt: "Part 4 of the Rust series. Part 3 promised output you can trust; this part shows how Lekalo keeps that promise honest, five cargo commands that gate every change, a pinned MSRV tested on three operating systems, hermetic fixtures with golden bytes, and Node.js contract checkers validating JSON Schema contracts."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "rust-testovanie-pre-deti"
published: true
---

[Part 3](/en/blog/rust-cli-tooling) described a CLI built around guarantees:
a closed exit protocol, byte-identical canonical output, a pinned MSRV. But
guarantees are only as good as the enforcement. This part is about the
enforcement, the quality machinery that keeps Lekalo honest, and how it
parallels what this repository already does.

## Five commands, zero mercy

Lekalo's development checks are five commands, and every one of them fails
the build on any violation:

```bash
cargo fmt --all -- --check                          # formatting is a gate
cargo check --workspace --all-targets --locked      # compiles, incl. tests/examples
cargo clippy --workspace --all-targets --locked -- -D warnings   # lints as errors
cargo build --workspace --locked                    # reproducible build
cargo test --workspace --locked                     # the test suite
```

Two details are worth unpacking for a PHP developer. First, `--locked`: every
command insists on the committed `Cargo.lock`: no silent dependency drift,
ever. That's `composer.lock` (or the frontend's frozen lockfile in `pnpm
install --frozen-lockfile`) elevated to a hard CI rule.

Second, `clippy ... -D warnings`: the linter's warnings are **errors**. Not
"please consider fixing this", the build dies. This is the same spirit as
running PHPStan at level 9 as a gate: the analyzer's opinion is part of the
definition of done, not a suggestion. This kit's `composer cs-fix-dry`,
`rector-dry` and `composer phpstan` occupy exactly that role, and its CI runs
them in parallel jobs, same instinct, same stakes.

## The MSRV is tested, not assumed

A floor you declare in `Cargo.toml` is a promise. A floor you *test* is a
fact. Lekalo's CI builds and tests on **Linux, Windows and macOS** with the
**exact Rust 1.80.0 toolchain**: not "whatever is latest", the pinned
minimum. If something breaks on the MSRV or on any platform, the pipeline
fails.

This repository does the same dance with its old-browser baseline: the Vite
build targets ES2015 and `bin/verify-build.php` verifies the shipped output
(no `@layer`, self-hosted assets), the declared floor is continuously
re-verified, not assumed. A contract without a check is a wish.

## Hermetic fixtures and golden bytes

Lekalo's core promise is deterministic output. How do you test that? With
**hermetic fixtures**: a `tests/fixtures/` tree where every subsystem,
loader, IR, model, graph, effects, context, trace, inspect, validation,
lockfile, structure, has its own fixture directory, and every expected
output exists as **golden canonical bytes**. The test is a byte comparison:
run the tool on the fixture, compare to the golden file, fail on any
difference.

There is no mocking, no network, no time-of-day in these tests. Same input,
same bytes, forever, which is precisely the property the tool promises in
production. If the output format changes, the golden files change with it,
deliberately, in the same commit.

## Contract checkers: an independent referee

Here's the most unusual part, and the one most worth stealing. The JSON
Schema contracts that govern Lekalo's model, lockfile, diagnostics, graphs
and more are validated by an **independent** checker suite written in
Node.js, a completely different runtime from the Rust core. The CI runs the
suite on **Node.js 18 and 24**, provisioning the **exact Ajv 8.17.1**
validator outside the checkout and exposing it through `NODE_PATH`.

Think about what that means: the format is not validated only by the code
that produces it. A second implementation, in a second language, with a
pinned third-party validator, independently confirms that every emitted
document matches the published contract. The Rust core and the Node checker
have to agree, if they drift, CI breaks. This repo does the equivalent when
the CSS suite audits the *built* output in `public/dist/` (WCAG AA contrast
computed from shipped tokens) instead of trusting the source CSS: verify the
artifact, not the intention.

## What this buys you

Every guarantee from part 3 has a corresponding enforcement: reproducible
builds via `--locked`, style via `fmt --check`, lints as errors via clippy,
the MSRV tested on three OSes, deterministic output tested against golden
bytes, and the wire contracts refereed by an independent Node suite. None of
it is optional, none of it is "best effort", the pipeline is the contract.

That's the mindset part 5 needs: if you're going to feed a tool's output to
an AI agent and trust its answers, the tool had better be this paranoid
about its own output. Which brings us to the layer on top of the language
entirely, the **semantic model**:

[next: semantic application models](/en/blog/semantic-application-models)

## Sources

- [Lekalo, CLI contract (Development checks)](https://github.com/ichinya/lekalo/blob/main/docs/cli.md): fmt/check/clippy/test gates
- [The Rust Book, Testing](https://doc.rust-lang.org/book/ch11-00-testing.html): unit and integration tests
- [Rust Clippy](https://github.com/rust-lang/rust-clippy): lints as errors
- [Ajv](https://ajv.js.org/): JSON Schema validator used by the contract checkers
