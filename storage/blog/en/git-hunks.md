---
title: "Git hunks ... why git splits a diff into blocks, not lines"
date: "2026-08-14"
version: "1.0.0"
tags: ["git", "workflow", "tooling"]
excerpt: "What a hunk is, why git uses it instead of a plain list of lines, and how to work with it ... git diff, git add -p and a real case where a hunk forced us to think differently."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "git-hunky"
published: true
---

Everyone knows `git diff` ... it shows what changed. But when it comes to
finer control over git, we run into a word that looks like the English
"chunk" (a piece), and yet it's something else: **hunk**. What exactly
is it, why does git use it, and why does it matter for code review and
for splitting commits?

## Lines vs. blocks

If you imagined a diff naively, it would be a table: "line 12 deleted,
line 13 added, line 40 changed…". Git doesn't do that. Instead it groups
changed lines into **contiguous blocks** ... and each such block is called
a **hunk**.

```diff
@@ -24,6 +24,8 @@         ← hunk header
 function hello() {
     echo "Hi";
 }
+echo "How are you?";
+echo "It's a nice day today.";
 echo "Bye";
 }
```

The header `@@ -24,6 +24,8 @@` is a compact map of the change:

- `-24,6` ... in the **old** file the block starts at line 24 and has 6 lines
- `+24,8` ... in the **new** file the block starts at line 24 and has 8 lines
  (2 lines were added)
- lines without a sign are **context** ... unchanged surrounding code that
  git includes in the diff so you can see where the change lives

A hunk is therefore not just "a piece of data" (that would be a chunk)
nor a "hook" (that's a script run at events like `pre-commit`). A hunk
is a **bounded change with context** ... and context is exactly why git
splits a diff this way.

## Why context makes a diff readable

Imagine a file where someone changed a function on line 300. Without
context, the diff would show "line 300: changed" ... and you'd have to
open the file to figure out what it is. With context you see the
function and its signature:

```diff
@@ -295,7 +295,7 @@
-fun sendEmail(to, subject, body) {
+fun sendEmail(to, subject, body, attachments = []) {
     mailer.deliver(to, subject, body);
 }
```

You see what changed and **where** it lives ... without opening the file.
Context is exactly what distinguishes a hunk from a plain list of lines.

## Hunks in practice: git add -p

The most familiar place you'll meet hunks is interactive staging:

```bash
git add -p
```

Git walks the changed files hunk by hunk and asks:

```
Stage this hunk? [y,n,q,a,d,e,?]
```

- `y` ... stage this hunk
- `n` ... skip it
- `s` ... split the hunk into smaller ones (if there's unchanged context
  between the changes)
- `e` ... manually edit the hunk
- `?` ... help

Thanks to that, two different changes in one file (say a bugfix plus a
refactor) can become two clean commits ... without copying lines by hand.

## When a hunk is too big

A hunk can't always be split. If two changes are **right next to each
other** (no unchanged context between them), git merges them into a
single hunk and `git add -p` offers `e` (edit) instead of `s` (split).
Then you have two options:

1. **Manually edit the hunk** (`e`) ... trim the context and keep only
   the part you want to stage.
2. **Apply part of the change manually** ... restore the file to its
   original state, apply only one part and commit step by step (exactly
   what we did today when splitting an archive: `git restore` + `awk`
   on one part of the hunk, commit, then the second part, commit).

Important: `git add <file>` stages the **whole file**, not part of a
hunk. If you want to separate two changes in one file, `git add -p` is
the right tool ... and if the hunks can't be split, go with option 2.

## Why bother

Hunks aren't theory ... they shape how you do code review and how you
write commit history:

| Approach | Result |
|---|---|
| `git add <file>` | One commit = everything in the file (even unrelated changes) |
| `git add -p` | One commit = one logical change, even from the same file |
| Manual split (`restore` + apply) | Works even where a hunk can't be split |

And in code review? When someone sends a PR that changes "just a few
lines" in five places, you're looking at exactly the **hunks** ... changes
with context. GitHub and GitLab do this for you. Understanding them
means understanding what you're actually reviewing.

## Summary

- **Hunk** = a contiguous block of changed lines with context (not a
  chunk, not a hook)
- The header `@@ -old,count +new,count @@` tells you where the block
  starts and how many lines it has
- Context makes a diff readable ... you see where the change lives
- `git add -p` stages hunk by hunk → clean commits from a single file
- When a hunk can't be split (changes right next to each other), edit it
  manually or split the commit by hand

Next time you see `@@ -1324,3 +1324,104 @@` in a `git diff`, you'll know
how to read it: "in the old file the block is at line 1324 (3 lines), in
the new one at 1324 (104 lines) ... and git treats it as one change."

## Sources

- [Git — git-diff Documentation](https://git-scm.com/docs/git-diff) — official diff documentation
- [Git — git-add Documentation](https://git-scm.com/docs/git-add) — `git add -p` interactive staging
- [Pro Git Book — Distributed Git](https://git-scm.com/book/en/v2/Distributed-Git-Distributed-Workflows) — distributed workflows
- [GitHub — Interactive Staging](https://docs.github.com/en/get-started/using-git/about-git#interactive-staging) — interactive staging tutorial
- [Atlassian — Git Diff Tool](https://www.atlassian.com/git/tutorials/difftool) — diff tools and visualization
