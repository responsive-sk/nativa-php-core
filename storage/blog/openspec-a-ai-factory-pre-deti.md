---
title: "OpenSpec pre deti ... diel 5: OpenSpec + AI Factory ... spolupráca človeka a AI (finále série)"
date: "2026-08-09"
version: "1.0.3"
tags: ["openspec", "php", "workflow", "ai"]
excerpt: "Finále série. Ako OpenSpec workflow riadia AI Factory skills ... aif-plan, aif-implement, aif-verify, aif-done ... a prečo AI nevládne, ale asistuje. Všetko na reálnych artefaktoch z tohto repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "openspec-ai-factory"
published: true
---

> Toto je diel 5 ... **finále série** **OpenSpec pre deti**. V [dieli 1](/blog/co-je-openspec-pre-deti)
> sme spoznali change, v [dieli 2](/blog/anatomia-change-pre-deti) jeho päť
> súborov, v [dieli 3](/blog/zivotny-cyklus-change-pre-deti) celý životný
> cyklus a v [dieli 4](/blog/spec-ako-zdroj-pravdy-pre-deti) štyri vrstvy
> dôkazov. Dnes si ukážeme, kto celý ten workflow poháňa ... AI Factory ... a ako
> vyzerá spolupráca človeka a AI. A všetko na reálnych artefaktoch, ktoré ležia
> priamo v tomto repo.

## V sérii bolo „my“ ... kto je „my“?

Celá séria rozprávala v prvej osobe množného čísla: „zmenili sme“, „rozhodli
sme sa“, „presunuli sme“. Ale možno si sa celý čas pýtal: kto je „my“? Človek? AI? Oboje?

Odpoveď je: **tím.** Projekt používa **AI Factory** ... sadu skills,
ktoré riadia presne ten OpenSpec workflow, o ktorom je celá séria. Skills sú
obyčajné súbory v repo:

```text
.agents/skills/
├── aif-plan/ # z požiadavky spraví plan (v našom prípade OpenSpec change)
├── aif-implement/ # vykoná úlohy z tasks.md
├── aif-verify/ # skontroluje, že je všetko naozaj hotové
├── aif-done/ # finalizuje: QA evidence + archivácia
├── aif-archive/ # presun do archívu
├── aif-commit/ # conventional commit
└── … a ďalších dvadsať
```

Každý skill je dokument s inštrukciami ... ako manuál pre AI asistenta. A k nim
patrí priečinok `.ai-factory/`, kde žijú artefakty, ktoré sme celú sériu
otvárali: `config.yaml`, `qa/`, `state/`, `rules/`, `evolutions/`.

## Config ... pravidlá hry, raz na jednom mieste

Všetko, čo sme v sérii ukázali, je zapísané v `.ai-factory/config.yaml` ...
jediný súbor, ktorý hovorí, ako sa v tomto projekte pracuje:

```yaml
aifhub:
  artifactProtocol: openspec # zmeny žijú v openspec/changes/
openspec:
  validateOnVerify: true # overenie spúšťa aj openspec validate
  archiveOnDone: true # hotový change sa archivuje sám
  requireSpecCoverageForDone: true # done vyžaduje pokrytie 100 %
workflow:
  plan_first: true # najprv plan, potom kód
  conventional_commits: true # commity v konvenčnom formáte
  review_gates: [phpstan, cs-fix-dry, rector-dry, test] # presne tie z dielu 3
quality:
  phpstan_level: 9 # level 9, žiadne zľavy
```

Všimni si, že to nie sú len pravidlá pre AI ... to sú **pravidlá pre projekt**.
AI ich číta a dodržiava, ale platia rovnako pre každého, kto do repo siahne.
Config je most medzi človekom a AI: obaja čítajú ten istý návod.

## Aif-verify ... dôkaz, nie sľub

V dieli 4 sme otvorili `verify.md` z change `2026-08-08-mobile-nav-menu` ...
dokument, ktorý vygeneroval `aif-verify`. Teraz vidíme celý príbeh:

```text
.ai-factory/qa/2026-08-08-mobile-nav-menu/
├── verify.md # záverečná správa: 11/11 úloh, všetky gates PASS
├── coverage.json # matica: každá požiadavka → súbory + testy
├── rules.md # rules gate pass
├── done-readiness.json # strojovo čitateľný verdikt
└── done.md # finalizácia: archivované, canonical spec +3
```

A v `.ai-factory/state/2026-08-08-mobile-nav-menu/final-summary.md` je
**záverečný súhrn** ... čo sa implementovalo, aké dôkazy existujú, aké commity
vznikli a čo nasleduje:

```text
# Final Summary: 2026-08-08-mobile-nav-menu
**Status:** done · **Archived:** yes · **Verified:** PASS (11/11 tasks)

## Verification evidence
- verify.md ... PASS, 11/11
- coverage.json ... 3/3 covered
- PHPUnit 160 tests, Codeception 13 tests, PHPStan 0, CS-Fixer 0, Rector 0

## Commits
- 4daf2c6 feat(nav): add CSS-only mobile nav menu ...
```

