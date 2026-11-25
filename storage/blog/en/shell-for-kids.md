---
title: "Arch packages for kids ... part 7: the shell"
series: "Arch packages for kids"
series-part: "7"
date: "2026-09-24"
version: "1.0.0"
published: true
tags: ["arch", "linux", "shell", "cachyos", "security", "beginners"]
excerpt: "The seventh lesson of the series: the terminal is a window, the shell is a language. Where aliases and variables live (.bashrc, .zshrc, config.fish), why every shell reads only its own file and why tokens belong in variables, not in history."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "shell-pre-deti"
---

> [Part 1](/en/blog/pacman-for-kids) showed the first command, [part 2](/en/blog/repos-and-trust) sorted the trust ladder, [part 3](/en/blog/flatpak-for-kids) opened Flatpak as the exception. The parts about the motto, verifying AI claims and sudo are still coming. Today part 7 — the shell: the place where all these commands actually live.

You open a terminal, type `sudo pacman -Syu` and wait for the output. The terminal is just a window — someone else reads and runs the command. It is called the shell and you already picked one, perhaps without knowing how.

Two people in one chat: one has fish, because CachyOS set it up for him. The other has zsh, because he got used to it — and bash next to it, because it came first. Neither of them is wrong. Both have their own settings file though — and that is exactly what this lesson is about.

## The terminal is a window, the shell is a language

A **shell** is a program that reads lines and runs them. You type a command, it understands it, calls the needed programs and prints the result.

The **terminal** is the window where the shell lives — the same way a browser is a window for the web.

Linux has several shells; you meet three most often:

| Shell | Character | Config |
|---|---|---|
| bash | the standard — most tutorials assume it | `~/.bashrc` |
| zsh | bash with extras — tab completion works better | `~/.zshrc` |
| fish | friendly — colors and hints right after install | `~/.config/fish/config.fish` |

The row "most tutorials assume it" is the most important one in the table. When someone on a forum writes a command, they wrote it for bash.

## One shell, one file

The first rule to remember: **every shell reads its own file.**

You write an alias into `~/.bashrc` and fish does not see it. You set a variable in `~/.zshrc` and it does not exist in bash. That is not a flaw — it is the deal: on startup the shell loads its own config, the others do not interest it.

That is why you remember it instead of guessing: when something works in one terminal only, the first question is — which shell runs there?

## Where variables live

A **variable** is a label with a value that the shell offers to every program it starts. You write it into the shell's config — once, and it holds at every startup.

In bash and zsh:

```bash
export UPDATER="sudo pacman -Syu"
```

In fish the syntax differs — the same idea, a different spelling:

```fish
set -x UPDATER "sudo pacman -Syu"
```

Aliases work the same way:

| What | bash / zsh | fish |
|---|---|---|
| variable | `export NAME="value"` | `set -x NAME "value"` |
| alias | `alias up='sudo pacman -Syu'` | `alias up 'sudo pacman -Syu'` |

After editing the config you restart the shell — or open a new terminal — and it reads the settings from the start.

## Keys do not belong in history

The shell remembers every line you typed — the **history**. It is a comfort: the up arrow brings back yesterday's command.

It is also a risk: it remembers absolutely everything.

So you do not type a token or an API key directly into a command. You write it once into the config as a variable:

```bash
export API_TOKEN="..."   # shell config, once
```

and programs read it from the environment. The history then keeps only the command that uses the token — not the token itself.

## Which shell to pick

CachyOS sets fish as the login shell for the user — root gets bash. During installation you can choose zsh or bash instead; changing later is simple:

```bash
chsh -s /bin/bash
```

After logging out and back in, the new shell runs. But which one to pick?

| When you want | Pick |
|---|---|
| every tutorial from the internet to work | bash |
| tab completion and you are used to it | zsh |
| colors and hints right away, without tuning | fish |

The voice rule of this series holds here too: pick one and stay with it. The four commands from the first lesson work in each of them — pacman does not know which one it runs in.

## Honestly — when not to change shells

Three cases where the change is not worth the effort.

First, fish does not run a script written for bash. Scripts from tutorials start with `#!/bin/bash` and fish does not understand them — the syntax differs. A solution exists: `bash script.sh` runs the script in bash even from fish. But if you want to copy commands from tutorials without thinking, bash or zsh saves you the tricks.

Second, configs multiply. Two shells mean two files — two places to look in when a variable does not work. You have fish and bash? Remember what lives where — otherwise you get lost in it yourself.

Third, the shell is not a project. It is a tool that reads the commands of this series. Hours of tuning prompts and themes are fun — but packages install in a completely bare bash as well.

## What comes next

The motto "TRUST NOTHING. READ THE DOCS. sudo LAST." will still get its own lesson — and sudo its yes and no tables. Leave today with one finished step: open the config of the shell you use and write the first alias into it:

```bash
alias up='sudo pacman -Syu'
```

(with fish: `alias up 'sudo pacman -Syu'`). From then on, updating the whole system takes two letters — and the rules of this series live where rules live: in your shell.

## Sources

- [CachyOS Wiki — Post Install Setup](https://wiki.cachyos.org/configuration/post_install_setup/) —
  the default login shell and changing it
- [Bash Reference Manual — GNU](https://www.gnu.org/software/bash/manual/) —
  variables, aliases and history
- [Zsh — Arch Wiki](https://wiki.archlinux.org/title/Zsh) —
  configuration and extensions
- [fish — official documentation](https://fishshell.com/docs/current/) —
  variable syntax, aliases and the config
