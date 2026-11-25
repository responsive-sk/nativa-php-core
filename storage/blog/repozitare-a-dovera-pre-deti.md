---
title: "Arch balíky pre deti ... diel 2: rebrík dôvery"
series: "Arch balíky pre deti"
series-part: "2"
date: "2026-09-24"
version: "1.0.2"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "Odkiaľ balíky prichádzajú a komu veríš, keď si ich inštaluješ: oficiálne repozitáre, AUR, Flatpak a „niečo, čo ti poslal kamarát“, rebrík dôvery zoradený podľa rizika."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "repos-and-trust"
---

> [Diel 1](/blog/pacman-pre-deti) ukázal prvý príkaz a podpis, ktorý si
> videl pri inštalácii. Dnes diel 2, rebrík dôvery: prečo balíky z
> oficiálnych repozitárov stoja na prvom stupni, čo je AUR a Flatpak,
> a prečo je rebrík zoradený práve takto.

V dieli 1 si nainštaloval Telegram cez pacman. Medzi príkazom a
výsledkom bežal tichý proces, pacman overil, že krabica pochádza
odtiaľ, odkiaľ tvrdí.

V tomto diele sa pozrieme, odkiaľ balíky môžu prichádzať. Nie všetky
smery sú rovnako bezpečné, a preto si rebrík zapamätáš ako celok.

## Rebrík dôvery

Štyri miesta, odkiaľ na CachyOS prichádza software, zoradené od
najbezpečnejšieho:

| Stupeň | Odkiaľ balíky | Kto ich balí | Riziko |
|---|---|---|---|
| 1 | oficiálne repozitáre | tím distribúcie | nízke |
| 2 | AUR | komunita | stredné |
| 3 | Flatpak | vývojári appky | stredné |
| 4 | „niečo z internetu“ | neznámy | najvyššie |

Prečo je to rebrík a nie rovná plocha? Lebo každý stupeň odpovedá na
otázku **kto za balík zodpovedá**: a na prvom stupni je odpoveď
najjasnejšia.

## Stupeň 1: oficiálne repozitáre

Balíky, ktoré vidíš cez `pacman -Ss`, prichádzajú z repozitárov
`core`, `extra` a ďalších, CachyOS k nim pridáva vlastné
(`cachyos-extra-v3`).

Každý balík má podpis. Kľúč je **GPG kľúč**: malý súbor, ktorým podpisujúci potvrdí, že krabica je od neho. Pacman pozná kľúče
signatárov repozitárov vopred a balík bez platného podpisu odmietne:

```text
error: telegram-desktop: signature from "..." is unknown trust
```

Rovnako odmietne balík podpísaný kľúčom, ktorý nepozná:

```text
error: failed to commit transaction (invalid or corrupted package)
```

Preto je stupeň 1 najnižšie riziko: balí kto, za koho sa hlási,
a overuje to nástroj, nie ty.

## Stupeň 2: AUR

**AUR** je Arch User Repository. Nie je to repozitár balíkov, je to
repozitár **receptov**. Každý recept sa volá `PKGBUILD`: krátky skript,
ktorý povie systému, odkiaľ stiahnuť zdrojový kód a ako ho zbaliť.

Recepty píše komunita. Ktokoľvek môže pridať svoj, preto je AUR
väčšie a zároveň rizikovejšie. Balík z repozitára overí pacman
automaticky; recept z AUR musíš pred použitím prečítať ty.

Najčastejšia chyba začiatočníka, a preto ju tu píšeme v opačnom
poradí: balík z AUR sa neinštaluje cez `sudo pacman -S`. Cez `sudo pacman -S` sa balíky z AUR neinštalujú, pacman totiž
inštaluje len balíky z repozitárov; z receptu ho najprv treba postaviť,
to robia AUR helperi ako yay alebo paru. Ako čítať recepty je téma
samostatnej série, tejto lekcie stačí jedno pravidlo:
**recept čítaj ako list, nie ako spam.**

## Stupeň 3: Flatpak

**Flatpak** prináša aplikácie v sandboxoch, izolovaných krabiciach,
ktoré majú obmedzený pohľad na systém. Znie to bezpečne a často aj
je.

Lenže sandbox je aj stroj. Pri systémovej inštalácii na ňom pracuje
privilegovaný helper, a história Flatpaku pozná obdobia, keď sa
útočníci zo sandboxov dokázali vymaniť. Niektoré z tých chýb boli
závažné a riešili sa urgentne.

Diel 3 sa pozrieme na Flatpak bližšie, ako sandbox funguje, kedy
Flatpak dáva zmysel a ako si udržať kontrolu. Zatiaľ platí CachyOS
konvencia: **nativné balíky na prvom mieste, Flatpak len keď
nativný balík neexistuje.**

## Stupeň 4: „niečo z internetu“

Štvrty stupeň nie je o nástroji, ale o nástrahách. „Stiahni
spustiteľný súbor“, „spustite tento skript“, „nainštaluj cez curl …
| bash“.

Zmysel rebríka: čím nižšie, tým menej ľudí stojí medzi tebou
a balíkom. Na stupni 1 je to tím distribúcie a jeho podpisy. Na
stupni 4 nikto, a jediné, čo ti zostáva, je vlastný úsudok.

Ak ti niekto pošle „jednoduchý inštalátor“ a nespolieha sa na
pacman, spýtaš sa: prečo? Väčšina odpovedí patrí na stupne 1–3.

## Kedy sa dá rebrík obísť

Úprimne, pravidlo „natívne prvé“ nie je dogma.

Menšie alebo komerčné aplikácie občas natívny balík nemajú. Vtedy príde
na rad AUR alebo Flatpak, rebrík nie je hranica, je to poradie, v ktorom sa pozeráš. Verzia v Flatpaku môže byť výrazne novšia ako
v repozitári; ak ti na tej jednej novšej verzii záleží, stupeň 3
je legálna voľba, s tými bezpečnostnými pravidlami, ktoré prídu
v diele 3.

A stupeň 4 má jednu výnimku: samotný CachyOS sa inštaluje z ISO
obrázku zo stránky distribúcie. Overenie cez podpis ISO je tá istá
disciplina ako rebrík, len na najvyššej úrovni.

## Čo bude ďalej

Diel 3, Flatpak ako výnimka. Ako sandbox funguje, prečo si
privilegovaný helper vyžaduje pozornosť a čo presne znamená pravidlo
„nativné prvé“.

## Zdroje

- [Pacman, Arch Wiki](https://wiki.archlinux.org/title/Pacman),
  kapitoly Package signing a Package security
- [Arch packaging standards, Arch Wiki](https://wiki.archlinux.org/title/Arch_packaging_standards),
  čo repozitáre znamenajú a kto za nimi stojí
- [AUR guidelines, Arch Wiki](https://wiki.archlinux.org/title/AUR_guidelines),
  pravidlá pre čítanie a písanie PKGBUILDov
- [Flatpak, dokumentácia sandboxov](https://docs.flatpak.org/en/latest/sandbox-permissions.html)
- [CachyOS Wiki](https://wiki.cachyos.org/): repozitáre a konvencie
  distribúcie
