---
title: "Tri vrstvy ... súbor, príkaz a aplikácia"
date: "2026-10-04"
version: "1.0.0"
published: true
tags: ["unix", "tar", "iso", "cli", "shell", "formaty"]
excerpt: "Na Unixe nič nie je „len súbor“ — každý príkaz má pod sebou vrstvu, ktorá niečo schováva a niečo pridáva. Článok rozkladá obaly na tri úrovne: dáta, príkaz a aplikácia — a hovorí, kedy obalenie škodí viac než pomáha."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "three-layers-of-wrapping"
---

Napíšeš `bin/cms migrate` a nič viac nepotrebuješ vedieť.
Príkaz zbehne, migrácie sa prejdú, hotovo.

Ale medzi tvojim prstom a SQLite sú štyri vrstvy: PHP, Symfony Console, `MigrateCommand` a samotný migrátor.

Ktorej z nich sa dá bezpečne nedať?
Žiadny — a to je problém, ktorý práve tento text rozoberá.

Nikto totiž nezačína od nuly. Každý príkaz, ktorý niekde beží,
už niekto obložil.

Otázka nie je *či* niečo obaľuje, ale **odkiaľ
sa obal začína a kde sa končí** — a čo všetko odoberie.

## Obal, ktorý drží súbory

**Kontajner** je najjednoduchší obal: vezme všetky súbory cesty a dá ich do jedného.
V Unixe je to `tar` a od roku 1979 je to skoro rovnako jednoduché.

```bash
tar -cf zaloha.tar storage/
tar -tf zaloha.tar | head -3
```

Podstatné je, čo `tar` **neobsahuje**:
žiadny súborový systém.
Súbory idú za sebou, každý predchádza 512-bajtová hlavička. Preto sa nedá pripojiť, otvoriť v prehliadači ani prejsť nástrojom na disky.
Je to **páska** s nálepkami — nie disk.

Čo `tar` naopak zachováva, je to, čo mnoho formátov zahodí:
oprávnenia, vlastníka a skupinu. Práve preto je po roku 2001
zvykom robiť distribúcie v `.tar.gz` a nie v `.zip` — ZIP pred
štandardom POSIX zapisoval práva ako neplatné ale Unix to naštastie neurobil.

## Obal, ktorý drží súborový systém

Tu sa veci lámu. [Diel 0](/blog/cachyos-instalacny-kluc-pre-deti) stiahol si si ISO obraz a nikto vtedy neodporučal sťahovať `tar` — a mal prečo, aj keď to nebolo vyslovené: `cachyos-desktop-linux-260809.iso` nie je kontajner.
Je to **obraz súborového systému**.

ISO 9660 (ECMA-119) opisuje disk: koreňový adresár, podadresáre,
alokačné tabuľky.
Presne to, čo by `tar` potreboval, keby ho napísal
na papier. Dôvod, prečo `tar` taký nie je, je dejinný — vznikol na magnetofóne, kde „adresár“ znamená iba poradie zápisu, nie adresár na disku.

Rozdiel vidíš jedným príkazom:

```bash
file cachyos-desktop-linux-260809.iso
# ISO 9660 CD-ROM filesystem data

bsdtar -tf cachyos-desktop-linux-260809.iso | head -3
# bsdtar to zvládne — čítať viac vie
```

Druhý príkaz je dôkaz, prečo sa ľudia mýlia: `bsdtar` **vie** otvoriť
ISO. Ale otvoriť a zapísať sú dve veci. `bsdtar` ISO nikdy nezapíše —
pretože ISO je read-only zápis, taký aký čitalnice nikdy nemala.

| Obal | Čo drží | Pripojiť? | Zapísať? |
|---|---|---|---|
| `tar` | súbory s právami, žiadny filesystem | nie | áno |
| ISO 9660 | celý súborový systém | **áno** | nie |
| squashfs | filesystem, komprimovaný a read-only | **áno** | nie |

Posledný riadok ťa zaujíma najviac. Arch Linux
nepoužíva ISO 9660 na svojom inštalačnom obraze — používa
**squashfs**, komprimovaný read-only filesystem. ISO je len obal
okolo neho, aby sa z neho dalo bootovať. V `[diel 0](/blog/cachyos-instalacny-kluc-pre-deti)`
si zapisoval ISO na kľúč a `dd` prepísal celý blok po blokoch;
vnútri kľúča bol squashfs.

## Obal, ktorý drží príkaz

Druhá úroveň obaľuje nie dáta, ale **program**. A je tu toto
repozitár, nie encyklopédia.

`bin/cms` má päť riadkov kódu a robí presne jednu vec — obaľuje
Symfony Console:

```php
#!/usr/bin/env php
// ...
$application = new Application('Nativa PHP Starter', '0.1.0');
$application->addCommand(new MigrateCommand());
// ...
$application->run();
```

Všetko pod tým `#!/usr/bin/env php` je obal. Príkaz `cms migrate`
neexistuje v Unixe — je to argument `php`-a. Bez wrappera by si
musel písať `php bin/cms migrate` a vedieť, ktorý PHP.

