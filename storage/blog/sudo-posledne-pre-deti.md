---
title: "Arch balíky pre deti ... diel 6: sudo LAST"
series: "Arch balíky pre deti"
series-part: "6"
date: "2026-10-03"
version: "1.0.0"
published: true
tags: ["arch", "linux", "sudo", "cachyos", "security", "beginners"]
excerpt: "Šiesta lekcia série: posledné pravidlo motto. Kedy sudo patrí (pacman -S, -Syu, -Sc), kedy nie (žiadne sudo yay, žiadne curl ... | sudo bash), prečo je sudo aj sudo najmenej a čo robí timestamp cache."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "sudo-last"
---

> [Diel 1](/blog/pacman-pre-deti) ukázal prvý príkaz, [diel 2](/blog/repozitare-a-dovera-pre-deti) zoradil rebrík dôvery, [diel 3](/blog/flatpak-pre-deti) otvoril Flatpak ako výnimku, [diel 4](/blog/kluc-trust-nothing-pre-deti) ukázal, že kľúč nič neverí, [diel 5](/blog/ai-bezpecnostne-tvrdenia-pre-deti) povedal, že AI tvrdenie je názor, kým ho neoveríš. [Diel 7](/blog/shell-pre-deti) sa vrátil do okna, z ktorého všetky príkazy behajú. Dnes diel 6, sudo: posledné pravidlo, ktoré zostalo.

Napíšeš `sudo` a počkáš. Alebo nenapíšeš a ideš ďalej. Rozdiel medzi týmito dvoma stavmi je presne táto lekcia, a väčšina príkazov, ktoré denne používaš, patrí do tej druhej skupiny.

Motto série znie „sudo LAST“ a od prvého dielu viselo vo vzduchu. Teraz ho rozložíme na dve vety, z ktorých každá má vlastnú cenu.

## sudo dáva práva na jeden príkaz

**sudo** nespustíš root shell. Neodovzdá ti práva trvalo, dáva ich **na jeden príkaz, na jeden riadok**. To je celý pointa.

`su` je opak: otvoríš si root shell a od tej chvíle každý ďalší príkaz beží s právami root. `sudo` nie. Spustí sa program, urobia sa veci a práva zmiznú.

Rozdiel je malý, kým sa nestane chyba. Píšeš `sudo` a stlačíš Enter skôr, než si dočítal príkaz. S `sudo` zostane zle napísaný alebo nechcený príkaz s právami root na jeden beh. S `su` by si musel najprv otvoriť ten shell, čas, kedy si si stihol všimnúť, čo píšeš.

sudo navyše zapisuje každý pokus do systémového logu. Nejde o zábavu, ide o to, aby sa dalo pozrieť, čo sa na stroji dialo.

A pamäť: po zadaní hesla si sudo drží platnosť približne päť minút (nastaviteľné cez `timestamp_timeout` v `/etc/sudoers`). V tom okne ďalšie `sudo` nechce heslo. To je pohodlné, a zároveň dôvod, prečo „sudo najmenej“ nie je len fráza.

## Tabuľka: sudo áno, sudo nie

| Čo chceš | Príkaz | Sudo? |
|---|---|---|
| Inštalovať balík | `pacman -S telegram-desktop` | **áno** |
| Aktualizovať systém | `pacman -Syu` | **áno** |
| Uvoľniť miesto v cache | `pacman -Sc` | **áno** |
| Prehľadať balíky | `pacman -Ss firefox` | **nie** |
| Čítať súbory | `cat /var/log/pacman.log` | **nie** |
| Spustiť aplikáciu | `telegram-desktop` | **nie** |
| Prečítať stav systému | `systemctl status NetworkManager` | **nie** |
| Zmena siete | `ip link set wlan0 down` | **áno** |

Riadok „áno“ znamená, že príkaz **zapisuje do systému**: mení balíky, konfiguráciu alebo sieť. Bez práv správcu to nefunguje a nedá sa to obísť inak než chybou.

Riadok „nie“ znamená, že príkaz **číta**. A čítanie práva nepotrebuje, s výnimkou, o ktorej povieme nižšie.

Platí to aj pre inštrument, nie len pre pacman. Pravidlo sa volá **sudo LAST**:

- **L**ooking, čítanie, bez práv
- **A**pplying, zápis, s právami
- **S**aving, ulož, čo si práve zmenil
- **T**roubleshoot, rieš, čo sa pokazilo

## Žiadne `sudo yay`

Toto si zapíš, aj keď ti to nikto neponúka:

```text
sudo yay -S balik
```