Toto nie je „AI napísalo, že to funguje". Je to **evidence trail** ... reťaz
dôkazov, ktorú môže skontrolovať každý. AI Factory nehovorí „ver mi"; hovorí
„pozri si tieto súbory".

## Aif-evolve ... AI, ktorá sa učí z vlastných chýb

A teraz to najzaujímavejšie. V `.ai-factory/evolutions/` leží súbor
`2026-08-09-11.35.md`  ... **evolution** z dnešného rána. Je záznam toho, čo
sa AI Factory naučila z predchádzajúcich chýb:

```text
AI napíše kód. Ale kto napíše špecifikáciu? Kto overí, že kód spĺňa to, čo mal? A kto rozhodne, že zmena je hotová?

OpenSpec je workflow, kde človek píše spec a AI implementuje. Ale keď AI implementuje niečo, čo spec nepopisuje — alebo keď spec opisuje niečo, čo AI nepochopila — vzniká medzera. A medzera je miesto, kde sa rodia bugy.

## Recurring patterns
- series growth broke cross-links (2×) ← pri dieloch 1-4 sa 2-krát rozbili krížové odkazy
- curly-quote edit failures (2×) ← 2-krát zlyhal edit na úvodzovkách

## Improvements Applied
- Series cross-linking rule: when a series grows, update EVERY previous part + verify bidirectional nav by grepping blog/ links
- Ground every part in real project code (verified via grep before writing)
- Series verification checklist (HTTP 200 per part, tables/code blocks, cross-links, index card count + BlogIndexTest)
```

A tu je meta-vrstva, ktorou končí celá séria: **tento článok je dôkazom tejto
evolúcie.** Vznikol presne podľa pravidiel, ktoré si AI Factory zapísala dnes
ráno: je ukotvený v reálnych súboroch (config.yaml, verify.md,
final-summary.md ... všetky som si pred písaním prečítal), krížové odkazy na
diely 1–4 sú aktualizované a otestované cez BlogIndexTest. AI sa učí ... a to
učenie je tiež súčasťou repo, nie tajomstvo v hlave.

## Kto rozhoduje? Človek, nie AI

Ale najdôležitejšia vec na záver série: **AI nerobí rozhodnutia ... AI ich
pripravuje a dokumentuje.** Celý čas sme videli, ako to funguje:

| Krok | Robí AI | Rozhoduje človek |
|---|---|---|
| Nápad | Zformuluje požiadavku do change | Čo vôbec riešime |
| Dizajn | Pripraví návrh + fakty z kódu | Ktorý prístup zvolíme (email vs. id…) |
| Non-goals | Navrhne, čo NEROBÍME | Čo je v scope |
| Review | Nájde problémy (F0/F1/F2…) | Ktoré opravíme a ako |
| Verdict | Vygeneruje dôkazy a verdikt | Či to odsúhlasí a archivuje | V tejto sérii to bolo vidieť naživo: keď review našiel tri problémy,
asistent nepovedal „opravím všetko“ ... spýtal sa. A odpovede boli výberové:
niektoré opravy áno, niektoré „len kritické“, niektoré „nič, len záznam“.
AI Factory dodáva **možnosti a dôsledky**; výber je ľudský.

## Človek + AI = tím, ktorý si pamätá

Poďme si zhrnúť celú sériu do troch viet:

1. **OpenSpec robí z rozhodnutí históriu** ... päť súborov na jeden change, archív ako pamäť projektu.
2. **Štyri vrstvy robia z tvrdení dôkazy** ... spec, kód, testy a QA sa navzájom kontrolujú; nič sa neprijíma na „veď to asi funguje“.
3. **AI Factory robí z workflow tím** ... AI pripravuje, dokumentuje a overuje; človek rozhoduje; a obaja čítajú ten istý návod a ten istý archív. A možno najkrajšie na tom celom je, že **táto séria sama je produktom tohto
systému** ... vznikla cez ten istý workflow, o ktorom rozpráva, a jej lekcie sa
vrátili do evolutions, aby pomohli ďalším. To je uzavretý kruh, ktorý sa
s každým change posúva o krok ďalej. Ďakujeme, že si to dočítal až sem. A ak sa chceš pozrieť, ako to celé vyzerá
v praxi ... otvor si `openspec/changes/archive/` a prečítaj si pár zmien.
Každá je príbeh.

→ [OpenSpec pre deti ... diel 1](/blog/co-je-openspec-pre-deti) — čo je OpenSpec
→ [Anatómia change](/blog/anatomia-change-pre-deti) — päť súborov, päť otázok
→ [Flow a ADR](/blog/flow-a-adr-starter-kitu) — mindset za starter kitom

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse nástroj
