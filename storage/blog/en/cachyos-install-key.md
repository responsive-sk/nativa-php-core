---
title: "Arch packages for kids ... part 0: the installation key"
series: "Arch packages for kids"
series-part: "0"
date: "2026-09-25"
version: "1.0.0"
published: true
tags: ["arch", "linux", "cachyos", "installation", "security", "beginners"]
excerpt: "Part zero of the series: verify the ISO, write the installation key and install CachyOS. The step that decides everything after it — and the first place the series motto works, before the system even exists."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "cachyos-instalacny-kluc-pre-deti"
---

> This is part 0 of the "Arch packages for kids" series — the step before
> every command: verify the ISO, the installation key and installing
> CachyOS. Series map: [pacman](/en/blog/pacman-for-kids), [the trust
> ladder](/en/blog/repos-and-trust), [Flatpak as the
> exception](/en/blog/flatpak-for-kids), the [motto](/en/blog/key-trust-nothing),
> [verifying AI claims](/en/blog/ai-security-claims), sudo LAST and the
> [shell](/en/blog/shell-for-kids). Today — everything that must hold
> before the first package falls.

You are sitting in front of an old laptop with a decision to make. The
CachyOS website offers an **ISO image** — the whole system in one file —
and next to it a long string of characters that looks like noise.

This is the lesson where the noise becomes a tool: you use it to verify
the ISO, write the installation key and start the install. Step by step
— with the series principles that work here before the system has any
command at all.

## What the installation key is

An **installation key** is a USB stick the computer boots the installer
from. It is not a copy of the ISO file onto the stick — the image is
written so the machine can start a whole installation system from it.

CachyOS installs **online** — the installer downloads packages from the
repositories while it runs. That is why the wiki requires a stable
internet connection and lists 3 GB of RAM with 30 GB of disk as the
minimum. The recommended line is higher: 8 GB of RAM, 50 GB on an SSD
and an x86-64-v3 processor.

You need at least 8 GB of free space on the stick. And one honesty right
at the start: writing the image overwrites everything on the stick —
nothing survives.

## Verification: integrity and origin

Before you write anything anywhere, verify the ISO — with two tests,
each for a different attribute:

| Test | What it verifies | Success looks like |
|---|---|---|
| SHA256 | integrity — the file did not corrupt while downloading | `OK` |
| GPG signature | origin — the ISO was signed by the CachyOS team | `Good signature` |

Integrity: in the folder with the downloaded ISO, run the checksum.

```bash
sha256sum -c cachyos-desktop-linux-260809.iso.sha256
# cachyos-desktop-linux-260809.iso: OK
```

The number in the file name is the release version — yours will differ.
Take the `.sha256` file name from the same place as the ISO: the official
website, SourceForge or a mirror. The wiki says it without ceremony —
always take the verification step, so the install does not have a
problem already while the bootable medium is being made.

Origin: an unsigned ISO is just a file claiming to be CachyOS. The
signature is who takes responsibility for the file.

```bash
gpg --keyserver hkps://keys.openpgp.org --recv-key F3B607488DB35A47
gpg --verify cachyos-desktop-linux-260809.iso.sig cachyos-desktop-linux-260809.iso
# gpg: Good signature from "CachyOS <..."
```

A bad signature or a foreign key means do not use the ISO — the wiki
marks this as Danger and it is not scaremongering, it is a procedure.
Cryptography speaks a dry language: `OK`, `Good signature`. That is
exactly what [part 5](/en/blog/ai-security-claims) is about — verify
numbers, not tone.

## Writing the stick — sudo LAST

The motto of the series reads TRUST NOTHING. READ THE DOCS. sudo LAST —
and it works before the system it would be stuck next to even exists.
Reading (downloading, verifying) runs without admin rights. Writing the
stick needs them. The order is the same as with every package: reading
first, writing after.

The simplest path is balenaEtcher: pick the ISO file, pick the stick,
Flash. If it asks for the administrator password, it asks for the write
— not for the download.

If you want to see what happens, type it straight into the terminal:

```bash
# which disk is the stick? find it by name and size
sudo fdisk -l

# writing the image — replace <usbdrive> with the stick, e.g. sdb
sudo dd bs=4M if=cachyos-desktop-linux-260809.iso of=/dev/<usbdrive> status=progress oflag=sync
```

And here is the moment worth slow reading: `dd` writes where you tell
it, with no questions and no trash bin. The wrong disk behind `of=`
means lost data. So verification first, then `fdisk -l` — and check the
stick twice, by name and by size.

The wiki also describes Rufus (Windows) and Ventoy (Linux and Windows —
a stick that carries several ISOs at once). Every tool does the same
thing: it hands the machine the ISO in a way it can boot from.

## Installing, step by step

Boot from the stick and click Launch Installer. Three things from the
wiki first — they cost more later when skipped.

First, **UEFI mode**: Secure Boot and CSM must be disabled in the
BIOS/UEFI settings. Not sure? `efibootmgr -v` in the ISO terminal prints
EFI variables — if it answers that the system does not support them, the
machine booted in Legacy/BIOS mode.

Second, **manual partitioning**: the wiki prefers it — the "Install
alongside" and "Replace" options are not 100% reliable and can crash the
installation. For the Limine boot manager you need two partitions:

| Partition | Filesystem | Size | Mount | Flag |
|---|---|---|---|---|
| boot | FAT32 | ≥ 4096 MiB | `/boot` | boot |
| root | your choice (see the wiki) | ≥ 20000 MiB | `/` | — |

Then the installer leads by itself: language and timezone, keyboard,
desktop environment (chosen once — no installing several at once),
packages, login credentials. Read the summary carefully — Install Now is
the first click after which the way back leads through reinstalling.

## Honestly — when the key is not enough

Three situations where this procedure cannot help.

First, verification protects against a bad file — not against your hand.
`dd` writes without questions and the installer with manual partitions
can take the whole disk. The trust ladder from
[part 2](/en/blog/repos-and-trust) starts one rung lower here: during an
install you are rung 4 — and a backup is the only way back that works
when nothing else does.

Second, the online installer without a stable internet connection does
not install. Wi-Fi with a sign-in page is not a stable connection — the
install can die halfway. And after a failed run the wiki recommends
booting the ISO again: a failed install unmounts partitions incorrectly
and further attempts fall into errors.

Third, an old machine without x86-64-v3. CachyOS optimizes packages for
new microarchitectures and recommends x86-64-v3 — processors from Zen
and Haswell upward. The minimum is lower, but when the machine does not
even reach that, honestly: there are distributions with lighter care.
The "for kids" simplification shortens the path, never the truth — and
the truth is that not every laptop is a CachyOS machine.

## What comes next

Part 1 — [pacman](/en/blog/pacman-for-kids). The system is installed and
waiting for its first command: how to find, read and install a package —
and why you never need to memorize names.

## Sources

- [CachyOS Wiki — Downloads and Validation](https://wiki.cachyos.org/cachyos_basic/download/) —
  SHA256, the GPG signature, official ISO sources
- [CachyOS Wiki — Preparation steps](https://wiki.cachyos.org/installation/installation_prepare/) —
  requirements, making the bootable stick (Etcher, dd, Rufus, Ventoy)
- [CachyOS Wiki — Installation Desktop/Laptop](https://wiki.cachyos.org/installation/installation_on_root/) —
  UEFI, manual partitioning, the install walkthrough
