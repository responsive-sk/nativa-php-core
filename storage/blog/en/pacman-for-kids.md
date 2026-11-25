---
title: "Arch packages for kids ... part 1: pacman"
series: "Arch packages for kids"
series-part: "1"
date: "2026-09-24"
version: "1.0.1"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "The first lesson of the series about packages on CachyOS: what a package is, what pacman does, why you never need to memorize package names and why one command updates the whole system."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "pacman-pre-deti"
---

> This is part 1 of the "Arch packages for kids" series, about packages
> on CachyOS and about whom you trust when you install them. Series map:
> [the installation key](/en/blog/cachyos-install-key) (part 0), pacman
> (today), [the trust ladder](/en/blog/repos-and-trust) between
> repositories, [Flatpak as the exception](/en/blog/flatpak-for-kids),
> the [motto](/en/blog/key-trust-nothing) "TRUST NOTHING. READ THE DOCS.
> sudo LAST." and [verifying AI security
> claims](/en/blog/ai-security-claims). Today, your first command.

You have a fresh CachyOS on your laptop. You want Telegram. You ask an
AI and it says ... install Flatpak.

Meanwhile you have pacman. It was there before you opened the chat. It
can find, verify, install, update and remove almost everything you will
ever need on Linux, from the command line, with no store, no ratings
and no screenshots.

This lesson shows you that command. And why you never need to memorize
package names.

## What a package is

A **package** is a box with an app inside. The box carries more than the
program, it carries metadata: name, version, description, the list of
dependencies and a **signature** confirming the box comes from where it
claims to come from.

**pacman** is the tool that drives those boxes. The name is short for
PACkage MANager. It searches, installs, updates and removes. On CachyOS
it is preinstalled, both Arch and CachyOS build their packages on it.

## Your first command

Installing looks like this:

```bash
sudo pacman -S telegram-desktop
```

Let's take it apart:

| Piece | What it does |
|---|---|
| `sudo` | installing writes into the whole system, that needs admin rights |
| `pacman` | the tool |
| `-S` | synchronize, find the package in a repository and install it |
| `telegram-desktop` | the package name |

When you run it, pacman finds the package and its dependencies, downloads
them, verifies the signatures and installs. No clicking, no website,
no "next, next, finish".

And you already know one rule for the coming parts: reading runs without
sudo, writing with sudo. Searching writes nothing, you run `pacman -Ss`
without admin rights.

## You don't memorize names

Why `telegram-desktop` and not `telegram`? Because that is the package's
name, and nobody expects you to remember it.

Instead of the exact name, type the root of a word you are sure about:

```bash
pacman -Ss telegram
```

The output lists everything containing that root, including what you
already have installed:

```text
extra/telegram-desktop <version> [installed]
    Official desktop client for Telegram
```

If a package shows up more than once, several repositories offer it.
CachyOS adds its own repositories on top of the Arch ones, that is why
you see `cachyos-extra-v3/` next to `extra/`. You don't memorize names;
you remember the root of a word and pacman finds the rest.

## One command for the whole system

On other systems every app ships its own updater, a dozen windows
begging for attention. Repository packages work differently:

| Approach | Updating |
|---|---|
| installer from a website | every app runs its own updater |
| pacman | one command, the whole system at once |

That one command:

```bash
sudo pacman -Syu
```

It updates the system, the libraries, the apps and their dependencies
together, because repository packages belong together and are released
as one whole.

And here is the rule to remember right away: never `-Sy` without `-u`.
Refreshing the package database without actually upgrading mixes new
metadata with old packages, that is exactly how systems break on the
Arch forums. Want to install one thing? Run `-Syu` first anyway. The
Arch Wiki says the same, a source this series trusts.

## When pacman is not enough

Honestly, three things worth knowing before you fall in love.

First, not everything is in the repositories. An app with a tricky
license or a small community project can be missing. Alternatives exist,
AUR (the community builds the packages themselves) and Flatpak (a
sandbox that brings its own risks). Both get their own parts.

Second, pacman has no store. No ratings, no screenshots, no "recommended
for you". Instead you get the package description, reading instead of
browsing. That is not a flaw, it is the deal.

Third, pacman does not decide trust for you. It verifies the signature
and tells you where a package came from. What that signature means and
whom you trust when the community built the package, that is part 2.

## What comes next

Part 2, the trust ladder. Official repositories, AUR, Flatpak and
"something a friend sent you": in what order they are safe, why the
ladder is sorted the way it is, and what the signature you saw during
installation actually means.

## Sources

- [Pacman, Arch Wiki](https://wiki.archlinux.org/title/Pacman): the
  full reference, including the rule about partial upgrades
- [pacman(8), manual page](https://man.archlinux.org/man/pacman.8)
- [Official Arch Linux package search](https://archlinux.org/packages/)
- [CachyOS Wiki](https://wiki.cachyos.org/): repositories and
  distribution specifics
