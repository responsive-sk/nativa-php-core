---
title: "Arch balíky pre deti ... diel 5: overuj čísla, nie tón"
series: "Arch balíky pre deti"
series-part: "5"
date: "2026-09-24"
version: "1.0.0"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "Piata lekcia série: bezpečnostné tvrdenie sa overuje číslami, nie tónom. Štyri kroky: databáza, advisory, porovnanie verzií, rozhodnutie."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "ai-security-claims"
---

> [Diel 1](/blog/pacman-pre-deti) ukázal prvý príkaz, [diel 2](/blog/repozitare-a-dovera-pre-deti) zoradil rebrík dôvery, [diel 3](/blog/flatpak-pre-deti) otvoril Flatpak ako výnimku, [diel 4](/blog/kluc-trust-nothing-pre-deti) zlepil motto na kľúč na monitor. Dnes diel 5 — pravidlo, ktoré drží celú sériu pri zemi: bezpečnostné tvrdenie sa overuje číslami, nie tónom.

Bezpečnostné tvrdenie prichádza rovnako vždy: niekto ti ho vloží do chatu, napíše ho AI alebo ho nájdeš na fóre. Znie istotne, hovorí o root prístupe a končí naliehavým „aktualizuj hneď“. Presne na takéto momenty pripravilo motto z [dielu 4](/blog/kluc-trust-nothing-pre-deti) papier pri monitore — a táto lekcia ukáže, čo robiť, keď ho prečítaš.

## Overuj čísla, nie tón

Tón je najlacnejšia časť každej odpovede. Istý tón neznamená istú pravdu — a plačlivý tón neznamená lož. Jediné, čo oddeľuje fakt od ficru, je číslo, ktoré vedie k záznamu.

Pravidlo znie: **každé bezpečnostné číslo musíš nájsť v databáze, ktorú nevlastní ten, kto ti ho servíroval.** Ak sa číslo nájde, máš oficiálny záznam. Ak sa nenájde, nemáš fakt — máš reťazec.

Postup má štyri kroky a každý má svoje miesto:

| Krok | Kde | Čo získaš |
|---|---|---|
| 1. nájdi číslo | NVD, security tracker, advisory projektu | oficiálny záznam — alebo ticho |
| 2. prečítaj affected/fixed | ten istý záznam | verzie, ktorých sa to týka |
| 3. porovnaj so systémom | `pacman -Si flatpak` | verzia, ktorú máš k dispozícii |
| 4. rozhodni | ty | aktualizovať / spokojne spať |

## Ako vyzerá záznam a ako vyzerá tlač

Oficiálny záznam hovorí suchým jazykom: číslo, produkt, dotknuté verzie, opravené verzie, odkaz na advisory, meno objaviteľa. Žiadne dramatické vety, žiadne výkričníky — jeho prácou je presnosť, nie strach.

Práve ten rozdiel je test. Varovanie apeluje, záznam popisuje. Ak ťa text tlačí k okamžitému rozhodnutiu pod strachom a neuvádza číslo, ktoré vedie do databázy, nie je to zdroj — je to tlač.

Ak sa číslo nájde, tretí krok rozhoduje o tom, či sa ťa chyba týka. `pacman -Si flatpak` vypíše verziu v repozitári a `sudo pacman -Syu` ju posunie nahor. Ak tvoja nainštalovaná verzia dosahuje alebo presahuje opravenú, problém sa ťa netýka — aktualizovaný systém je odpoveďou na väčšinu advisory v histórii.

## AI klame ... a niekedy má pravdu

Úprimne do oboch strán.

Niekedy má AI pravdu: chyba existuje, je závažná a aktualizovať treba. Rada „over si verziu a aktualizuj“ je dobrá vždy — lebo aktualizovať je vždy správna odpoveď, aj keď dôkazy chýbajú.

Niekedy klamie: čísla si doplní, formát si vypožičie od databázy a istotu si vypožičie od seba. Neznamená to zlý úmysel — jazykové modely skladujú vzory, nie evidenciu. Zoznam CVE je vzor; čísla v ňom sú náplň.

Preto motto neznie „nechatuj AI“ ani „ver AI“. Znie: overuj čísla, nie tón. AI je dobrý sprievodca a zlý zdroj — sprievodca ti ukáže cestu, zdroj nesie zodpovednosť za každé číslo.

## Úprimne — kde sa postup láme

Tri situácie, keď štyri kroky nestačia.

Po prvé, NVD môže meškať. Nové chyby sa objavia v advisory projektu skôr, než dostanú CVE záznam — preto je prvou zastávkou advisory projektu a až potom databáza. Ticho v NVD nie je definitívny verdikt, je to jeden z údajov.

Po druhé, číslo bez kontextu zlieva. Existujúci záznam neznamená, že sa ťa týka: treba prečítať affected verzie a spôsob zneužitia. Záznam o chybe servera sa na desktop stroj netýka ani náhodou — aj to je odpoveď.

Po tretie, postup nehodnotí úmysel. Zistíš, či číslo existuje — nezistíš, prečo ho niekto napísal. Na to slúži iný test: ak chce niečo nútiť k rýchlemu rozhodnutiu pod strachom, tempo je varovanie. Bezpečnostná pravda má čas čakať na overenie.

## Čo bude ďalej

Diel 6 — sudo LAST. Posledné pravidlo motta ako samostatná lekcia: kedy sudo patrí (`pacman -S`, `-Syu`, `-Sc`), kedy nie (žiadne `sudo yay`, žiadne `curl … | sudo bash`) a prečo „sudo LAST“ znamená aj „sudo najmenej“.

## Zdroje

- [NVD — National Vulnerability Database](https://nvd.nist.gov/) —
  databáza CVE záznamov, krok 1 postupu
- [Arch Security Tracker](https://security.archlinux.org/) —
  advisory a affected/fixed verzie pre Arch balíky
- [Flatpak security advisories — GitHub](https://github.com/flatpak/flatpak/security/advisories) —
  oficiálne advisory projektu Flatpak
- [Pacman — Arch Wiki](https://wiki.archlinux.org/title/Pacman) —
  porovnanie verzií a aktualizácie
