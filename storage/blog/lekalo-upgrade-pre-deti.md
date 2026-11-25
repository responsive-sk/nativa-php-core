---
title: "Rust a Lekalo pre deti ... diel 10.2: upgrade cez tri brány"
series: "Rust & Lekalo"
series-part: "10.2"
date: "2026-09-19"
version: "1.0.0"
tags: ["rust", "lekalo", "upgrade", "ci", "semanticky-model"]
excerpt: "Upgrade nástroja v0.1.31 → v0.3.2 bez zmeny jednej definície v modeli. Tri brány medzi starým a novým svetom, jedna registrácia, ktorá hovorí nie, a digest, ktorý sa nedá uhádnuť, da sa overiť."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "upgrading-lekalo"
published: true
---

V [dieli 10.1](/blog/lekalo-dizajn-v-modeli-pre-deti) model dostal piaty
modul a stál na 142 definíciách. Dnes nepribúda ani jedna. Dnes sa
hýbe zem: nástroj, ktorý model kompiluje a kontroluje, ide z verzie
0.1.31 na 0.3.2.

Znie to ako rutina `cargo install`. Nebola. Cesta viedla cez tri brány,
jednu registráciu, ktorá vždy vie prečo hovorí nie, a jeden digest,
ktorý sa nedá uhádnuť, da sa iba overiť.

## Prečo vôbec meniť verziu

Upstream vydal M3: adapter kernel, TS symbol scanner a natívne brány
pre pnpm workspaces. Naše témy žijú v TypeScripte a CSS bundli, takže
niečo také využijeme skôr či neskôr.

Pravidlo ale zostáva z dielu 10.1: najprv kontrakt, potom súkromie,
potom veci. Upgrade nástroja je zmena podmienok, za ktorých kontrakt
platí. Kým nevieš, čo nová verzia mení na wire formáte, nemen pin v CI.

## Registrácia, ktorá hovorí nie

Prvý príkaz po inštalácii padol. Klasika.

```sh
// po inštalácii v0.3.2
lekalo validate --strict
// unsupported-version error [LEK-VER-018]
// versioning.unsupported-version: The contract version is outside
// the accepted registry.
```

Nová verzia nesie vnútorný register kontraktov. Ten sa dá vypísať
jediným príkazom:

```sh
lekalo compatibility
// compatibility: model current 0.2.16 (0.2.16..0.2.16),
// ir current 0.2.16, protocol current 0.3.2
```

Náš lock hovoril `1.0.0`. Register akceptuje `0.2.16`. Žiadne varovanie,
žiadny fallback, verzia mimo registra je chyba, ktorá zastaví celý
behn. To je znak dobrého nástroja: ticho prehlásiť starý formát kompatibilným
by bol sľub, ktorý nikto nenapísal.

Ako zistiť cieľový tvar? Bisekcia analógiou z 10.1: `lekalo init`
v dočasnom adresári vygeneruje zelený projekt. Jeho `schema_version`
je odpoveď. `"0.2.16"`: a svet znovu dáva zmysel.

## Tri brány

Cesta od starého pinu k novému viedla cez tri samostatné brány. Každá
hovorí inou rečou a každá chce iný druh práce.

| Brána | Čo bolo zlé | Čo to vzalo |
|---|---|---|
| `schema_version` | 33 YAML súborov deklarovalo `1.0.0` | jeden `sed`, žiadna myšlienka |
| `authorization.yaml` | attachment reč sa zmenila: identita, profile_ref aj `model_ref` | tri riadky hlavičky |
| `lekalo.lock` | wire diskriminátor `lekalo/lock/v1.0.0` mimo registra | zmazať a dať `lekalo lock` nanovo |

Prvá brána je mechanika. Druhá je zaujímavá: `authorization.yaml` je
attachment so svojou uzavretou schémou a nová politika hovorí, že
verzia priliehava k verzii projektu. `lekalo/authorization/v1.0.0`
sa stalo `lekalo/authorization/v0.2.16`: a vnutorný pin `model_ref`
sa odkazuje na verziu modelu, nie na verziu dokumentu.

Tretia brána je tichá. Lock sa negeneruje inkrementálne, starý wire
diskriminátor zablokoval aj zápis. Zmazal som súbor a nechal nástroj,
nech si ho napíše zo sveta, ktorý vidí. Presne na to lock slúži.

## Digest, ktorý sa nedá uhádnuť

Zostáva strict profil. A ten si vedie poriadok.

