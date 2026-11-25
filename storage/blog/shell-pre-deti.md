---
title: "Arch balíky pre deti ... diel 7: shell"
series: "Arch balíky pre deti"
series-part: "7"
date: "2026-09-24"
version: "1.0.1"
published: true
tags: ["arch", "linux", "shell", "cachyos", "security", "beginners"]
excerpt: "Siedma lekcia série: terminál je okno, shell je jazyk. Kde žijú aliasy a premenné (.bashrc, .zshrc, config.fish), prečo každý shell číta len svoj súbor a prečo tokeny patria do premenných, nie do histórie."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "shell-for-kids"
---

> [Diel 1](/blog/pacman-pre-deti) ukázal prvý príkaz, [diel 2](/blog/repozitare-a-dovera-pre-deti) zoradil rebrík dôvery, [diel 3](/blog/flatpak-pre-deti) otvoril Flatpak ako výnimku. Diely o motti, overovaní AI tvrdení a sudo ešte prídu. Dnes diel 7, shell: miesto, kde všetky tieto príkazy naozaj žijú.

Otvoríš terminál, napíšeš `sudo pacman -Syu` a počkáš na výstup. Terminál je len okno, príkaz prečíta a vykoná niekto iný. Volá sa shell a práve si ho vybral, možno ani netušíš ako.

Dvaja ľudia v jednom chate: jeden má fish, lebo ho CachyOS nastavil sám. Druhý má zsh, lebo si naň zvykol, a vedľa aj bash, lebo bol prvý. Žiaden z nich nemá pravdu. Majú však každý svoj súbor s nastavením, a presne o tom je táto lekcia.

## Terminál je okno, shell je jazyk

**Shell** je program, ktorý číta riadky a vykonáva ich. Napíšeš príkaz, on ho pochopí, zavolá potrebné programy a vypíše výsledok.

**Terminál** je okno, v ktorom shell býva, rovnako ako prehliadač je okno pre web.

Na Linuxe shellow je viac, tri stretneš najčastejšie:

| Shell | Charakter | Config |
|---|---|---|
| bash | štandard, predpokladá ho väčšina návodov | `~/.bashrc` |
| zsh | bash s doplnkami, dopĺňa na tab lepšie | `~/.zshrc` |
| fish | priateľský, farby a nápovedy hneď po inštalácii | `~/.config/fish/config.fish` |

Riadok „predpokladá ho väčšina návodov“ je najdôležitejší v celej tabuľke. Keď niekto na fóre napíše príkaz, písal ho pre bash.

## Jeden shell, jeden súbor

Prvé pravidlo, ktoré si zapamätaj: **každý shell číta svoj vlastný súbor.**

Napíšeš si alias do `~/.bashrc` a fish ho nevidí. Nastavíš premennú v `~/.zshrc` a v bashi neexistuje. Nie je to chyba, je to dohoda: shell pri štarte načíta svoj config, ostatné ho nezaujímajú.

Preto si to treba pamätať, nie hádať: keď niečo nefunguje len v jednom termináli, prvá otázka znie, ktorý shell tam beží?

## Kde žijú premenné

**Premenná** je menovka s hodnotou, ktorú shell ponúka každému spustenému programu. Píše sa do configu shellu, raz, a platí pri každom štarte.

V bashi a zsh:

```bash
export UPDATER="sudo pacman -Syu"
```

V fishi je syntax iná, rovnaká myšlienka, iný zápis:

```fish
set -x UPDATER "sudo pacman -Syu"
```

Aliasy fungujú rovnako:

| Čo | bash / zsh | fish |
|---|---|---|
| premenná | `export MENO="hodnota"` | `set -x MENO "hodnota"` |
| alias | `alias up='sudo pacman -Syu'` | `alias up 'sudo pacman -Syu'` |

Po úprave configu shell reštartuješ, alebo otvoríš nový terminál, a nastavenie číta od začiatku.

## Kľúče nepatria do histórie

Shell si pamätá každý riadok, ktorý si napísal, **história** príkazov. Je to pohodlie: šípka hore vráti včerajší príkaz.

Je to aj riziko: pamätá si úplne všetko.

Token alebo API kľúč preto nepíšeš priamo do príkazu. Napíšeš ho raz do configu ako premennú:

```bash
export API_TOKEN="..."   # config shellu, raz
```

a programy si ho prečítajú z prostredia. V histórii potom ostane len príkaz, ktorý token používa, nie token samotný.

## Ktorý shell si vybrať

CachyOS nastavuje používateľovi fish ako login shell, root má bash. Pri inštalácii si môžeš vybrať aj zsh alebo bash; zmena neskôr je jednoduchá:

```bash
chsh -s /bin/bash
```

Po odhlásení a prihlásení beží nový shell. Ale ktorý si vybrať?

| Keď chceš | Vyber |
|---|---|
| aby fungoval každý návod z internetu | bash |
| dopĺňanie na tab a si si naň zvykol | zsh |
| farby a nápovedy hneď, bez ladenia | fish |

Pravidlo hlasu tejto série platí aj tu: vyber si jeden a zostaň pri ňom. Štyri príkazy z prvej lekcie fungujú v každom z nich, pacman nepozná, v ktorom beží.

## Úprimne: kedy shell nemeniť

Tri situácie, keď zmena nestojí za námahu.

Po prvé, fish nespustí skript pre bash. Skripty z návodov začínajú `#!/bin/bash` a fish im nerozumie, syntax sa líši. Riešenie existuje: `bash skript.sh` spustí skript v bashi aj z fishu. Ak však chceš kopírovať príkazy z návodov bez premýšľania, bash alebo zsh ti ušetrí triky.

Po druhé, configy sa násobia. Dva shelly znamenajú dva súbory, dve miesta, kde hľadať, prečo premenná nefunguje. Máš fish aj bash? Pamätaj si, kde máš čo, inak sa v tom strácaš aj ty.

Po tretie, shell nie je projekt. Je to nástroj, ktorý má čítať príkazy z tejto série. Hodiny ladenia promptu a tém sú zábava, ale balíky sa inštalujú aj v úplne holom bashi.

## Čo bude ďalej

Motto „TRUST NOTHING. READ THE DOCS. sudo LAST.“ ešte dostane vlastnú lekciu, a sudo svoje tabuľky áno a nie. Dnes ale odíď s jedným hotovým krokom: otvor config shellu, ktorý používaš, a napíš doň prvý alias:

```bash
alias up='sudo pacman -Syu'
```

(s fishom: `alias up 'sudo pacman -Syu'`). Odvtedy je aktualizácia celého systému dve písmená, a pravidlá z tejto série máš usadené tam, kde žijú: vo svojom shelli.

## Zdroje

- [CachyOS Wiki, Post Install Setup](https://wiki.cachyos.org/configuration/post_install_setup/),
  defaultný login shell a zmena shellu
- [Bash Reference Manual, GNU](https://www.gnu.org/software/bash/manual/),
  premenné, aliasy a história
- [Zsh, Arch Wiki](https://wiki.archlinux.org/title/Zsh),
  konfigurácia a rozšírenia
- [fish, oficiálna dokumentácia](https://fishshell.com/docs/current/),
  syntax premenných, aliasy a config