A potom sa na to obalí ďalší. `Makefile` má 32 targetov a jeden
z nich je sám osebe obal piatich ďalších:

```make
pre-commit: lint analyze test lekalo-check lekalo-trace
```

Napíšeš `make pre-commit` a spustí sa päť príkazov. Žiadny z nich
si nevidel. A neuvidíš ich ani potom — kým nespustíš:

```bash
make -n pre-commit   # vypíše, čo by spustil, bez toho aby to spustil
```

To je cena obalu. Nie je to pomalé a nie je to zlé — ale **musíš
si vedieť, že existuje nástroj, ktorým cez obal pozrieš**. Bez neho
ladíš naslepo.

## Obal, ktorý drží aplikáciu

Tretia úroveň obaľuje celú aplikáciu spolu s jej závislosťami.
Flatpak, AppImage, snap — každý z nich tvrdí, že jeho balík
beží všade.

Rozhodnúť, či to pravda, má už [diel 3](/blog/flatpak-pre-deti) —
tam je odpoveď explicitne: je to výnimka z pravidla, nie pravidlo.
Sem patrí len preto, aby bola škála kompletná.

Čo balík aplikácie robí navyše oproti prímu príkazu, je dôležité:
**udržiava vlastnú definíciu sveta**. Má vlastné knižnice, vlastný
HOME, vlastné povolenia siete. Aplikácia v ňom nevidí tvoj systém
a ty nevidíš do nej. Všetko, čo potrebuje, musí byť deklarované
v manifeste — inak to nemá.

To je výhoda aj cena súčasne. Výhoda: balík beží aj na stroji,
kde chýbajú tri knižnice. Cena: keďže nevidí do systému, nedokáže
sa opraviť sám — a ty nemôžeš opraviť jeho.

## Úprimne — kedy obaly škodia

Teraz čas na druhú polovicu pravdy. Obal nie je vždy výhoda —
a nasledujúce dva dôkazy sú z repozitára, v ktorom práve čítáš.

**Prvý: jednoriadkový príkaz obaľovať nemá.** `dist` je v tomto
Makefile delegát na `bin/build-dist.sh` — a to je správne, lebo
skript má 280 riadkov. Ale keby `migrate` bol jeden riadok
`php bin/cms migrate`, obal by bol strata. Vytvára vrstvu, ktorú
musí niekto udržiavať, a nenesie žiadnu hodnotu.

**Druhý: distributuje sa adresár, nie archív.**
`bin/build-dist.sh` skončí vytvorením adresára, v ktorom je
kompletná aplikácia. Žiadny `tar`, žiadny `.zip`. Je to zámerné —
archív by bol ďalší obal, ktorý by si musel rozbaliť, skôr než
sa vôbec dostaneš k `composer.json`. Adresár je forma, v ktorej
sa dá čítať priamo.

A tretí, najúprimnejší: **štyri vrstvy medzi prstom a databázou
majú cenu pri ladení.** `make pre-commit` → `composer test` →
PHPUnit → PDO → SQLite. Keď zlyhá, chyba sa vracia cez všetky
štyri a každá môže vlastný exit kód preložiť na nulu. Vtedy
nevieš, kde sa to pokazilo — a zvyčajne začneš hľadať v tej
vrstve, ktorú práve píšeš, nie v tej, ktorá zlyhala.

## Prakticky

1. Keď obaľuješ, zapíš si priamo pod sebou, **čo obaľuješ** — jediný
   komentár `# wrapper okolo: <príkaz>` zachráni budúceho človeba,
   ktorým si v tomto prípade ty.
2. Nauč sa nástroj, ktorým pozrieš cez obal: `make -n`,
   `bash -x`, `--dry-run`, `docker inspect`. Obal, ktorý nevieš
   preklopiť, je obal, ktorý raz odladíš naslepo.
3. Obal pridávaj až vtedy, keď zodpovedá na otázku *„čo by bolo
   bez neho ťažšie?“* — a ak je odpoveď „nič“, obal je len
   vrstva navyše.

Obal je zmluva ... nie s nástrojom, ale s tým, kto príde
o dva roky neskôr. A tým si ty.

## Zdroje

- [GNU tar manuál](https://www.gnu.org/software/tar/manual/tar.html) —
  formát, hlavičky, čo kontajner zachováva
- [ECMA-119 (ISO 9660)](https://www.ecma-international.org/publications-and-standards/standards/ecma-119/) —
  normatívny opis súborového systému na optickom disku
- [Linux kernel — squashfs](https://www.kernel.org/doc/html/latest/filesystems/squashfs.html) —
  komprimovaný read-only filesystem, ktorý používa Arch
- [Flatpak Dokumentácia](https://docs.flatpak.org/en/latest/) —
  sandbox, manifest, čo balík aplikácie deklaruje
- [Nativa Minimal Documentation](/docs/getting-started) — zero-dependency
  bootstrap, ktorého `bin/cms` je obalom
