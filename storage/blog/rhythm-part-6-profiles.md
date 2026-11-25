---
title: "Bug Story, Tutorial, Filozofia ... výber rytmu"
date: "2026-08-29"
version: "1.0.1"
tags: ["writing", "narrative-rhythm", "technical-writing", "blog"]
excerpt: "Nie všetky články majú rovnaký rytmus. Bug story má dlhú fázu hmly. Tutorial má dlhú fázu slnka. Filozofický článok má dlhú fázu noci. Účel článku si vyberá rytmus."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Writing"
translation: "rhythm-part-6-profiles"
published: true
---

Píšete článok o bugu, ktorý ste opravili. Má nasledovať rovnaký rytmus ako tutorial o nastavení Dockeru?

Nie. Bug story potrebuje dlhú fázu hmly, čitateľ potrebuje cítiť problém. Tutorial potrebuje dlhú fázu slnka, čitateľ potrebuje vidieť riešenie krok za krokom. Účel článku si vyberá rytmus.

## Bug Story: hmla dominantná

Bug story je o probléme, ktorý sa stal a ako ste ho opravili. Čitateľ chce cítiť zmätok, potom úľavu.

```
INTRO  [10%]  Hák
HMLA   [35%]  Dlhá — problém narastá, kaskáda, chaos
SLNKO  [20%]  Rýchle — fix je zvyčajne jednoduchý
NOC    [15%]  Stredná — prečo sa to stalo
OBJEKT [10%]  Krátke — X zostáva, len nie tu
TÉMA   [5%]   Jedna veta
OUTRO  [5%]   Odkazy
```

Fáza hmly dominuje, pretože príbeh je o probléme. Fáza slnka je krátka, pretože opravy bugov sú zvyčajne na jednom riadku. Cesta čitateľa je: zmätok → jasnosť → pochopenie.

**Príklad:** „Kliknite stránku 3 ... a pager sa zdvojnásobil“

Článok trávi 44% dĺžky popisom problému. Oprava sú tri riadky kódu. To je správne, príbeh je o bugu, nie o oprave.

## Tutorial: slnko dominantná

Tutorial je o učení sa niečoho nového. Čitateľ chce vidieť riešenie, krok za krokom, s kódom.

```
INTRO  [5%]   Hák (krátka)
HMLA   [10%]  Krátke — "toto ešte nefunguje"
SLNKO  [50%]  Dlhá — krok za krokom, veľa code
NOC    [10%]  Krátke — prečo taký prístup
OBJEKT [10%]  Stredná — kedy použiť
TÉMA   [5%]   Jedna veta
OUTRO  [10%]  Odkazy na ďalšie kroky
```

Fáza slnka dominuje, pretože príbeh je o riešení. Fáza hmly je krátka, čitateľ vie, že nevie ako to urobiť. Cesta čitateľa je: medzera → vyplnenie → dokončenie.

**Príklad:** „MoonShine Features + HTMX“

Článok trávi väčšinu dĺžky ukázaním, ako stavať toast notifikácie, live search a form submit. Problém („admin panely potrebujú JavaScript framework“) je vyslovený raz a opustený.

## Filozofia: noc dominantná

Filozofický článok je o chápaní, prečo niečo je také, aké je. Čitateľ chce premýšľať, nie učiť sa.

```
INTRO  [10%]  Hák
HMLA   [10%]  Krátke — kontext
SLNKO  [15%]  Krátke — fakty
NOC    [40%]  Dlhá — reflexia, hĺbka, "prečo"
OBJEKT [10%]  Stredná — uzavretie
TÉMA   [10%]  Možno 2-3 vety (výnimka)
OUTRO  [5%]   Odkazy
```

Fáza noci dominuje, pretože príbeh je o chápaní. Fáza slnka je krátka, fakty sa dajú rýchlo. Cesta čitateľa je: otázka → reflexia → pochopenie.

**Príklad:** „Filozofia menej vrstiev“

Článok trávi väčšinu dĺžky vysvetľovaním, prečo závislosti záležia. Code ukážky sú minimálne. Cesta čitateľa je: „prečo nula závislostí?“ → pochopenie → súhlas.

## Review / Comparison: vyvážený

Review článok porovnáva možnosti. Čitateľ chce fakty, tabuľky a jasné odporúčanie.

```
INTRO  [10%]  Hák
HMLA   [15%]  "Prečo porovnávame"
SLNKO  [30%]  Fakty, tabuľky, code
NOC    [20%]  "Čo sme sa naučili"
OBJEKT [10%]  Kedy použiť čo
TÉMA   [10%]  Jedna veta
OUTRO  [5%]   Odkazy
```

Žiadna fáza nedominuje. Cesta čitateľa je: kontext → fakty → pochopenie → rozhodnutie.

**Príklad:** „Porovnanie tém: Starter vs Magazine vs Minimal“

Článok prezentuje tri témy s rovnakou váhou. Fáza noci vysvetľuje filozofiu za každou. Cesta čitateľa je: „aké sú možnosti?“ → porovnanie → voľba.

## Ako rozpoznať profil

Položte jednu otázku: **o čom článok je?**

```markdown
# Rozhodovací strom:
Problém, ktorý sa stal → Bug Story (HMLA dominantná)
Učenie sa niečoho nového → Tutorial (SLNKO dominantná)
Chápanie prečo → Filozofia (NOC dominantná)
Porovnávanie možností → Review (vyvážený)
```

Nástroj rhythm diagram vám ukáže, aký profil má váš článok:

```bash
php bin/rhythm-diagram.php storage/blog/en/article.md
```

```
Profile: Bug Story
HMLA dominance: 44% | SLNKO: 16% | NOC: 33%
```

Ak profil nezodpovedá vášmu úmyslu, prestavte článok.

---

Účel článku si vyberá rytmus. Nie naopak. Bug story s dlhou fáhou slnka je tutorial v disguise. Filozofický článok s dlhou fáhou hmly je bug story, ktorý zabudol byť o chápaní.

---

→ [Part 1, Článok, ktorý nikto nedočítal](/blog/rhythm-part-1-nobody-finished): prečo rytmus znamená viac
→ [Part 2, Fog a Sun](/blog/rhythm-part-2-fog-and-sun): ako písať problémy, ktoré udržia pozornosť

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
