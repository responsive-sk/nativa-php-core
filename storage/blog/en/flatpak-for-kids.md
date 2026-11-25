---
title: "Arch packages for kids ... part 3: Flatpak as the exception"
series: "Arch packages for kids"
series-part: "3"
date: "2026-09-24"
version: "1.0.0"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "The third lesson of the series: how a sandbox limits what an app can see of your system, why installation follows different rules than running, and what \"native first\" means exactly."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "flatpak-pre-deti"
---

> [Part 1](/en/blog/pacman-for-kids) showed the first command, [part 2](/en/blog/repos-and-trust) sorted the places packages come from, the trust ladder. Today part 3: Flatpak, rung 3 of the ladder. How the sandbox works, why installation deserves attention and when the exception to "native first" is a legal choice.

In part 2 you saw that Telegram exists in the official repositories too,`pacman -S telegram-desktop` installs it without Flatpak. Back then you installed it through Flatpak anyway, because that is what the AI suggested.

It is not a mistake to be ashamed of. It is, however, exactly the case the ladder exists for: the package sat on rung 1 and you reached for rung 3. This lesson shows what happens in between, and when rung 3 is the right choice.

## What a sandbox is

A **sandbox** is a rented apartment for an app. The app lives, runs and works inside it, but its view outside is limited. The Flatpak documentation lists what the apartment sees by default:

| What the app sees | Sandbox default |
|---|---|
| host files | only the app's own directories |
| network | none |
| devices | none (except `/dev/null` and similar) |
| processes outside the apartment | none |
| host services (D-Bus, X11, sound) | none |

The app can ask for these things, most of them need them, otherwise it would not run. Enter the **portal**: a system dialog that asks you. When you pick a file in it, your pick is the permission, the app gets exactly that one file, not the whole disk.

And here is the sentence to remember: sandbox permissions are **static**. They are written once into the app's manifest, no variables, no expansion. What the developer wrote into the manifest is what the apartment has. So you read the permissions the same way you read recipes in part 2, like a letter, not like spam.

## Why installation deserves attention

The sandbox protects the app while it runs. Installation is a different kind of activity, and it follows different rules.

Flatpak knows two modes: **system-wide** (the default, every user of the machine sees the app) and **per-user** (`--user`: only you). A system-wide installation has to write into system directories, and a process inside an apartment without a view outside cannot do that for you. A **system helper** does it instead: a component that runs with administrator rights.

That is not a design flaw, nothing without administrator rights could perform a system installation. But it is a price: the door into the system opens every time you install, not only while you run.

Flatpak's history has had bugs through which an app escaped its sandbox further than it should, some of them concerned exactly the path between the sandbox and the system. They were fixed; the rule from them remains:

| Activity | What protects it | Your attention |
|---|---|---|
| running an app inside the sandbox | apartment isolation | low |
| system-wide installation | the system helper under admin rights | high |
| updates | the same channel as installation | high |

One more look at the same thing: pacman installs packages whose signatures a tool verifies. Flatpak on rung 3 installs from the publisher's repository, the trust stands on the publisher's ID and on the remote you added. `flathub` is the main verified repository; remotes added from tutorials on the internet are rung 4 in a rung 3 mask.

## Two commands and one ID

Installing from Flathub:

```bash
flatpak install flathub org.telegram.desktop
```

`org.telegram.desktop` is the **application ID**: a three-part name where the last part is the app's name and the preceding parts say who publishes it. It is not a package name; `flatpak search telegram` finds it when you do not know the exact ID.

Updating everything you have through Flatpak:

```bash
flatpak update
```

And checking what you actually have:

```bash
flatpak --version
flatpak list --app
```

The rule this lesson exists for: **pacman does not update Flatpak.** Repository packages are updated by `pacman -Syu`: Flatpak apps have their own update channel. Whoever uses Flatpak and does not update stands still, with old versions of everything they ever installed through it. One command, regularly: `flatpak update`.

## The "native first" rule

The CachyOS convention from part 2, native packages first, now sounds exact:

| Situation | What you do |
|---|---|
| a native package exists in a repository | `pacman -S`: rung 1 |
| no native package, the AUR recipe is maintained | an AUR helper, rung 2 |
| neither native nor AUR, the publisher ships Flatpak | `flatpak install flathub …`: rung 3 |
| nobody packages anything | look for another app, ignore rung 4 |

When rung 3 is the right choice: the app is not in the repositories or in AUR; or the Flatpak carries a much newer version you are waiting for; or the app brings its own libraries that would fight the ones on your system. These are exceptions, and exceptions work when they have rules: install from a verified remote, read the permissions, update.

## Honestly: when Flatpak is not the right choice

Three cases where you avoid rung 3:

First, a native package exists and you are missing nothing. Installing through Flatpak adds a second copy of the app, a second update channel and a second place to look in when something does not work.

Second, you care about system integration. The sandbox is a wall even for the things you want, integration with the system theme, with dialogs and with other apps runs through portals, not directly.

Third, you do not want one more rule to remember. The ladder is simple precisely because it has few exceptions. Every tool you add, you also have to update, and Flatpak does not take that job away, it only moves it to itself.

## What comes next

Part 4, the key on the monitor. The series motto "TRUST NOTHING. READ THE DOCS. sudo LAST.", three rules, each with an example from the earlier parts, and why we write them on paper next to the screen, not in our heads.

## Sources

- [Flatpak, Sandbox permissions](https://docs.flatpak.org/en/latest/sandbox-permissions.html),
  what the sandbox limits by default and how portals work
- [Flatpak, Using Flatpak](https://docs.flatpak.org/en/latest/using-flatpak.html),
  the system versus `--user` mode, installation, updating
- [Flathub](https://flathub.org/): the main repository of Flatpak apps
- [CachyOS Wiki](https://wiki.cachyos.org/): repositories and
  distribution conventions
