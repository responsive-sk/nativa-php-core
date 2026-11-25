---
title: "Arch balíky pre deti ... diel 3: Flatpak ako výnimka"
series: "Arch balíky pre deti"
series-part: "3"
date: "2026-09-24"
version: "1.0.0"
published: true
tags: ["arch", "linux", "pacman", "cachyos", "security", "beginners"]
excerpt: "Tretia lekcia série: ako sandbox obmedzuje pohľad aplikácie na systém, prečo inštalácia platí iné pravidlá ako beh a čo presne znamená pravidlo „nativné prvé“."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "flatpak-for-kids"
---

> [Diel 1](/blog/pacman-pre-deti) ukázal prvý príkaz, [diel 2](/blog/repozitare-a-dovera-pre-deti) zoradil miesta, odkiaľ balíky prichádzajú — rebrík dôvery. Dnes diel 3: Flatpak, stupeň 3 rebríka. Ako sandbox funguje, prečo si inštalácia vyžaduje pozornosť a kedy je výnimka z pravidla „nativné prvé“ legálna.

V dieli 2 si videl, že Telegram existuje aj v oficiálnych repozitároch — `pacman -S telegram-desktop` ti ho nainštaluje bez Flatpaku. Ty si ho ale vtedy inštaloval cez Flatpak, lebo tak ti poradila AI.

Nie je to chyba, za ktorú sa treba hanbiť. Je to však presne ten prípad, kvôli ktorému rebrík existuje: balík bol na stupni 1, ty si siahol po stupni 3. Táto lekcia ukáže, čo sa pri tom deje — a kedy je stupeň 3 správna voľba.

## Čo je sandbox

**Sandbox** je nájomný byt pre aplikáciu. Aplikácia v ňom žije, behá a pracuje — ale pohľad von má obmedzený. Dokumentácia Flatpaku vymenováva, čo byt vidí defaultne:

| Čo aplikácia vidí | Sandbox defaultne |
|---|---|
| hostiteľské súbory | len vlastné adresáre aplikácie |
| sieť | žiadnu |
| zariadenia | žiadne (okrem `/dev/null` a pod.) |
| procesy mimo bytu | žiadne |
| systémové služby (D-Bus, X11, zvuk) | žiadne |

Aplikácia môže o tieto veci požiadať — väčšina ich aj potrebuje, inak by nefungovala. Prichádza na rad **portál**: systémový dialóg, ktorý sa pýta teba. Keď si v ňom vyberieš súbor, tvoj výber je zároveň povolenie — aplikácia dostane práve ten jeden súbor, nie celý disk.

A tu je veta, ktorú si zapamätaj: povolenia v sandboxe sú **statické**. Zapisujú sa raz do manifestu aplikácie — žiadne premenné, žiadna expanzia. Čo vývojár napísal do manifestu, to byt má. Preto povolenia čítaš takisto ako recept v dieli 2 — ako list, nie ako spam.

## Prečo si inštalácia vyžaduje pozornosť

Sandbox chráni beh aplikácie. Inštalácia je iný druh činnosti — a platia pre ňu iné pravidlá.

Flatpak pozná dva režimy: **system-wide** (default — aplikáciu vidia všetci používatelia stroja) a **per-user** (`--user` — len ty). Pri systemovej inštalácii musí Flatpak zapisovať do systémových adresárov — a to za teba nemôže robiť proces v byte bez pohľadu von. Robí ho **system helper**: komponent, ktorý beží s právami správcu.

To nie je konštrukčná chyba — systémovú inštaláciu nemôže urobiť nič, čo právami správcu nedisponuje. Je to však cena: dvere do systému sa otvárajú vždy, keď inštaluješ, nie len keď beháš.

História Flatpaku pozná chyby, cez ktoré sa aplikácia zo sandboxu dokázala dostať ďalej, než mala — niektoré z nich sa týkali práve cesty medzi sandboxom a systémom. Boli opravené; pravidlo z nich zostáva:

| Činnosť | Čo ju chráni | Tvoja pozornosť |
|---|---|---|
| beh aplikácie v sandboxe | izolácia bytu | nízka |
| inštalácia system-wide | system helper pod právami správcu | vysoká |
| aktualizácie | ten istý kanál ako inštalácia | vysoká |

