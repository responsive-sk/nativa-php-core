---
title: "Arch packages for kids ... part 2: the trust ladder"
series: "Arch packages for kids"
series-part: "2"
date: "2026-09-24"
version: "1.0.0"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "Where packages come from and whom you trust when you install them: official repositories, AUR, Flatpak and \"something a friend sent you\" — the trust ladder sorted by risk."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "repozitare-a-dovera-pre-deti"
---

> [Part 1](/en/blog/pacman-for-kids) showed the first command and the
> signature you saw during installation. Today part 2 — the trust
> ladder: why packages from official repositories sit on the first
> rung, what AUR and Flatpak are, and why the ladder is sorted the
> way it is.

In part 1 you installed Telegram through pacman. Between the command
and the result ran a silent process — pacman verified that the box
comes from where it claims to come from.

In this part we look at where packages can come from. Not all
directions are equally safe — and that is why you remember the ladder
as a whole.

## The trust ladder

Four places software comes from on CachyOS, sorted from the safest:

| Rung | Where packages come from | Who builds them | Risk |
|---|---|---|---|
| 1 | official repositories | the distribution team | low |
| 2 | AUR | the community | medium |
| 3 | Flatpak | the app developers | medium |
| 4 | "something from the internet" | unknown | highest |

Why a ladder and not a flat surface? Because every rung answers the
question **who is accountable for the package** — and on the first
rung the answer is the clearest.

## Rung 1 — official repositories

The packages you see through `pacman -Ss` come from the `core`,
`extra` and other repositories — CachyOS adds its own
(`cachyos-extra-v3`).

Every package carries a signature. The key is a **GPG key** — a small
file the signer uses to confirm the box is theirs. Pacman knows the
repository signers' keys in advance and refuses a package without a
valid signature:

```text
error: telegram-desktop: signature from "..." is unknown trust
```

It refuses a package signed with a key it does not know just the same:

```text
error: failed to commit transaction (invalid or corrupted package)
```

That is why rung 1 is the lowest risk: the package is built by someone
who signs under their own name — and the verification is done by a
tool, not by you.

## Rung 2 — AUR

**AUR** is the Arch User Repository. It is not a repository of
packages — it is a repository of **recipes**. Each recipe is called a
`PKGBUILD`: a short script that tells the system where to download the
source code and how to package it.

The community writes the recipes. Anyone can add one — that is why
AUR is bigger and riskier at the same time. Pacman verifies a
repository package automatically; a recipe from AUR must be read by
you before use.

The most common beginner mistake — and the reason we spell it out in
reverse: a package from AUR is not installed through
`sudo pacman -S`. Pacman only installs packages from repositories; a
recipe has to be built first, and that is the job of AUR helpers like
yay or paru. How to read recipes is a topic for its own series — this
lesson needs one rule only: **read a recipe like a letter, not like
spam.**

## Rung 3 — Flatpak

**Flatpak** ships applications in sandboxes — isolated boxes with a
limited view of the system. That sounds safe, and often it is.

But a sandbox is also machinery. During system-wide installation a
privileged helper works inside it — and Flatpak's history has had
periods when attackers managed to escape their sandboxes. Some of
those bugs were serious and got fixed urgently.

Part 3 looks at Flatpak up close — how the sandbox works, when
Flatpak makes sense and how you keep control. For now the CachyOS
convention holds: **native packages first — Flatpak only when a
native package does not exist.**

## Rung 4 — "something from the internet"

The fourth rung is not about a tool, it is about traps. "Download the
executable", "run this script", "install with curl … | bash".

The point of the ladder: the lower you go, the fewer people stand
between you and the package. On rung 1 it is the distribution team
and its signatures. On rung 4 it is nobody — and the only thing left
is your own judgment.

If someone sends you a "simple installer" that bypasses pacman, ask:
why? Most answers belong on rungs 1–3.

## When the ladder can be bypassed

Honestly — the "native first" rule is not dogma.

Smaller and commercial apps sometimes have no native package. Then
AUR or Flatpak comes in — the ladder is not a border, it is the order
in which you look. The Flatpak version can be much newer than the one
in the repositories; if that one newer version matters to you, rung 3
is a legal choice — with the security rules coming in part 3.

And rung 4 has one exception: CachyOS itself is installed from an ISO
image on the distribution's website. Verifying the ISO signature is
the same discipline as the ladder — just at the highest level.

## What comes next

Part 3 — Flatpak as the exception. How the sandbox works, why the
privileged helper deserves attention and what "native first" means
exactly.

## Sources

- [Pacman — Arch Wiki](https://wiki.archlinux.org/title/Pacman) —
  the Package signing and Package security chapters
- [Arch packaging standards — Arch Wiki](https://wiki.archlinux.org/title/Arch_packaging_standards) —
  what the repositories mean and who stands behind them
- [AUR guidelines — Arch Wiki](https://wiki.archlinux.org/title/AUR_guidelines) —
  rules for reading and writing PKGBUILDs
- [Flatpak — sandbox documentation](https://docs.flatpak.org/en/latest/sandbox-permissions.html)
- [CachyOS Wiki](https://wiki.cachyos.org/) — repositories and
  distribution conventions