Nie. `yay` je pomocný nástroj, ktorý volá pacman. Spúšťa sa vlastnými právami, presne ako beží. Keď potrebuje zvýšené práva, spýta sa sám, a spýta sa správne, lebo vie čo robí.

Keď napíšeš `sudo` pred ním, získaš presne to, čo si nechal získať, ak dáš `yay` do pustého shell s rootom. Obe možnosti sú zlé, len jedna sa dá omylem preklepať.

To isté platí pre každý pomocný nástroj: AUR helper, grafický inštalátor, skript, ktorý si stiahol. Ak nástroj sám zvládne zvýšenie práv, nepridávaj mu ho ručne.

## Žiadne `curl ... | sudo bash`

Toto je druhé pravidlo, ktoré sa píše na tri riadky:

```text
curl -sSL https://example.com/install.sh | sudo bash
```

Riadok si rozdeľ na dve polovice a pozri, čo sa deje.

Prvá polovica stiahne súbor. Nič viac, stiahne text. Druhá polovica spustí ten text s právami root. Medzi nimi nie je okno, v ktorom by si si stihol prečítať, čo stiahlas. To isté urobíš ručne, ak si to rozdelíš:

```text
curl -sSL -o install.sh https://example.com/install.sh
less install.sh
bash install.sh
```

Teraz vidíš, čo beží. A ak niečo nevyzerá správne, `install.sh` si nechávaš na disku a nikto ho nespustí za teba.

V oboch prípadoch bežíš rovnaký kód. Rozdiel je, že v druhom prípade si ho najprv prečítal.

## A čo keď niečo naozaj treba

Sú príkazy, kde `sudo` patrí aj na čítanie, a to je normálne, nie chyba. `pacman -Sc` číta cache, ale mazanie v nej nie. `ip link set wlan0 down` zistí stav siete a zmení ju.

Rozdiel je v tom, že tie príkazy **majú svoju vlastnú kontrolu**. `sudo` im nepúšťa práva naslepo, dávajú im, lebo vedia, že potrebujú, a robia si vlastnú kontrolu argumentov.

A `visudo`? Keď už meníš pravidlá, použi `visudo`, nie `nano`:

```bash
sudo visudo
```

visudo zamkne súbor, zapíše zmenu do dočasného a **skontroluje syntax**. Chybný riadok v `/etc/sudoers` znamená, že sudo prestane fungovať úplne, a pri úprave v `/etc/sudoers.d/` sa dá zlyhanie opraviť jednoduchšie, než v hlavnom súbore. Ako čítať `sudo -ll` ti ukáže aktuálne nastavenia.

## Úprimne: prečo si pravidlá pýtať

Niektorí ľudia na fóre píšu, že `sudo` je zbytočné prekáženie a že kto nepotrebuje systemd, ten sudo nepotrebuje vôbec. Často majú pravdu.

Lenže na Arch si `sudo` nezvykneš zadarmo. Inštalátor CachyOS ti ho nainštaluje a členstvo v skupine `wheel` dáva práva. A `sudo` má dva sprievodné efekty, o ktorých sa hovorí málo:

Prvý, heslo. Keď `sudo` raz prijme tvoje heslo, drží si ho v pamäti päť minút. Počas tých päť minút máš na termináli plné práva bez pýtania.

Druhý,`sudo -s` a `sudo su` sú most do trvalého root shellu. Presne to, čo si `sudo` navrhnuté vyhnúť. `sudo -s` na chvíľu, `sudo su` natrvalo.

Ani jedno nie je zraniteľnosť. Sú to východy, ktoré sa dajú použiť, a preto musia byť vedomé. Kto `sudo` používa na čítanie každý deň, ten práva berie ako samozrejmosť, a práve vtedy sa najľahšie stane, že priklepne tam, kde nemá.

## Čo bude ďalej

Osem dielov má motto hotové. Ale motto znie „sudo LAST“ a `T` znamená troubleshoot, časť, ktorú sme doteraz nechali na teba.

Nastavil si niekedy sieť, spravil si `pacman -Syu` a nič sa nedeje? Toto je presne miesto, kde sa to dá pokúsiť. Prišli s tým konkrétnym prípadom, pozrieme spolu.

## Zdroje

- [Sudo, ArchWiki](https://wiki.archlinux.org/title/Sudo): práva na jeden príkaz,
  `visudo`, skupina `wheel` a Polkit
- [sudoers(5), man.archlinux.org](https://man.archlinux.org/man/sudoers.5.en),
  `timestamp_timeout`, `Defaults` a úpravy v `/etc/sudoers.d/`
- [Sudo Home](https://www.sudo.ws/): `sudo -s`, `sudo su` a spôsob práce s právami