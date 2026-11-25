---
title: "Rust a Lekalo pre deti ... diel 7: model rastie, autorizácia, blog a gates na každom pushi"
series: "Rust & Lekalo"
series-part: "7"
date: "2026-09-08"
version: "1.0.1"
tags: ["rust", "lekalo", "semanticky-model", "autorizacia", "architektura"]
excerpt: "V dieli 6 sme spustili Lekalo naživo na 53 definíciách. Včera model zdvojnásobil na 106 uzlov, autorizácia sa stala deklarovaným kontraktom, blog vstúpil do modelu a toolchain sa stal gate-om, ktorý beží pri každom pushi, s poctivými limitmi a živým výstupom."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-model-grows"
published: true
---

> Toto je diel 7 série **Rust a Lekalo**. V dieli 6 sme spustili Lekalo
> naživo na Users modeli, 53 definícií, 23 pravidiel, nula chýb
> ([diel 6](/blog/lekalo-pre-deti)). Včera sa model rozhodol dospieť:
> pribudla autorizácia, pribudol blog a celý toolchain sa zamkol
> do gate-u, ktorý beží pri každom pushi.

Každé číslo v tomto článku je živý výstup z tohto repozitára, spustený
dnes. Nič nie je vymyslené.

## 1. Graf sa takmer zdvojnásobil

V dieli 6 mal model len jeden modul, Users, a graf závislostí ukázal
`55 nodes, 106 edges`. Včera pribudol Blog modul. Dnes graf hovorí:

```bash
$ lekalo graph export --json
status: valid
nodes: 106 edges: 189
```

Takmer dvojnásobok uzlov, 78 hrán navyše. Prečo? Blog povrch je veľký:
šesť read query (index, podľa slugu, full-text search, tagy, kategórie,
série), štyri admin commandy so štyrmi efektmi a dvoma eventmi, desať
endpointov, štyri scenáre a jedna policy (`blog.cover_owned_by_article`),
ktorá drží cover obrázky pri ich článku. Všetko deklarované, nič
spustené. Lekalo zostáva čistá deklarácia, PHP runtime je stále
písaný ručne.

## 2. Autorizácia sa stala kontraktom (#25)

Najväčšia zmena nebola nový modul, bol to nový dokument v koreni
repozitára: `lekalo/authorization.yaml`. Hovorí, kto smie čo robiť,
rečou modelu:

```yaml
schema_version: "lekalo/authorization/v1.0.0"
roles:
  - id: users.role.guest
  - id: users.role.member
    implies: ["users.role.guest"]
  - id: users.role.admin
    implies: ["users.role.member"]
policies:
  - id: users.policy.public_auth_flow
    actor: public
    decision: allow
    error_ref: "users.error.unauthorized"
    applies_to: ["users.register", "users.login", "users.request_password_reset", "users.reset_password"]
  - id: users.policy.admin_role_management
    actor: identity.user
    decision: allow
    error_ref: "users.error.forbidden"
    applies_to: ["users.create_role", "users.delete_role", "users.sync_role_permissions", "users.list_roles"]
    roles: ["users.role.admin"]
```

Traja aktéri (`public`, `identity.user`, `internal`), reťaz rolí, ktorá
zrkadlí RBAC kitu (`guest -> member -> admin`, admin zahŕňa membera,
member zahŕňa guesta), a štyri policy pokrývajúce všetky Users commandy
a query. Doménové pravidlá, ktoré *nie sú* autorizácia, dĺžka hesla,
unikátny email, neaktívny účet, zostávajú zámerne model-level policy.
Autorizácia a doménová logika sú rôzne kontrakty.

Poctivá poznámka: autorizácia tu znamená **deklaráciu a validáciu, nie
vynucovanie**. Lekalo dokáže, že policy sú dobre sformované a symboly,
na ktoré odkazujú, existujú. Nikdy ich nespúšťa. Runtime RBAC je stále
ručne písané PHP, model a runtime sa zhodujú preto, že oba držíme
poctivé, nie preto, že jeden generuje druhý.

## 3. Blog modul: read-side gates v command-only gramatike

Modelovanie blogu odkrylo skutočný limit súčasnej schémy. Model 1.0.0
policy gramatika akceptuje len commandy, ale dve najdôležitejšie brány
blogu sú read-side: members-only brána na čítanie článku a prekladová
parita medzi `sk/` a `en/` adresármi. Riešenie je poctivé a
zdokumentované: tieto read-side brány žijú v
`lekalo/modules/blog/policies.yaml` s komentárom, prečo ešte nemôžu byť
first-class authorization policy.

