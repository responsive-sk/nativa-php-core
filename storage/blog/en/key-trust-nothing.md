---
title: "Arch packages for kids ... part 4: the key on the monitor"
series: "Arch packages for kids"
series-part: "4"
date: "2026-09-24"
version: "1.0.0"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "The fourth lesson of the series: the motto TRUST NOTHING. READ THE DOCS. sudo LAST. — three rules, each with an example from the earlier parts, and why we write them on paper next to the screen, not in our heads."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "kluc-trust-nothing-pre-deti"
---

> [Part 1](/en/blog/pacman-for-kids) showed the first command, [part 2](/en/blog/repos-and-trust) sorted the trust ladder, [part 3](/en/blog/flatpak-for-kids) opened Flatpak as the exception. Today part 4 — the motto of the whole series, cut down to three lines worth sticking next to your monitor.

In the chat this series was born from, one sentence fell that matters more than every command in the previous parts:

> the first thing you stick on your monitor ... art generators lie ...

From it grew the rule of the whole series. Three lines, each short, each proven in practice:

> **TRUST NOTHING. READ THE DOCS. sudo LAST.**

This lesson invents nothing new — it joins what you already know into a form that survives even the worst day.

## TRUST NOTHING

The first rule does not say "trust nobody" — it says "nobody decides for you".

The trust ladder from [part 2](/en/blog/repos-and-trust) has four rungs because trust is not one decision — it is a chain. On rung 1 a tool verifies the signature, but you chose the distribution whose keys you let into your system. On rung 2 you read recipes like letters. On rung 4 nobody stands between you and the package.

| Rung | Who decides for you | What stays on you |
|---|---|---|
| 1 | the distribution team + the tool | choosing the distribution |
| 2 | the community writes recipes | reading the recipe |
| 3 | the app publisher | reading the permissions |
| 4 | nobody | everything |

"TRUST NOTHING" therefore means: see the whole chain. A package pacman verified is trustworthy only as long as you trust those who signed it.

## READ THE DOCS

The second rule is the tool of the first. Do not trust — verify. And verification has an address.

Every part of this series ends with a Sources section and none of them is decoration. The rule about partial upgrades from part 1 stands on the Arch Wiki. The sandbox permissions from part 3 stand in the Flatpak documentation. When something does not work, the first stop is not the chat — it is the tool's documentation.

Documentation has three properties the chat does not: it is verifiable (it lives in one place), it is updated (when a new version ships) and it does not flatter. It will not return the answer you want to hear — it returns the answer that is.

## sudo LAST

You know the third rule from [part 1](/en/blog/pacman-for-kids): searching runs without admin rights, writing with them. "LAST" means two things at once — sudo is the final step of an action and the last option you reach for.

You type `pacman -Ss telegram` — without sudo. You find, you read, you compare. Only when you know exactly what you are installing and from where comes `sudo pacman -S telegram-desktop`. sudo at the end is a consequence of the order: reading first, writing after.

That is also why the motto says "sudo LAST", not "sudo never". Installation and updating cannot happen without admin rights — the rule is not dogma, it is an order.

## Why paper and not your head

You might think you will remember three rules. You will remember them — in peace.

Under pressure it works differently. When someone drops a list full of CVE numbers and the words "root access" in front of you, fear decides before knowledge does. The paper next to your monitor does not decide for you. It does one thing: it stands there even in the moment your head cannot give a reliable answer.

And one more thing. A rule you must phrase to fit three lines, you must first understand. Writing is a comprehension check — "TRUST NOTHING" survives on paper only if you can say what it means.

## Honestly — when the key on the monitor is not enough

Three situations where three lines cannot help.

First, the motto is not a replacement for documentation. "READ THE DOCS" is an instruction to read — not a claim that three words suffice. A new situation needs new reading, not reciting an old rule.

Second, paper nobody reads is wallpaper. The key on the monitor works only as long as you occasionally look at it and check what you are doing.

Third, the rules do not resolve conflicts among themselves. When the documentation is old and the chat is new, the motto will not tell you which one to believe — there, only the procedure from the next part helps: verify numbers, not tone.

## What comes next

Part 5 — verify numbers, not tone. How a security claim gets verified: database, advisory, version comparison. "AI lies ... and is sometimes right — so verify numbers, not tone."

## Sources

- [Pacman — Arch Wiki](https://wiki.archlinux.org/title/Pacman) —
  documentation as the primary source of part 1's rules
- [Flatpak — documentation](https://docs.flatpak.org/en/latest/) —
  sandbox and permissions from part 3
- [CachyOS Wiki](https://wiki.cachyos.org/) — repositories and
  distribution conventions
