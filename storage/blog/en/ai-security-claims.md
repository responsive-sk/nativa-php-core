---
title: "Arch packages for kids ... part 5: verify numbers, not tone"
series: "Arch packages for kids"
series-part: "5"
date: "2026-09-24"
version: "1.0.0"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "The fifth lesson of the series: a security claim gets verified with numbers, not tone. Four steps: database, advisory, version comparison, decision."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "ai-bezpecnostne-tvrdenia-pre-deti"
---

> [Part 1](/en/blog/pacman-for-kids) showed the first command, [part 2](/en/blog/repos-and-trust) sorted the trust ladder, [part 3](/en/blog/flatpak-for-kids) opened Flatpak as the exception, [part 4](/en/blog/key-trust-nothing) glued the motto onto the key on the monitor. Today part 5 — the rule that keeps the whole series on the ground: a security claim gets verified with numbers, not tone.

A security claim always arrives the same way: someone pastes it into a chat, an AI writes it, or you find it on a forum. It sounds certain, speaks of root access and ends with an urgent "update now". This is exactly the moment the motto from [part 4](/en/blog/key-trust-nothing) prepared the paper next to your monitor for — and this lesson shows what to do when you read it.

## Verify numbers, not tone

Tone is the cheapest part of any answer. A confident tone does not mean a confident truth — and an alarming tone does not mean a lie. The only thing separating fact from fiction is a number that leads to a record.

The rule reads: **every security number must be found in a database not owned by whoever served it to you.** If the number is found, you have an official record. If it is not found, you do not have a fact — you have a string.

The procedure has four steps and each has its place:

| Step | Where | What you get |
|---|---|---|
| 1. find the number | NVD, security tracker, project advisories | an official record — or silence |
| 2. read affected/fixed | the same record | the versions concerned |
| 3. compare with the system | `pacman -Si flatpak` | the version available to you |
| 4. decide | you | update / sleep well |

## What a record looks like and what pressure looks like

An official record speaks a dry language: number, product, affected versions, fixed versions, link to an advisory, discoverer's name. No dramatic sentences, no exclamation marks — its job is precision, not fear.

That difference is the test. A warning appeals, a record describes. If a text pushes you toward an instant decision under fear and gives no number leading into a database, it is not a source — it is pressure.

If the number is found, the third step decides whether the flaw concerns you. `pacman -Si flatpak` prints the version in the repository and `sudo pacman -Syu` moves it up. If your installed version reaches or exceeds the fixed one, the problem does not concern you — an updated system is the answer to most advisories in history.

## AI lies ... and is sometimes right

Honesty to both sides.

Sometimes the AI is right: the flaw exists, it is serious and updating is due. The advice "check your version and update" is always good — because updating is always the right answer, even when the evidence is missing.

Sometimes it lies: numbers get filled in, the format gets borrowed from a database and the certainty gets borrowed from itself. That does not mean bad intent — language models store patterns, not records. A CVE list is a pattern; the numbers inside are filler.

That is why the motto does not read "never trust AI" or "trust AI". It reads: verify numbers, not tone. AI is a good guide and a bad source — a guide shows you the way, a source carries responsibility for every number.

## Honestly — where the procedure breaks

Three situations where the four steps are not enough.

First, NVD can lag. New flaws appear in a project's advisory before they get a CVE record — which is why the project's advisory is the first stop and the database the second. Silence in NVD is not a verdict, it is one data point.

Second, a number without context misleads. An existing record does not mean it concerns you: read the affected versions and the exploitation path. A record about a server flaw does not concern a desktop machine at all — and that, too, is an answer.

Third, the procedure does not judge intent. You find out whether a number exists — not why somebody wrote it. For that, a different test helps: if someone pushes you toward a fast decision under fear, the tempo is the warning. Security truth has time to wait for verification.

## What comes next

Part 6 — sudo LAST. The motto's last rule as its own lesson: when sudo belongs (`pacman -S`, `-Syu`, `-Sc`), when it does not (no `sudo yay`, no `curl … | sudo bash`) and why "sudo LAST" also means "sudo the least".

## Sources

- [NVD — National Vulnerability Database](https://nvd.nist.gov/) —
  the CVE database, step 1 of the procedure
- [Arch Security Tracker](https://security.archlinux.org/) —
  advisories and affected/fixed versions for Arch packages
- [Flatpak security advisories — GitHub](https://github.com/flatpak/flatpak/security/advisories) —
  the Flatpak project's official advisories
- [Pacman — Arch Wiki](https://wiki.archlinux.org/title/Pacman) —
  version comparison and updates
