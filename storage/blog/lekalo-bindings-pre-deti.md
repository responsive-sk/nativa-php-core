---
title: "Rust a Lekalo pre deti ... diel 9.2: témy dostali routy"
series: "Rust & Lekalo"
series-part: "9.2"
date: "2026-09-14"
version: "0.1.1"
tags: ["rust", "lekalo", "semanticky-model", "temy", "architektura"]
excerpt: "Model rastie na 135 definícií: témy dostali endpoint bindingy a scenáre. Dva reprezentatívne bindingy namiesto šiestich povrchov, route-parity test ako rozšírenie vzoru „gate nemôže čítať PHP, test áno“, a gramatika, ktorá nepozná koreňovú cestu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-themes-get-routes"
published: false
---

V [dieli 8](/blog/lekalo-temy-v-modele-pre-deti) sa témy stali štvrtým
modulom modelu, enum, entita, query, command. Dnes dostávajú druhú
polovicu slovenského slova „zastúpenie“: **bindingy a scenáre**. Model
rastie z 130 na **135 definícií**.

## Čo je nového

`lekalo/modules/themes/` má dva nové súbory:

| Definícia | Druh | Čo hovorí |
|---|---|---|
| `themes.endpoint_blog_index` | endpoint | `GET /blog` → `themes.resolve_active` |
| `themes.endpoint_settings_switch` | endpoint | `POST /mark/settings/site` → `themes.set_site_theme` |
| `themes.binding_php` | target-binding | modul ↔ `php-nativa` (parity s blogom) |
| `themes.read_flow` | scenario | návštevník načíta stránku, resolver vyberie tému |
| `themes.admin_switch_flow` | scenario | admin pošle formulár, téma sa perzistuje |

Themes sú tak prvý modul po blogu s kompletným slovníkom: endpointy,
target-binding aj scenáre.

## Dva bindingy namiesto šiestich: a prečo

Téma je *rozmer* existujúcich endpointov: `/blog`, `/blog/{slug}`,
`/authors/{slug}`, tagy, kategórie, homepage, všetko sa renderuje cez
aktívnu tému. Nabindovať všetkých šesť by znamenalo, že každá budúca
zmena route je povinná úprava modelu, za nulovú pridanú hodnotu
kontraktu.

Preto **reprezentatívna dvojica**: `GET /blog` (čítanie, ktoré prechádza
resolverom) a `POST /mark/settings/site` (zápis,`SettingsAction::saveSite`
perzistuje validovaný kľúč `theme`). Tie dva nesú celý fakt: „téma je
rozmer, nie nová route.“

## Route-parity: vzor naďalej rastie

V dieli 8 vzor znel: *gate nemôže čítať PHP, test áno*, na enum. Dnes
rovnaký vzor na **routy**:

`tests/Integration/ThemesBindingParityTest.php` (4 testy, 46 assertions):

- parsuje `bindings.yaml` (`json_decode`: kanonický JSON, nula
  závislostí),
- pre každý `kind: endpoint` nájde runtime `#[Route]` deklarácie
  (regex nad action triedami, tá istá technika discovery, ktorú používa
  `Themes\ThemeRegistry`),
- assertne **method+path zhodu** každom bindingu.

Premenuješ route bez modelu → test spadne. Zmeníš model bez runtime →
tiež. A test zároveň pinuje *čo* bindingy volajú: `/blog` musí volať
`themes.resolve_active`, settings POST musí volať
`themes.set_site_theme`.

## Gramatika: koreňová cesta neexistuje

Poctivý nález z implementácie: endpoint-path gramatika Modelu 1.0.0 je
`^(/[a-z0-9:_{}-]+)+$`: segmenty musia byť **neprázdne**. Koreňová
cesta `/` (homepage, rovnako theme-aware) sa v modeli vyjadriť nedá.
Validátor to povedal presne:

```
invalid error [LEK-IR-006] ir.value-invalid
lekalo/modules/themes/bindings.yaml:11:15:
A value violates its grammar or rule.
```

Prvé, čo som napísal, bolo `path: "/"`: a CLI to odmietol. Riešenie:
homepage reprezentuje `GET /blog` binding (validná gramatika, rovnaký
fakt), gramatický limit je zdokumentovaný v `docs/lekalo-model.md` na
prepadnutie, keď upstream grammar rozšíri.

## Digest recept druhýkrát

Pridanie bindingov zmenilo IR → `LEK-AUTH-010` znova. Recept z dielu 8
funguje presne tak, ako je zdokumentovaný: najprv overenie proti
známemu pinu na HEAD (`e9df0e…`: presná zhoda), potom nový digest
`23bdb373…`. Druhé overenie receptu = už nie je náhoda, je to postup.
A `lekalo.lock` opäť stál, zamraze resolution identity, nie obsah.

## Živý výstup

```
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
validated strict profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
lock checked sha256:bc4dbad9… resolver 1.0.0
composer test: OK (919 tests, 3048 assertions)
```

135 definícií (17 endpoint, 8 scenario, 3 target-binding, z toho
themes: 11 definícií, 2 endpointy, 2 scenáre, 1 target-binding) v štyroch
moduloch.

## Prebudte sa

- Reprezentatívny subset je legitímny modeling, bindovať všetko je
  slepá poslušnosť, nie kontrakt.
- Paritný vzor rastie s modelom: enum → routy. Každé miesto, kde sa
  model a runtime dotýkajú ručne, dostane test.
- Gramatiky majú slepé škvurny; poctivý model ich dokumentuje, namiesto
  toho, aby ich obchádzal.

Ďalší diel: keď témy dostanú vlastné administrátorské UI (theme preview,
per-locale varianty), model bude čakať pripravený.

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo): repozitár projektu (product 0.1.31, endpoint gramatika v `ir/grammar.rs`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): dokumentácia modelu vrátane bindings sekcie a gramatického limitu
- `lekalo/modules/themes/bindings.yaml` + `scenarios.yaml`: 2 endpointy, target-binding, 2 scenáre
- `tests/Integration/ThemesBindingParityTest.php`: route-parity strážca (4 testy, 46 assertions)
- [Diel 8, témy v modeli](/blog/lekalo-temy-v-modele-pre-deti): enum, entita, query, command a digest lekcia