```yaml
// lekalo/authorization.yaml — pin v hlavičke
model_ref:
  schema_version: "0.2.16"
  ir_digest: "sha256:…"
```

Strict porovnáva tento pin s digestom kanonických IR bajtov aktuálneho
modelu. Nesedí,`LEK-AUTH-010`, `state: stale-model`. Popis znie ako
jeden krok: spočítaj hash a vlož. Otázkou je hash čoho.

Prvá hypotéza zlyhala. Digest `0bf1d827…` z `contracts.model` v locke
je konštanta schémy, planner fixture ho má úplne rovnaký ako náš
projekt. Druhá hypotéza bola lepšia ukážka toho, prečo existujú testy:
vložila som do `observed_revision` git SHA, 40 hex znakov namiesto
64. Výsledok bol diagnostic registry invariant error, aj keď celý
registry žije vnútri binárky. Formát mení výsledok, nie len hodnotu.

Pravda sa dala prečítať v zdrojáku: `to_canonical_json()` kompilovaného
projektu. A overiť sa dala empiricky. Fixture planner deklaruje pin
`e909626a…`: ak nájdem postup, ktorý ho reprodukuje na plannerovi,
mám algoritmus a môžem ho spustiť na sebe.

```sh
// recept — overený na planner fixture aj na našom modeli
lekalo load --ir --json | jq -c '.ir' | tr -d '\n' | sha256sum
```

Kľúčový detail je `tr -d '\n'`: `jq` pridáva newline, kanonické bajty
ho nemajú. Hash bajtov s newline je hash iného sveta, a strict to vie
povedať presnejšie ako ja.

Náš pin: `540d1fff…`. Strict po vložení zelený.

## Čo upgrade zmenil

Znie to, ako by celý deň urobil `sed` a jeden hash. Takmer pravda.
Zmeny, ktoré ostanú:

| Vec | Pred | Po |
|---|---|---|
| CI pin | `--tag v0.1.31` | `--tag v0.3.2` |
| Recept na digest | python one-liner nad envelope prefixom | `jq -c '.ir' \| tr -d '\n' \| sha256sum` |
| `observed_revision` | git SHA | SHA-256 nad git SHA, podľa wire formátu |
| Definície v modeli | 142 | 142 |

Posledný riadok je ten dôležitý. Model nepopisuje starú ani novú verziu
nástroja, popisuje aplikáciu. Zmenila sa zem, nie dáta. Preto môže
upgrade vyzerať ako mechanika a predsa to celý deň trvať: všetky tri
brány idú o wire formáty, nie o význam.

## Kedy upgrade nechať byť

Úprimne: ak nepotrebuješ M3 veci, starý pin ti stále beží a kontroluje
tvoj model korektne. Upgrade stál popoludnie a pol dňa dokumentačnej
archeológie. To je cena, nie chyba.

Čas na upgrade je vtedy, keď upstream tvoju verziu vytiahne z registra,
vtedy starý pin znamená svet, ktorý nikto už nekompiluje. Alebo keď
potrebuješ feature. Nie z nudy. Nuda nie je dôvod dotýkať sa wire
formátov.

## Čo bude ďalej

Sľub z 10.1 stojí: bloky, grid, section, form, nav, footer, najprv
ako bloková matica v dokumentácii a architektonickom teste, do modelu
pridú, keď slovník skristalizuje.

Malá poznámka k teplote: bloková matica prežila dnešný upgrade bez
jedinej zmeny. Guard test čítal dokument s `1.0.0` schémou aj s
`0.2.16` schémou a v oboch svetoch zlychal presne raz, keď som mu
svojvoľne menil názov. To je ten znak kryštalizácie: slovník, ktorý
nejaký čas odoláva, je kontrakt, nie návrh.

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo): repozitár projektu (product 0.3.2, registrácia kontraktov cez `lekalo compatibility`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): dokumentácia modelu, vrátane overeného receptu na refresh `model_ref.ir_digest`
- [docs/design-blocks.md](https://github.com/evan70/nativa-minimal/blob/master/docs/design-blocks.md): bloková matica deviatich druhov, ktorá upgrade prežila nedotknutá
- [authorization.md v upstreamu](https://github.com/ichinya/lekalo/blob/v0.3.2/docs/authorization.md): uzavretá schéma, strict review a mapping evidence
- [Diel 10.1, dizajn vstúpil do modelu](/blog/lekalo-dizajn-v-modeli-pre-deti): piaty modul a prvá lekcia z gramatiky
