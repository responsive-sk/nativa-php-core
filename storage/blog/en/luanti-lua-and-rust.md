---
title: "Luanti, Lua and Rust ... three languages, one boundary"
date: "2026-09-17"
version: "1.0.0"
tags: ["luanti", "lua", "rust", "architecture", "games"]
excerpt: "A voxel game is an architecture lesson with a GUI. The C++ core breaks nothing, Lua mods carry extensibility, map.sqlite keeps the world alive between sessions ... and each of the three zones knows exactly what it trusts. This repo plays the same game in other languages — from deterministic seeds to state out of context."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "luanti-lua-a-rust"
published: true
---

You're a developer. You launch a voxel game ... Luanti (formerly
Minetest), cubes, digging, building. And your brain says "game", because
it looks like one.

You're not entirely wrong. It's a game that is simultaneously an
architecture lesson with a GUI.

Under the hood live three languages, and each has a precisely drawn zone
of what it trusts. The same pattern this repository uses for its blog,
its tests and its agents ... just with cubes.

## Three languages, three trust zones

Luanti isn't written in one language. It's a division of labor that
looks like a boundary textbook:

| layer | language | what it trusts |
|---|---|---|
| engine — rendering, networking, meshing | C++ (compiled) | no one — input arrives from the network |
| mods — world logic, rules, blocks | Lua (sandboxed in LuaJIT) | only the API the engine exposes |
| world — blocks, players, inventories | `map.sqlite` | the bytes on disk |

The core is compiled and native because it must be fast — drawing
thousands of cubes, meshing chunks, pushing packets. Nothing there is
refactored at runtime.

Mods are written in Lua because extensibility wants a different
language — dynamic, no compilation step, runnable immediately. But they
never touch the world's memory directly. Mods call only the API the
engine hands them. The boundary isn't a word ... it's a capability list.

And the world itself doesn't live in memory or in a language. It lives
in SQLite.

## A world from the same seed

The most beautiful thing about Luanti is generation. From a single seed
comes the same world — today, in a year, on another machine. The seed is
a contract: same input, same output, a byte-deterministic reality.

This repository does the same with covers. `CoverGenerator` hashes an
identity (`series:` or the slug) into a (template, palette) pair — the
same article renders the same SVG, twice, always:

```php
// src/Modules/Blog/Application/Services/CoverGenerator.php — identity
$seed = null !== $series && '' !== trim($series) ? $series : $slug;
$index = abs(crc32($seed)) % count(self::IDENTITIES);
```

A world seed, a cover seed. No timestamps, no chance ...
determinism is a property, not a bonus.

And `map.sqlite`? This repo has its own family — `users.db`,
`config.db`, `audit.db`. The world lives on disk, not in a session. The
player logs off, the client closes, the world remains. Exactly the law
we wrote in the [state out of
context](/en/blog/state-out-of-context) article: state doesn't live in
a window. It lives in files.

## A sandbox is not trust

Watch out for one common misunderstanding.

The Lua sandbox in Luanti doesn't exist because the engine distrusts
mods' intentions. Mods are installed by a server admin — they are
hosted code someone approved.

The sandbox is a **capability list**. A mod may do what the API hands
it — read blocks, change inventories, send messages. It may not disrupt
the process, because the process never exposes that. So the boundary
isn't "we don't trust you in our hearts" ... it's "here are exactly
these hands".

This repository draws boundaries the same way — through capabilities,
not hopes. Upload doesn't trust a filename, it asks the bytes
(`finfo`). A comment starts as pending and waits for moderation. An
agent reads the revision report from the git content model, because
mtime is a false trail. Each of these boundaries is a list of what the
input may do ... not a prayer it won't do something else.

## When Lua isn't enough (and where Rust enters)

Lua in Luanti has its ceiling. A hot loop — thousands of iterations over
block trees — spends its time crossing the API boundary and inside the
interpreter. The engine therefore gradually moves the hot parts down
into native C++.

In web development the map is identical, only the names change:

| work | language here | why |
|---|---|---|
| request in, HTML out | PHP (this kit) | shared-nothing, fast iteration |
| long-running analyzer, compiler, CLI | Rust (Lekalo) | compile-time contracts, determinism |
| extensibility, scripting | Lua/... | dynamics without compilation |

PHP is here the engine for the web request — exactly like C++ is the
engine for rendering. Rust isn't pushed into the web app's core; it
lives in tooling, where the strength of contracts pays off (part 1 of
the series wrote a whole section about it: [truth carries a
type](/en/blog/why-rust)). And Lua has its place wherever you want
dynamics — you just have to admit the price: an interpreter and a
boundary.

Three languages aren't chaos. They're three answers to three questions:
**how fast**, **how safely extensible**, **how long the truth holds**.

## When it doesn't hold (the mandatory honesty)

Be honest: this repo doesn't embed Lua, and it won't.

No extra templating language, no plugin runtime — the zero-dependency
philosophy means boundaries get solved with processes and tests, not
another interpreter. Luanti takes Lua because it's a game with thousands
of mods. This kit has no mod ecosystem.

The second honesty: Luanti isn't a showcase security model against
hostile code. A mod in the sandbox is a trusted guest with limited
hands — hostile code is defended against elsewhere (and this repo too
handles foreign input in comments and uploads, not in plugins).

And the third: "Rust would make the engine better" is a claim without a
type. The C++ engine has run for a decade with all that history behind
it. A language is not an argument by itself — a contract is.

## Practically

1. **Download Luanti and look at a mod** ... any mod's `init.lua` is a
   list of API calls — read it as a capability list, not as code.
2. **Absorb the world from a seed** ... create a new world with the
   same seed twice. Then run `bin/cms generate-images` twice and check
   `git diff` — determinism has a test in this repo, not an impression.
3. **Find the boundary in your project** ... one question: which input
   today trusts more than it should? That's where your sandbox is
   missing.

Three languages then aren't a game ... they're a map of who trusts whom.

## Sources

- [Luanti — official documentation](https://docs.luanti.org/) ... engine, modding API, world format
- [Lua 5.1 — Reference Manual](https://www.lua.org/manual/5.1/) ... the language the engine embeds
- [LuaJIT](https://luajit.org/) ... the Lua JIT compiler mods run on
- [The Rust Programming Language](https://doc.rust-lang.org/book/) ... compile-time contracts
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