Ešte jeden pohľad na to isté: pacman inštaluje balíky, ktorých podpisy overuje nástroj. Flatpak na stupni 3 inštaluje z repozitára vydavateľa — dôvera stojí na ID vydavateľa a na remote, ktorý si pridal. `flathub` je hlavný overený repozitár; remotes pridané z návodov na internete sú stupeň 4 v maske stupňa 3.

## Dva príkazy a jedno ID

Inštalácia z Flathubu:

```bash
flatpak install flathub org.telegram.desktop
```

`org.telegram.desktop` je **ID aplikácie** — trojdielny názov, v ktorom koncová časť je meno aplikácie a predchádzajúce časti povedali, kto ju vydáva. Nie je to meno balíka; cez `flatpak search telegram` ho nájdeš, keď presné ID nepoznáš.

Aktualizácia všetkého, čo máš cez Flatpak:

```bash
flatpak update
```

A kontrola, čo máš nainštalované:

```bash
flatpak --version
flatpak list --app
```

Pravidlo, kvôli ktorému táto lekcia existuje: **Flatpak neaktualizuje pacman.** Balíky z repozitárov aktualizuje `pacman -Syu` — Flatpak aplikácie majú vlastný aktualizačný kanál. Kto Flatpak používa a neaktualizuje, stojí na mieste — so starými verziami všetkého, čo si cez neho dal. Jeden príkaz, pravidelne: `flatpak update`.

## Pravidlo „nativné prvé“

CachyOS konvencia z dielu 2 — natívne balíky na prvom mieste — teraz vyznie presne:

| Situácia | Čo robíš |
|---|---|
| natívny balík v repozitári existuje | `pacman -S` — stupeň 1 |
| natívny neexistuje, recept v AUR je udržiavaný | AUR helper — stupeň 2 |
| natívny ani AUR nie je, vydavateľ vydáva Flatpak | `flatpak install flathub …` — stupeň 3 |
| nikto nič nebalí | hľadaj inú aplikáciu — stupeň 4 ignoruj |

Kedy je stupeň 3 správna voľba: aplikácia nie je v repozitároch ani v AUR; alebo Flatpak nesie výrazne novšiu verziu, na ktorú čakáš; alebo aplikácia prináša vlastné knižnice, ktoré by sa na systéme bitkovali s existujúcimi. Sú to výnimky — a výnimky fungujú, keď majú pravidlá: inštaluj z overeného remote, čítaj povolenia, aktualizuj.

## Úprimne — kedy Flatpak nie je správna voľba

Tri prípady, keď sa stupeň 3 vyhne:

Po prvé, natívny balík existuje a nechýba ti nič. Inštalácia cez Flatpak pridá druhú kópiu aplikácie, druhý aktualizačný kanál a druhé miesto, kde hľadať, prečo vec nefunguje.

Po druhé, záleží ti na systémovej integrácii. Sandbox je stena aj pre veci, ktoré chceš — integrácia so systémovou témou, s dialógmi a s ostatnými aplikáciami beží cez portály, nie priamo.

Po tretie, nechceš si pamätať ďalšie pravidlo. Rebrík je jednoduchý presne preto, že má málo výnimiek. Každý nástroj, ktorý si pridáš, si musíš aj aktualizovať — a Flatpak ti túto úlohu nezoberie, len ju presunie k sebe.

## Čo bude ďalej

Diel 4 — kľúč na monitor. Motto série „TRUST NOTHING. READ THE DOCS. sudo LAST.“ — tri pravidlá, každé s príkladom z prvých dielov, a prečo ich píšeme na papier pri monitore, nie do hlavy.

## Zdroje

- [Flatpak — Sandbox permissions](https://docs.flatpak.org/en/latest/sandbox-permissions.html) —
  čo sandbox defaultne obmedzuje a ako fungujú portály
- [Flatpak — Using Flatpak](https://docs.flatpak.org/en/latest/using-flatpak.html) —
  režim system versus `--user`, inštalácia, aktualizácia
- [Flathub](https://flathub.org/) — hlavný repozitár Flatpak aplikácií
- [CachyOS Wiki](https://wiki.cachyos.org/) — repozitáre a konvencie
  distribúcie
