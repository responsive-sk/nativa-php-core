---
title: "Tempo ... prečo dĺžka vety je dizajnové rozhodnutie"
date: "2026-08-29"
version: "1.0.1"
tags: ["writing", "narrative-rhythm", "technical-writing", "blog"]
excerpt: "Steinbeck vedel, že krátke vety vytvárajú napätie a dlhé ho uvoľňujú. Rovnaké pravidlo platí pre technické články."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Writing"
translation: "rhythm-part-4-tempo"
published: true
---

Prečítajte si dve verzie tej istej vety:

```markdown
# Verzia A
Server skúsil neočakávané ukončenie procesu PHP FPM worker
v dôsledku nedostatočnej alokácie pamäte vyplývajúcej
z východiskového parametra memory_cost hashovacieho
algoritmu argon2id prekračujúceho dostupné zdroje.

# Verzia B
Server spadol. Dosť pamäte. Argon2id pýtal 64 MB.
Shared hosting mal 128 MB na všetko.
```

Rovnaká informácia. Verzia A má 42 slov. Verzia B má 22 slov. Verzia B je tá, ktorú si zapamätáte.

## Prečo záleží na dĺžke vety

Dĺžka vety nie je štýlová voľba, je to nástroj rytmu. Krátke vety vytvárajú napätie. Dlhé ho uvoľňujú. Striedanie medzi nimi vytvára pulz, ktorý udrží čitateľa.

Steinbeck to vedel. Jeho vety sa striedajú medzi údernými a plynúcimi:

```markdown
Jesenné listy preleteli mesačne osvetlenými ulicami.
Muž v čiernom kabáte kráčal sám.
Ruky mal vo vreckách.
Vetrol bol studený.
```

Štyri krátke vety. Potom by dlhšia veta prerušila vzorec. Samotný rytmus rozpráva príbeh.

## Tempo podľa fázy

Každá fáza má svoje tempo:

### INTRO: úderné (5-15 slov)
```markdown
Kliknite stránku 3. Pager sa zdvojnásobil.
Kliknite znova. Ztrojnásobil.
```

Tri vety. Každá je úder. Čitateľ je chytený skôr, než stihne odísť.

### HMLA: staccato (8-15 slov)
```markdown
Mali sme hotový, otestovaný, bezpečný systém.
Všetko fungovalo na vývoji, v Dockeri, na VPS.
Potom prišiel shared hosting.
Prvý prihlasovací pokus: server spadol.
```

Krátke vety budujú tlak. Každá pridá problém. Čitateľ cíti, ako sa hmla zahusťuje.

### SLNKO: plynutie (15-25 slov)
```markdown
Server vracia celý HTML blob: search input,
count info, tabuľka, paginácia — v jednom reťazci.
HTMX nájde target element a nahradí ho.
```

Dlhšie vety teraz. Napätie sa uvoľňuje. Čitateľ chápe riešenie.

### NOC: reflektívne (12-20 slov)
```markdown
Cache nie je ani automatická odpoveď, ani nepriateľ.
Je to nástroj — a každý nástroj má svoje miesto.
```

Stredné vety. Tiché. Čitateľ premýšľa, neučí sa.

### TÉMA: jedna veta (10-20 slov)
```markdown
Magia frameworku je taká dobrá,
aké dobré HTML mu naservírujete.
```

Jedna veta. Maximálny dopad. Čitateľ si ju nesie so sebou.

## Tempo analyzer

Vytvorili sme nástroj, ktorý meria dĺžku viet v sekciách:

```bash
php bin/tempo-analyzer.php storage/blog/en/article.md
```

Výstup:
```
[OK] INTRO    Avg: 9 words/sentence (target: 5-15)
[OK] HMLA     Avg: 11 words/sentence (target: 8-15)
[!] SLNKO    Avg: 11 words/sentence (target: 15-25)
[OK] NOC      Avg: 13 words/sentence (target: 12-20)
```

Sekcia SLNKO má vety, ktoré sú príliš krátke. To je signál: vysvetlenie neplynie. Oprava: spojte súvisiace vety, pridajte kontext, nechajte vysvetlenie dýchať.

## Vzorec rytmu

Plný rytmus vyzerá takto:

```
INTRO:   ▓▓░░░░░░░░  krátke, úderné
HMLA:    ▓▓▓░░░░░░░  staccato, narastajúce
SLNKO:   ▓▓▓▓▓░░░░░  plynúce, vysvetľujúce
NOC:     ▓▓▓░░░░░░░  stredné, reflektívne
OBJEKT:  ▓▓░░░░░░░░  2-3 vety
TÉMA:    ▓░░░░░░░░░  jedna veta
OUTRO:   ▓░░░░░░░░░  1-2 vety + odkazy
```

Krátke, krátke, dlhé, stredné, krátke, jedna, hotovo. To je pulz.

---

Tempo zostáva nástrojom, nie pravidlom. Niektoré články potrebujú dlhšie fázy HMLA. Niektoré kratšie SLNKO. Ciele sú usmernenia, rytmus je cieľ.

---

Každé slovo musí pracovať. Ak slovo nepridáva význam, vyhoďte ho. Ak veta nepridáva rytmus, rozdeľte ju. Tempo nie je dekorácia, je to štruktúra.

---

→ [Part 5, Two Tones](/blog/rhythm-part-5-bilingual): písanie v EN a SK bez straty duše
→ [Part 3, Night, Object, Theme](/blog/rhythm-part-3-night-object-theme): časti, ktoré robia články zapamätateľnými

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
