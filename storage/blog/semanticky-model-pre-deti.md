---
title: "Rust a Lekalo pre deti ... diel 5: sémantický model aplikácie — opísať app bez opísania kódu"
series: "Rust & Lekalo"
series-part: "5"
date: "2026-09-06"
version: "1.0.2"
tags: ["rust", "lekalo", "semanticky-model", "architektura", "ai"]
excerpt: "Diel 5 série. Pred Lekalo finále: čo je sémantický model aplikácie. Model nie je zdrojový kód a nikdy kód neparsuje — je to jazykovo neutrálny opis entít, commandov, policy a endpointov, žijúci v pevných YAML/JSON homech. Ako príklad použijeme vlastný lekalo/ adresár tohto repa."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "semantic-application-models"
published: true
---

> Toto je diel 5 série **Rust a Lekalo**. Štyri diely sme sa bavili o
> *jazyku*: čo Rust je, ako funguje ownership, ako sa správajú jeho tooling
> a kvalita. Teraz vystúpime o úroveň vyššie, k vrstve, ktorá umožní
> posledný diel: k **sémantickým modelom aplikácie**. V [dieli 4](/blog/rust-testovanie-pre-deti)
> sme skončili pri paranoidnom toolingu; dnešný diel je o tom, čo ten tooling
> konzumuje. Ďalej: [diel 6 ... Lekalo deep dive](/blog/lekalo-pre-deti).

Tu je kľúčové tvrdenie, a chvíľu si ho nechaj uležať: model aplikácie *nie
je* zdrojový kód aplikácie. A nástroj, ktorý pracuje s modelmi, **neparsuje**
zdrojový kód. Lekalo effect engine je zdokumentovaný ako „never detection
from source and never inference from names“. Všetko, čo vie, vie preto, že
to niekto **deklaroval** — v YAML/JSON dokumentoch, ktoré opisujú aplikáciu
na sémantickej úrovni.

## Čo model opisuje

Ak zdrojový kód odpovedá na *ako*, sémantický model odpovedá na *čo*.
Lekalo model slovník má štrnásť druhov definícií a pekne sa mapujú na to,
ako sa PHP modul ako `Users` z tohto kitu správa:

| Druh | Čo deklaruje | Príklad z tohto repa |
|---|---|---|
| `entity` | vec s identitou a poliami | `User`, `Role`, `Permission`, `Session` |
| `scalar` / `enum` / `value-object` | typy polí | `email`, `password`, `account_state` |
| `command` | akcia so vstupmi a efektmi | `register`, `login`, `reset_password` |
| `query` | čítanie s deklarovanými readmi | `find_user_by_email`, `current_user` |
| `policy` | pravidlo na commande (allow/deny) | `password_min_length`, `admin_only_rbac` |
| `event` | niečo, čo sa stalo | `user_registered`, `session_started` |
| `effect` | create/update/delete na entite | `effect_user_created` |
| `endpoint` | HTTP povrch volajúci command/query | `POST /login` → `login` |
| `scenario` | používateľský tok pokrývajúci symboly | register → login, password reset |

Všimni si, čo chýba: žiadne triedy, žiadne balíky, žiadne framework koncepty,
žiadne implementačné detaily. Model kontrakt je explicitný, že „obsahuje
žiadne target-language class, package, framework or runtime koncepty“ —
rovnaký slovník opisuje TypeScript, PHP alebo Go aplikáciu. To znamená
*jazykovo neutrálny*: modeluješ *aplikáciu* a implementačný jazyk je
dodatočná myšlienka.

## Pevné home-y

Keďže model sú dokumenty, nie kód, jeho umiestnenie je kontrakt. Lekalo
projekty majú fixný layout:

```
lekalo/
├── project.yaml                 # presne jedna project definícia
├── modules/<modul>/
│   ├── module.yaml              # presne jedna module definícia
│   ├── entities.yaml            # scalar, enum, value-object, entity
│   ├── commands.yaml            # command, effect
│   ├── queries.yaml             # query
│   ├── policies.yaml            # policy
│   ├── events.yaml              # event
│   ├── scenarios.yaml           # scenario
│   └── bindings.yaml            # endpoint, target-binding
└── targets/<target>.yaml        # target konfigurácia
```

Každá definícia je uzavretý objekt so `schema_version` — a loader akceptuje
presne dva literály: `0.1.0` a `1.0.0`. Čokoľvek iné je
`unsupported-version`, exit 5. Definícia v zlom súbore (napr. entita v
`commands.yaml`) sa odmietne. Model je prísny, lebo je kontrakt: ak sú
dokumenty nejednoznačné, platí cenu každý downstream konzument — vrátane AI
agenta.

Môžeš si otvoriť vlastný `lekalo/` adresár tohto repa a vidieť to všetko
naživo: projekt `nativa`, modul `users`, 53 definícií opisujúcich auth, RBAC
a password reset — JSON obsah v `.yaml` súboroch (loader akceptuje prísny
JSON frontend), validovaný `lekalo validate` s 23 pravidlami a nulou chýb.
Je to fungujúci príklad formátu, committnutý vedľa PHP kódu, ktorý opisuje.

## Prečo vôbec modelovať aplikáciu?

Ak model neparsuje kód, udržiavať ho v synchronizácii je ručná práca. Tak
prečo? Tri odpovede, všetky mierené na skutočné publikum nástroja —
AI-assisted development:

**Determinizmus.** AI agent nemôže bezpečne čítať celý tvoj codebase; môže
bezpečne konzumovať validovaný model s byte-identical kanonickým výstupom.
Agentove fakty prichádzajú z kontraktu, nie z hádania v zdrojových
súboroch.

**Kontext.** `lekalo context` projektuje minimálno-dostačujúcu kontextovú
kapsulu pre jeden symbol pod token budget — chránené sémantické fakty ako
typované záznamy, nikdy nezbalené do prózy, bez source bajtov, secretov
alebo absolútnych ciest unikajúcich do kapsuly. Pre agenta s token oknom je
to presne ten tvar užitočného kontextu.

**Traceabilita.** Model spája requirements → symboly → testy → gates
v neutrálnom trace manifeste, takže „má táto zmena dôkazy?“ je query, nie
archeologický projekt.

Poctivá cena je tá, ktorú si si všimol: model sa musí udržiavať.
Protiargument je, že tento kit už udržiava *kanonický spec* v
`openspec/specs/` a roadmapu — konvencie projektu považujú „opíš zámer
oddelené od kódu“ za normálnu prax. Lekalo berie rovnaký inštinkt a robí ho
strojovo čitateľným.

## Finále je jeden diel ďaleko

Teraz máš všetky ingrediencie: jazyk (diely 1-2), tooling a kvalitu
(diely 3-4) a modelovú vrstvu (tento diel). Diel 6 to celé spustí end-to-end
na Users modeli v tomto repozitári — validácia, grafy závislostí, impact
analýza a kontextové kapsuly, naživo:

[ďalej: Lekalo deep dive — finále](/blog/lekalo-pre-deti)

## Zdroje

- [Lekalo — Model kontrakt](https://github.com/ichinya/lekalo/blob/main/docs/model.md) — druhy definícií a sémantika
- [Lekalo — Loader](https://github.com/ichinya/lekalo/blob/main/docs/loader.md) — YAML/JSON frontendy, kanonický model
- [Lekalo — Kanonická štruktúra projektu](https://github.com/ichinya/lekalo/blob/main/docs/canonical-structure.md) — model home-y a root discovery
- [Tento repozitár — lekalo/ model](/blog/lekalo-pre-deti) — fungujúci Users model, na ktorý sa článok odvoláva
