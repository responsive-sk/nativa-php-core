---
title: "Arch packages for kids ... part 6: sudo LAST"
series: "Arch packages for kids"
series-part: "6"
date: "2026-10-03"
version: "1.0.0"
published: true
tags: ["arch", "linux", "sudo", "cachyos", "security", "beginners"]
excerpt: "Sixth lesson of the series: the last rule of the motto. Where sudo belongs (pacman -S, -Syu, -Sc), where it does not (no sudo yay, no curl ... | sudo bash), why sudo is also sudo as little as possible, and what the timestamp cache does."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "sudo-posledne-pre-deti"
---

> [Part 1](/blog/pacman-for-kids) showed the first command, [part 2](/blog/repos-and-trust) sorted the trust shelf, [part 3](/blog/flatpak-for-kids) opened Flatpak as the exception, [part 4](/blog/key-trust-nothing) showed that a key trusts nothing, [part 5](/blog/ai-security-claims) said that an AI claim is an opinion until you check it. [Part 7](/blog/shell-for-kids) went back to the window all those commands run in. Today part 6 — sudo: the last rule that was left.

You type `sudo` and wait. Or you don't type it and move on. The difference between those two states is exactly this lesson — and most commands you use daily belong in the second group.

The series motto has said "sudo LAST" since the first part. Now we break it into two sentences, each with its own cost.

## sudo grants rights for a single command

**sudo** does not start a root shell. It does not hand you rights permanently — it grants them **for one command, for one line**. That is the whole point.

`su` is the opposite: you open a root shell, and from that moment every following command runs with root rights. `sudo` does not. The program starts, does its work, and the rights are gone.

The difference is small until it goes wrong. You type `sudo` and press Enter before you finish reading the command. With `sudo`, a mistyped or unwanted command keeps root rights for that one run. With `su` you would first have to open that shell — time in which you can still notice what you are typing.

sudo also records every attempt in the system log. Not for fun, so that it is possible to look at what happened on the machine afterwards.

And memory: after you type your password, sudo considers it valid for about five minutes (configurable via `timestamp_timeout` in `/etc/sudoers`). Inside that window further `sudo` will not ask. That is convenient — and also why "sudo as little as possible" is not just a slogan.

## Table: sudo yes, sudo no

| What you want | Command | Sudo? |
|---|---|---|
| Install a package | `pacman -S telegram-desktop` | **yes** |
| Update the system | `pacman -Syu` | **yes** |
| Free up cache space | `pacman -Sc` | **yes** |
| Search packages | `pacman -Ss firefox` | **no** |
| Read files | `cat /var/log/pacman.log` | **no** |
| Run an app | `telegram-desktop` | **no** |
| Read system state | `systemctl status NetworkManager` | **no** |
| Change the network | `ip link set wlan0 down` | **yes** |

A "yes" row means the command **writes to the system**: it changes packages, configuration, or the network. Without rights it will not work, and there is no way around that except by failing.

A "no" row means the command **reads**. And reading does not need rights — except for the case we get to below.

This holds for the tooling too, not just pacman. The rule is called **sudo LAST**:

- **L**ooking — reading, no rights
- **A**pplying — writing, with rights
- **S**aving — saving what you just changed
- **T**roubleshoot — fixing what broke

## No `sudo yay`

Write this down, even if nobody offers it to you:

```text
sudo yay -S package
```

No. `yay` is a helper that calls pacman. It runs with its own rights, exactly as it normally runs. When it needs elevated rights it asks by itself — and it asks properly, because it knows what it is doing.

When you put `sudo` in front of it, you get exactly what you would have got by dropping `yay` into an empty root shell. Both options are bad, only one of them is easy to mistype.

The same holds for every helper tool: an AUR helper, a graphical installer, a script you downloaded. If the tool handles elevation itself, do not hand it rights by hand.

## No `curl ... | sudo bash`

This is rule two, and it takes three lines to write:

```text
curl -sSL https://example.com/install.sh | sudo bash
```

Split the line in half and look at what happens.

The first half downloads a file. That is all it does — it downloads text. The second half runs that text with root rights. There is no window in between in which you could have read what you downloaded. You can do exactly the same thing by hand if you split it:

```text
curl -sSL -o install.sh https://example.com/install.sh
less install.sh
bash install.sh
```

Now you see what runs. And if something does not look right, `install.sh` stays on disk and nobody runs it for you.

In both cases the same code runs. The difference is that in the second case you read it first.

## And when something really needs it

There are commands where `sudo` belongs even for reading — and that is normal, not a mistake. `pacman -Sc` reads the cache, but it also deletes from it. `ip link set wlan0 down` finds out the network state and changes it.

The difference is that those commands **have their own check**. They are not being handed rights blindly — they ask for them because they know they need them, and they validate their own arguments.

And `visudo`? When you do change the rules, use `visudo`, not `nano`:

```bash
sudo visudo
```

visudo locks the file, writes the change to a temporary copy and **checks the syntax**. A wrong line in `/etc/sudoers` means sudo stops working entirely — and in `/etc/sudoers.d/` a failure is easier to undo than in the main file. `sudo -ll` shows you the current settings.

## Honestly — why you ask about the rules

Some people on forums write that `sudo` is a pointless obstacle and that if you do not need systemd you do not need sudo at all. Often they are right.

But on Arch you do not get `sudo` for free. The CachyOS installer sets it up and membership in the `wheel` group grants rights. And `sudo` has two side effects people rarely mention:

First, the password. Once `sudo` accepts your password it keeps it valid for five minutes. For those five minutes the terminal has full rights without asking.

Second, `sudo -s` and `sudo su` are bridges into a permanent root shell. Exactly what `sudo` was designed to avoid. `sudo -s` is momentary, `sudo su` is forever.

Neither is a vulnerability. They are exits that can be used, and therefore have to be known about. Someone who uses `sudo` for reading every day treats rights as a given — and that is exactly when it becomes easy to type one where it does not belong.

## What comes next

Eight parts and the motto is complete. But the motto says "sudo LAST", and `T` means troubleshoot — the part we have left to you until now.

Ever set up a network, run `pacman -Syu`, and nothing happened? This is exactly the place to try it. Come back with that specific case and we will look at it together.

## Sources

- [Sudo — ArchWiki](https://wiki.archlinux.org/title/Sudo) — rights for a single
  command, `visudo`, the `wheel` group and Polkit
- [sudoers(5) — man.archlinux.org](https://man.archlinux.org/man/sudoers.5.en) —
  `timestamp_timeout`, `Defaults` and changes in `/etc/sudoers.d/`
- [Sudo Home](https://www.sudo.ws/) — `sudo -s`, `sudo su` and how privileges work