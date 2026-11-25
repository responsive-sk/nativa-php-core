---
title: "Rust and Lekalo ... part 2: ownership — the one idea that makes Rust safe without a runtime"
series: "Rust & Lekalo"
series-part: "2"
date: "2026-09-06"
version: "1.0.0"
tags: ["rust", "lekalo", "architecture", "beginners", "memory"]
excerpt: "Part 2 of the Rust series. PHP frees memory with a garbage collector; Rust frees it at compile time. The idea that makes that possible is ownership — and it behaves nothing like PHP arrays. We compare copy-on-write with move semantics and look at a real boundary from this repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "ownership-pre-deti"
published: true
---

In [part 1](/en/blog/why-rust) we said Rust has no garbage collector and no
runtime — memory is freed **at compile time**, deterministically. But that
claim hides a question: if there's no runtime sweeping memory, then *who*
decides when a value can be freed? The answer is the single most important
idea in Rust, and the one that feels most alien to a PHP developer:

**Every value has exactly one owner. When the owner's scope ends, the value
is freed.**

That's it. No collector, no sweeper, no "stop the world" pause. Just one
rule, enforced by the compiler, that turns memory bugs into compile errors.
This part explains the rule, how it differs from PHP's model, and what it
has to do with a method you've probably read in this repository.

## PHP: copy-on-write, invisible sharing

PHP arrays are famous for being cheap to copy. That's because PHP doesn't
actually copy them — it uses **copy-on-write**: two variables can point at the
same array in memory, and only when one of them *writes* does PHP duplicate
the data.

```php
$user = ['name' => 'Eva', 'roles' => ['admin']];
$copy = $user;                  // cheap — they share one array in memory
$copy['roles'][] = 'editor';    // now PHP copies, because a write happens
```

This is invisible and forgiving. You can pass the same array to five
functions and PHP figures out sharing and copying for you. You never think
about who "owns" `$user` — and that's precisely the problem. Rust's position
is: if nobody owns it, then *when* it is freed is somebody else's runtime
problem. PHP accepted a runtime (and a collector) to buy that freedom.

## Rust: move, not copy

In Rust, assigning a value to another name does not copy it — it **moves**
ownership.

```rust
let user = User::new("Eva");
let moved = user;   // ownership of user moves to `moved`
// println!("{user}"); // compile error: use of moved value
```

After the move, `user` is gone. The compiler knows it, and using the old
name is a compile error — not a crash, not a null, not an undefined value.
A compile error, before the program ever runs. That's the trade: PHP lets
you share everything and pays with a runtime; Rust makes ownership explicit
and pays with compile-time strictness.

Small values that are cheap to copy (numbers, booleans) implement `Copy` and
still copy on assignment. But anything with real weight — a string, a
database row, a request — *moves*. And if you want to *use* a value without
taking ownership of it, you borrow it.

## Borrowing: many readers, one writer

Ownership would be unusable if you couldn't share. So Rust has **borrowing**:
a reference `&T` that lets you read a value without owning it.

```rust
fn count_roles(user: &User) -> usize { user.roles.len() }

let n = count_roles(&user); // borrow — `user` is still owned and usable
```

The borrow checker is the part of the compiler that polices these
references. Its rules read like a bouncer's: either **one writer** or
**many readers**, never both at the same time. If you try to hand out a
mutable reference while another reference is still alive, the program
doesn't compile. Data races — two pieces of code mutating the same memory
concurrently — become impossible, because the compiler forbids the pattern
that causes them.

This is the "borrow checker" of part 1: the discipline PHP enforces with
warnings and discipline (or silently ignores) is enforced in Rust at compile
time, for every program, always.

## Scope is the free point

Because every value has one owner and the owner's scope ends at the closing
brace, memory is freed **when the scope ends** — the pattern is called RAII
(resource acquisition is initialization), and it extends beyond memory.
Files, locks, database connections: whatever you acquire, you release by
dropping the value. No `finally` blocks, no `unset()`, no forgetting.

```rust
{
    let handle = File::open("app.log")?; // acquired here
    // ... work with handle ...
} // handle is closed and freed here, automatically
```

Compare that with PHP, where you close a file with `fclose()`, release a
lock with `unlock()`, and hope you remembered. Rust's answer: the scope *is*
the cleanup.

## A boundary from this repo: `User::fromRow()`

Here's the connection to code you can open right now. In
`src/Modules/Users/Domain/Entities/User.php`, the entity is created from a
raw database row:

```php
public static function fromRow(array $row): self
{
    return new self($row);
}
```

Look at what the constructor does with that raw row: it normalizes
`is_active` into a real boolean once, and `last_login_at` into a nullable
string once. The comment in the code says it best: *"Normalized once at the
boundary — no re-guessing per call."*

This is ownership thinking, in PHP clothing. The raw row **crosses a
boundary** (the database → the domain), and at that boundary it is consumed
and converted into a typed, owned object. After that, the row's raw shape
is nobody's business — the entity owns its state and no longer re-derives
it. Every caller gets a consistent, normalized `User` instead of guessing
what `$row['is_active']` might have been.

In Rust, that same boundary would be explicit in the type system: a function
that *consumes* a row and returns an entity —

```rust
fn from_row(row: Row) -> User { /* row is moved in, consumed, gone */ }
```

— makes it impossible to use the row afterwards, by construction. The
normalization happens once because the row physically cannot be read twice.

The principle is the same in both languages: **normalize at the boundary,
then own the result.** Rust just has a compiler that enforces the "once".

## What ownership buys you

- **No use-after-free** — the compiler knows a value's lifetime and refuses
  code that touches freed memory.
- **No double-free** — one owner means one free, by construction.
- **No data races** — the borrow checker's one-writer rule is checked at
  compile time.
- **Deterministic cleanup** — scope is the free point; no GC pauses.

PHP doesn't have these bugs because the runtime prevents them — at the cost
of a runtime. Rust doesn't have them because the compiler prevents them — at
the cost of learning to think in owners and borrows. Part 1's claim was that
this makes Rust the right tool for long-running, safety-critical tooling.
Lekalo, the tool at the end of this series, leans on exactly these
guarantees: a CLI that parses models, builds graphs, and must never corrupt
memory while doing it.

## What's next

Ownership is the mental model; **tooling** is how you live with it
day-to-day. Part 3 looks at cargo workspaces, CLIs, and one thing Rust does
better than almost anything else: deterministic, byte-identical output —
which is exactly what makes Lekalo's `graph export` reproducible:

[next: Rust tooling](/en/blog/rust-cli-tooling)

## Sources

- [The Rust Book — Understanding Ownership](https://doc.rust-lang.org/book/ch04-00-understanding-ownership.html) — ownership, borrowing, move semantics
- [The Rust Book — Smart Pointers](https://doc.rust-lang.org/book/ch15-00-smart-pointers.html) — RAII and ownership of resources
- [PHP Manual — Arrays](https://www.php.net/manual/en/language.types.array.php) — copy-on-write background
