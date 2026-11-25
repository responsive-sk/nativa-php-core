---
title: "Arch balíky pre deti ... diel 4: kľúč na monitor"
series: "Arch balíky pre deti"
series-part: "4"
date: "2026-09-24"
version: "1.0.0"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "Štvrtá lekcia série: motto TRUST NOTHING. READ THE DOCS. sudo LAST., tri pravidlá, každé s príkladom z predchádzajúcich dielov, a prečo ich píšeme na papier pri monitore, nie do hlavy."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "key-trust-nothing"
---

> [Diel 1](/blog/pacman-pre-deti) ukázal prvý príkaz, [diel 2](/blog/repozitare-a-dovera-pre-deti) zoradil rebrík dôvery, [diel 3](/blog/flatpak-pre-deti) otvoril Flatpak ako výnimku. Dnes diel 4, motto celej série, skrátené na tri riadky, ktoré stoja za to prilepiť si na monitor.

V chate, z ktorého táto séria vznikla, padla jedna veta, ktorá je dôležitejšia než každý príkaz v predchádzajúcich dieloch:

> prvé čo si nalep na monitor ... art. generator klame ...

Z nej je nápad zrodiť celé pravidlo série. Tri riadky, každý krátky, každý overený praxou:

> **TRUST NOTHING. READ THE DOCS. sudo LAST.**

Táto lekcia nerobí nič nové, spája to, čo už vieš, do formy, ktorá prežie aj zlý deň.

## TRUST NOTHING

Prvé pravidlo neznie „nikomu never“, znie „nikto za teba nerozhoduje“.

Rebrík dôvery z [dielu 2](/blog/repozitare-a-dovera-pre-deti) má štyri stupne, lebo dôvera nie je jedno rozhodnutie, je to reťaz. Na stupni 1 overuje podpis nástroj, ale distribúciu, ktorej kľúče si do systému pustil, si zvolil Ty. Na stupni 2 čítaš recepty ako listy. Na stupni 4 nestojí medzi tebou a balíkom nikto.

| Stupeň | Kto rozhoduje za teba | Čo zostáva na tebe |
|---|---|---|
| 1 | tím distribúcie + nástroj | výber distribúcie |
| 2 | komunita píše recepty | prečítať recept |
| 3 | vydavateľ appky | prečítať povolenia |
| 4 | nikto | všetko |

„TRUST NOTHING“ teda znamená: viď reťaz celú. Balík, ktorý overil pacman, je dôveryhodný len dovtedy, kým dôveruješ tým, ktorí ho podpísali.

## READ THE DOCS

Druhé pravidlo je nástroj prvého. Never, ale over. A overenie má adresu.

Každý diel tejto série končí sekciou Zdroje a žiadna z nich nie je dekorácia. Pravidlo o čiastočných aktualizáciách z dielu 1 stojí na Arch Wiki. Povolenia sandboxu z dielu 3 stojí v dokumentácii Flatpaku. Keď niečo nefunguje, prvá zastávka nie je chat, je to dokumentácia nástroja.

Dokumentácia má tri vlastnosti, ktoré chat nemá: je overiteľná (stojí na jednom mieste), je aktualizovaná (pri vydaní novej verzie) a nelichotí. Nevráti ti odpoveď, ktorú chceš počuť, vráti ti odpoveď, ktorá je.

## sudo LAST

Tretie pravidlo poznáš z dielu 1: hľadanie beží bez práv správcu, zápis s nimi. „LAST“ znamená dve veci naraz, sudo je posledný krok akcie a posledná možnosť, ku ktorej siahaš.

Napíšeš `pacman -Ss telegram`: bez sudo. Nájdeš, prečítaš, porovnáš. Až keď vieš presne, čo inštaluješ a odkiaľ, príde `sudo pacman -S telegram-desktop`. sudo na konci je dôsledok poradia: najprv čítanie, potom zápis.

Aj preto motto znie „sudo LAST“, nie „sudo nikdy“. Inštalácia a aktualizácia bez práv správcu nejde, pravidlo nie je dogma, je to poradie.

## Prečo papier a nie hlava

Mohlo by sa zdať, že tri pravidlá si zapamätáš. Zapamätáš si ich, v pokoji.

Pod tlakom to funguje inak. Keď ti niekto pred monitor hodí zoznam plný CVE čísel a slov „root prístup“, rozhodne strach skôr než vedomosti . Papier pri monitore nerobí rozhodnutie za teba. Robí jediné: stojí tam aj v momente, keď ti hlava nedá spoľahlivú odpoveď.

A ešte jedna vec. Pravidlo, ktoré musíš sformulovať tak, aby sa zmestilo na tri riadky, musíš najprv pochopiť. Písanie je kontrola porozumenia, „TRUST NOTHING“ na papieri prežije len vtedy, keď vieš povedať, čo znamená.

## Úprimne: kedy kľúč na monitore nestačí

Tri situácie, keď tri riadky nedajú rady.

Po prvé, motto nie je nahradenie dokumentácie. „READ THE DOCS“ je pokyn čítať, nie tvrdenie, že tri slová stačia. Nová situácia potrebuje nové čítanie, nie recitáciu starého pravidla.

Po druhé, kľúč na monitore funguje len dovtedy, kým sa naň občas pozrieš a skontroluješ, čo robíš. Bez tých pohľadov je z neho tapeta.

Po tretie, pravidlá neriešia konflikty medzi sebou. Keď je dokumentácia stará a chat nový, motto ti nepovie, ktorému uveriť, tu pomôže iba postup z nasledujúceho dielu: over čísla, nie tón.

## Čo bude ďalej

Diel 5, overuj čísla, nie tón. Ako sa overuje bezpečnostné tvrdenie: databáza, advisory, porovnanie verzií. „AI klame ... a niekedy má pravdu, preto overuj čísla, nie tón.“

## Zdroje

- [Pacman, Arch Wiki](https://wiki.archlinux.org/title/Pacman),
  dokumentácia ako primárny zdroj pravidiel z dielu 1
- [Flatpak, dokumentácia](https://docs.flatpak.org/en/latest/),
  sandbox a povolenia z dielu 3
- [CachyOS Wiki](https://wiki.cachyos.org/): repozitáre a konvencie
  distribúcie
