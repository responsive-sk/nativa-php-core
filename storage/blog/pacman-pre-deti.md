---
title: "Arch balíky pre deti ... diel 1: pacman"
series: "Arch balíky pre deti"
series-part: "1"
date: "2026-09-24"
version: "1.0.1"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "Prvá lekcia série o balíkoch na CachyOS: čo je balík, čo robí pacman, prečo si nemusíš pamätať názvy balíkov a prečo jeden príkaz aktualizuje celý systém."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "pacman-for-kids"
---

> Toto je diel 1 série „Arch balíky pre deti“, o balíkoch na CachyOS
> a o tom, komu môžeš veriť, keď ich inštaluješ. Mapa série: [kľúč na
> inštaláciu](/blog/cachyos-instalacny-kluc-pre-deti) (diel 0), pacman
> (dnes), [rebrík dôvery](/blog/repozitare-a-dovera-pre-deti) medzi
> repozitármi, [Flatpak ako výnimka](/blog/flatpak-pre-deti),
> [motto](/blog/kluc-trust-nothing-pre-deti) „TRUST NOTHING. READ THE
> DOCS. sudo LAST.“ a [overovanie bezpečnostných
> tvrdení](/blog/ai-bezpecnostne-tvrdenia-pre-deti) od AI. Dnes, prvý
> príkaz.

Máš čerstvý CachyOS na starom noťasi. Chceš Telegram. Spýtaš sa AI a ona ti
poradí ... nainštaluj si Flatpak.

A pritom máš pacman. Bol tu skôr, než si otvoril chat. Vie nájsť,
overiť, nainštalovať, aktualizovať aj odstrániť takmer všetko, čo na
Linuxe budeš potrebovať, a robí to z príkazového riadku, bez obchodu
s hodnoteniami a bez obrázkov.

Táto lekcia ti ukáže ten príkaz. A aj to, prečo si nemusíš pamätať
názvy balíkov.

## Čo je balík

**Balík** je krabica s aplikáciou. Vnútri nie je len program, aj
metadáta: názov, verzia, popis, zoznam závislostí a **podpis**, ktorý
potvrdzuje, že krabica pochádza naozaj odtiaľ, odkiaľ tvrdí.

**pacman** je nástroj, ktorý tie krabice ovláda. Názov je skratka
z PACkage MANager. Hľadá, inštaluje, aktualizuje a odstraňuje.
Na CachyOS je predinštalovaný, Arch aj CachyOS stavia balíky na ňom.

## Prvý príkaz

Inštalácia vyzerá takto:

```bash
sudo pacman -S telegram-desktop
```

Rozoberme ho:

| Časť | Čo robí |
|---|---|
| `sudo` | inštalácia píše do celého systému, na to potrebuješ práva správcu |
| `pacman` | nástroj |
| `-S` | synchronizuj, nájdi balík v repozitári a nainštaluj |
| `telegram-desktop` | názov balíka |

Keď príkaz spustíš, pacman nájde balík aj jeho závislosti, stiahne ich,
overí podpisy a nainštaluje. Bez klikania, bez webovej stránky,
bez „next, next, finish“.

A už teraz poznáš pravidlo na budúce lekcie: čítanie beží bez sudo,
zápis so sudo. Hľadanie nič nezapisuje,`pacman -Ss` spustíš
bez práv správcu.

## Nemusíš si pamätať názvy

Prečo `telegram-desktop` a nie `telegram`? Lebo balík sa volá takto,
a nikto od teba nečakal, že si to zapamätáš.

Namiesto presného názvu napíš koreň slova, v ktorom si istý:

```bash
pacman -Ss telegram
```

Výpis ukáže všetko, čo koreň obsahuje, aj to, čo už máš
nainštalované:

```text
extra/telegram-desktop <verzia> [installed]
    Official desktop client for Telegram
```

Ak sa balík medzi výsledkami objaví viackrát, je to preto, že ho
ponúka viac repozitárov. CachyOS pridáva vlastné repozitáre k tým
archovským, preto vedľa `extra/` vidíš aj `cachyos-extra-v3/`.
Názvy si nepamätáš; pamätáš si koreň slova a pacman si to nájde sám.

## Jeden príkaz na celý systém

Na iných systémoch má každá aplikácia vlastný updater, desiatky
okien, ktoré ťa prosia o aktualizáciu. Pri balíkoch z repozitárov
to takto nefunguje:

| Prístup | Aktualizácia |
|---|---|
| inštalátor z webu | každá appka si updaterom svoja |
| pacman | jeden príkaz, celý systém naraz |

Ten jeden príkaz:

```bash
sudo pacman -Syu
```

Aktualizuje systém, knižnice, aplikácie aj ich závislosti spolu,
lebo balíky z repozitárov patria k sebe a vydávajú sa ako celok.

A tu je pravidlo, ktoré si zapamätaj hneď: `-Sy` nikdy bez `-u`.
Obnova databázy balíkov bez samotnej aktualizácie mieša nové metadata
so starými balíkmi, presne tak sa na fórach Archu lámu systémy.
Chceš nainštalovať jednu vec? Aj tak najprv `-Syu`. Píše to aj
Arch Wiki, zdroj, ktorému v tejto sérii budeme veriť.

## Kedy pacman nestačí

Úprimne, tri veci, ktoré treba vedieť skôr, než sa zamiluješ.

Po prvé, nie všetko je v repozitároch. Aplikácia s chúlostivou
licenciou alebo menšia komunitná vec tam môže chýbať. Existujú
alternatívy, AUR (balíky balí komunita sama) a Flatpak (sandbox,
ktorý prináša aj svoje riziká). Obom sa venujú ďalšie diely.

Po druhé, pacman nemá obchod. Žiadne hodnotenia, žiadne screenshoty,
žiadne „odporúčané pre teba“. Namiesto toho popis balíka, čítanie
namiesto listovania. Nie je to chyba, je to dohoda.

Po tretie, pacman nerozhoduje o dôvere za teba. Overí podpis a povie
ti, odkiaľ balík pochádza. Čo ten podpis znamená a komu veríš, keď
balík balila komunita, to je téma dielu 2.

## Čo bude ďalej

Diel 2, rebrík dôvery. Oficiálne repozitáre, AUR, Flatpak a „niečo,
čo ti poslal kamarát“: v akom poradí sú bezpečné, prečo je rebrík
zoradený práve takto a čo znamená podpis, ktorý si dnes videl pri
inštalácii.

## Zdroje

- [Pacman, Arch Wiki](https://wiki.archlinux.org/title/Pacman),
  kompletná príručka vrátane pravidla o čiastočných aktualizáciách
- [pacman(8), manuálová stránka](https://man.archlinux.org/man/pacman.8)
- [Oficiálny prehliadač balíkov Arch Linux](https://archlinux.org/packages/)
- [CachyOS Wiki](https://wiki.cachyos.org/): repozitáre a špecifiká
  distribúcie