Zvyšok modulu je učebnicový. Identita článkovej entity je
`slug + locale`: presne ten pár, ktorý článok reálne adresuje na disku
(`storage/blog/{sk,en}/{slug}.md`):

```bash
$ lekalo inspect blog.article_by_slug
symbol: blog.article_by_slug
kind: query
module: blog
contract:
  reads: blog.article
  returns: blog.article?
effects: state=available
  - read operation:blog.article_by_slug -> blog.article (canonical)
dependents: state=available
  - exposes blog.endpoint_article (canonical)
  - exposes blog.endpoint_cover (canonical)
```

Jedna query, projektovaná: čo číta, čo vracia, kto ju vystavuje. A
scenáre to spájajú end-to-end,`read_flow`, `search_flow`,
`members_gate_flow`, `admin_editing_flow`: model pokrýva životný
cyklus, nielen symboly.

## 4. Gates: model sa stal povinným

Najdôležitejší commit včera nebola funkcia, bol to lock.

```bash
$ lekalo lock --check
lock checked sha256:d6550607… resolver 1.0.0 (adapters 0, generators 0, profiles 0, capabilities 0)
```

Re-lock z 0.1.26 na 0.1.30 zmenil v lockfile presne dve polia,
`core.version` a `resolver.request_digest`: lebo kontraktové digesty sa
posunuli upstream. Samotná rezolúcia sa nezmenila. A lock už nie je
dokumentácia, je to gate:

- `make lekalo-check`: `lock --check` + `validate`: je zapojené do
  `pre-commit` a beží v CI pri každom pushi.
- CI job inštaluje CLI na pinovanej verzii `v0.1.30`, takže gate sa nikdy
  ticho neposunie.
- `docs/lekalo-model.md` dokumentuje layout, re-lock workflow a version
  policy, ďalšia zmena modelu sa nebude musieť spätne luštiť.

```bash
$ lekalo validate --project .
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
```

## Poctivé limity (stav k 0.1.30)

Včerajšia práca tiež nakreslila hranicu toho, čo ešte nie je, zámerne:

- **`lekalo inspect` zatiaľ nevie projektovať authorization policy.**
  Policy validujú, ale `inspect users.policy.public_auth_flow` odpovie
  `inspect.symbol-unknown`. Autorizačný dokument sa validuje, ale ešte
  sa nedá prezerávať po symboloch.
- **`validate --strict` už prechádza.** Prvý beh po pridaní blog modulu
  odmietol s `LEK-AUTH-009 authorization.effect-unprotected`: blog
  efekty nemali žiadne autorizačné deklarácie. Následná úprava bola
  malá: dve nové policy (`blog.policy.public_read` pre šesť query plus
  `record_view`, `blog.policy.admin_editing` pre CRUD pod
  `users.role.admin`), obnovený `model_ref` pin a plná mapping evidence
  pre `php-nativa` target. Strict dnes validuje čisto, `23 rules, 0
  errors, 0 warnings, 0 info`.
- **Upstream povrchy sú stále odložené:** error kontrakty (#62),
  slovník `users.error.*` je pripnutý cez `error_refs`, ale ešte nemá
  per-project deklaračný povrch; extended effects (#26), zamýšľaná
  event sémantika (local delivery, unordered, bez dedup, štandardná
  citlivosť, portable) je zdokumentovaná, ale nemá kanonický domov;
  strict review čaká na adapter mapping dôkazy (#27–#29); AIFHub
  provider protokol je nepublikovaný.

Toto nie sú zlyhania. Je to model, ktorý hovorí pravdu o tom, kde
projekt stojí, čo je presne na to ten nástroj.

---

Hlbší posun včera nebol počet uzlov. Bol v tom, že model prestal byť
niečo, na čo sa *pozeráme*, a stal sa niečo, čo *kontroluje*, pri
každom pushi, v CI, skôr než niečo vyjde von. Recenzent môže prehliadnuť
súbor; gate nemôže. Model je druhý pár očí, ktorý nikdy nespí, a včera
sme mu dali odznak a rozvrh.

→ [Diel 6, Lekalo deep dive](/blog/lekalo-pre-deti): kde séria šla naživo na Users modeli
→ [Diel 5, sémantické modely](/blog/semanticky-model-pre-deti): prečo modelová vrstva existuje
→ [Dokumentácia modelu na GitHub-e](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): layout, re-lock workflow, version policy

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo): repozitár projektu (product 0.1.30)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): dokumentácia sémantického modelu tohto repozitára
- `lekalo/authorization.yaml`: autorizačný kontrakt (#25), reťaz rolí a policy
- `lekalo/modules/blog/*.yaml`: sémantický model Blog modulu
