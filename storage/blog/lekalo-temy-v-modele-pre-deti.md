---
title: "Rust a Lekalo pre deti ... diel 9.1: témy vstúpili do modelu"
series: "Rust & Lekalo"
series-part: "9.1"
date: "2026-09-13"
version: "0.1.0"
tags: ["rust", "lekalo", "semanticky-model", "temy", "architektura"]
excerpt: "Štvrtý modul v semantickom modeli: šesť definícií pre témy, seeds-first rozsah, strict-ready od prvého commitu — a lekcia o pripnutom IR digeste, ktorú sme si vybojovali s LEK-AUTH-010. Plus vzor „gate nemôže čítať PHP, test áno“."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-themes-enter-the-model"
published: false
---

V [dieli 7](/blog/lekalo-model-rastie-pre-deti) model zdvojnásobil na 106
definícií a toolchain sa stal gate-om. Dnes model rastie znova — na
**130 definícií** — a štvrtý modul má názov, ktorý poznáte z celej série
o frontend refaktore: **témy**.

## Čo je nového v modeli

Šesť definícií v `lekalo/modules/themes/`:

| Definícia | Druh | Čo hovorí |
|---|---|---|
| `themes.variant` | enum | `starter \| magazine \| minimal \| moonshine` — whitelist, ktorý musí zrkadliť `Themes\ThemeRegistry` |
| `themes.active_state` | entita | singleton — trvalo uložená téma, riadok `theme` v config.db |
| `themes.resolve_active` | query | aktívna téma pre request, s pripnutým poradím: `?style=` → `THEME` env → nastavenie → `starter` |
| `themes.set_site_theme` | command | admin zápis cez `/mark/settings` |
| `themes.effect_site_theme_set` | efekt | zápis do config.db |
| `themes.site_theme_set` | event | „téma bola zmenená“ — payload `variant` |

K tomu dve politiky v `authorization.yaml`: čítanie aktívnej témy je
`public` (každý návštevník vidí, ktorá téma sa renderuje — aj cez
`?style=` override), zápis je `users.role.admin`. Zrkadlo blog politik
z dielu 7.

## Seeds-first: čo model zámerne NEdeklaruje

Poctivé rozlíšenie ako vždy. Model dnes **nemá**:

- **endpoint bindings** — téma nie je nová route. Je to *dimenzia*
  existujúcich endpointov (`/`, `/blog`, `/blog/{slug}` existujú bez
  ohľadu na tému); bindingy by len duplikovali to, čo už deklaruje blog
  a users,
- **scenáre** — prídu spolu s bindingmi v ďalšej zmene; scenár bez
  naviazaného endpointu by bol dekorácia.

Nula runtime zásahov — model len deklaruje to, čo PHP už vynucuje ručne.

## Lekcia: LEK-AUTH-010 a pripnutý digest

Prvý beh `lekalo validate --strict` po pridaní modulu spadol:

```
denied error [LEK-AUTH-010] authorization.mapping-stale:
Adapter mapping evidence is missing, stale, partial, or unsupported
and cannot pass strict review.
```

Diagnostika JSON envelopu povie presne prečo: `"state": "stale-model"`.
V `authorization.yaml` je `model_ref.ir_digest` — pin, ktorý hovorí
„tieto politiky boli písané presne proti tomuto modelu“. Po pridaní
modulu sa model zmenil → pin je starý → strict nemôže veriť dokumentu.

A tu je pasca: digest **nie je** sha256 surového výstupu
`lekalo load --ir`. Je to sha256 nad **kompaktným kanonickým IR JSON** —
presnými bajtmi medzi `"ir":` a zatváracou `}` v JSON obálke. Obálka
vkladá kanonické bajty doslovne, takže recept vyzerá takto:

```sh
lekalo load --ir --json | python3 -c '
import sys, hashlib
raw = sys.stdin.buffer.read().decode("utf-8")
prefix = "{\"status\":\"valid\",\"modelVersion\":\"1.0.0\",\"ir\":"
body = raw[len(prefix):].rstrip("\n")
print("sha256:" + hashlib.sha256(body[:-1].encode()).hexdigest())'
```

Najdôležitejší krok: **overiť recept proti známemu dobrému pinu** predtým,
ako uveríte čerstvej hodnote. Spustili sme ho na committed modeli,
porovnali s pripnutým `c0eaab…` — presná zhoda — a až potom vymenili
digest za nový `e9df0e…`. Verifikovaný nástroj, nie viera.

Zaujímavosť: `lekalo lock` sa **nezmenil**. Zamraze resolution identity
(verzie, adaptery, kontrakty), nie obsah modelu — `lock --check` zostal
zelený aj s novým modulom.

## Vzor: „gate nemôže čítať PHP, test áno“

Enum `themes.variant` je ručne písané zrkadlo runtime whitelistu. Lekalo
validuje model izolovane — nikdy nemôže importovať PHP symboly. Ako
teda zabránime tomu, aby sa enum a `Themes\ThemeRegistry` rozišli?

Nový test: `tests/Integration/ThemesModelParityTest.php`.

- parsuje `lekalo/modules/themes/entities.yaml` (je to kanonický JSON —
  platná podmnožina YAML, takže stačí `json_decode`, nula závislostí),
- vytiahne hodnoty `themes.variant` enumu,
- assertne **set-rovnosť** s `Themes\ThemeRegistry::all()`.

Nová téma bez zápisu do modelu → test spadne v CI. Model bez runtime →
tiež. Gate nemôže čítať PHP; test áno. Hodujeme ho na každé
miesto, kde sa model a runtime dotýkajú ručne.

## Živý výstup

```
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
validated strict profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
lock checked sha256:bc4dbad9… resolver 1.0.0
```

130 definícií (30 scalar, 18 command, 18 effect, 15 endpoint, 11 query,
9 entity, 9 policy, 6 event, 6 scenario, 4 enum, 2 value-object,
2 target-binding) v štyroch moduloch: users, blog, comments, **themes**.

## Prebudte sa

- Model teraz pokrýva aj frontend povrch — nie len dáta a API.
- Pripnutý digest je živá vec: každá zmena modelu = povinný refresh pinu.
  Recept je zdokumentovaný v `docs/lekalo-model.md`.
- Vzor parity-testu je všeobecný: čokoľvek, čo gate nemôže vidieť,
  stráži test.

Ďalší diel: endpoint bindings a scenáre pre témy — keď sa „rozmer“
endpointov stane plnohodnotnou časťou kontraktu.

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo) — repozitár projektu (product 0.1.31, autorizácia #25)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — dokumentácia modelu, vrátane receptu na refresh `model_ref.ir_digest`
- `lekalo/modules/themes/*.yaml` — sémantický model Themes modulu (6 definícií)
- `lekalo/authorization.yaml` — `themes.policy.public_read` a `themes.policy.admin_editing`
- [Diel 7 — model rastie](/blog/lekalo-model-rastie-pre-deti) — autorizácia, blog a gates na každom pushi
