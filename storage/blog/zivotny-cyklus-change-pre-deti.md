---
title: "OpenSpec pre deti ... diel 3: životný cyklus change ... od nápadu po archív"
date: "2026-08-09"
version: "1.0.2"
tags: ["openspec", "php", "workflow"]
excerpt: "Change nie je súbor, je to cesta. Prejdeme celý životný cyklus ... od nápadu cez prieskum, dizajn, implementáciu a quality gates až po archív ... na čerstvom live príklade fázy 2."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "lifecycle-of-a-change"
published: true
---

> Toto je diel 3 série **OpenSpec pre deti**. V [dieli 1](/blog/co-je-openspec-pre-deti)
> sme si povedali, čo je change a prečo sa nepíše priamo do spec. V [dieli 2](/blog/anatomia-change-pre-deti)
> sme rozobrali päť súborov archivovaného change. Dnes to celé ožijeme: vezmeme
> **čerstvý change, ktorý práve prechádza celým workflow**, a ukážeme si jeho
> cestu od nápadu až po archív ... krok za krokom, v reálnom čase.

## Change nie je súbor, je to cesta

Change v OpenSpec žije. Narodí sa, rastie, starne a nakoniec odíde do archívu. Ale čo sa deje medzi narodením a archiváciou?

V dieli 2 sme otvorili change ako **fotku** ... päť súborov ležiacich v archíve.
Ale keď sa pozrieš na fotku, nevidíš, ako vznikla. Kto ju fotil? Z akej strany?
Čo sa stalo medzi prvým a druhým záberom? Životný cyklus change je presne to „medzi“. A fáza 2 tohto projektu ...
`2026-08-09-author-profile` ... je príklad, ktorý ukazuje, pretože **prechádza týmto
workflow práve teraz**, kým píšem tento článok. Autorská stránka
`/authors/{id}`, avatar na autorovom riadku a verejné servírovanie uploadov.
Poďme sa pozrieť na jej cestu.

## Fáza 0: Nápad ... ešte nič neexistuje

Každý change začína ako požiadavka v rozhovore. Žiadny súbor, žiadny
checklist. V tomto prípade to bola veta:

> „Priprav design fázy 2: autorská stránka `/authors/{id}` + avatar na
> autorovom riadku (stĺpec `avatar` v users už existuje).“

Znie to. Ale pozor ... v tejto fáze ešte **nevieme nič**. Je avatar
stĺpec naozaj nepoužitý? Dá sa k nemu vôbec dostať? Čo všetko to rozbije?
Nápad je len palivo ... cesta začína prieskumom.

## Fáza 1: Prieskum ... dizajn z faktov, nie z predstáv

Pred napísaním prvého riadku design.md sa overili fakty priamo v kóde. Toto je
fáza, ktorú na fotke nevidíš, ale je najdôležitejšia:

| Overený fakt | Čo znamenal pre dizajn |
|---|---|
| `users.avatar VARCHAR(255)` existuje v schéme, ale **žiadny kód ho nečíta ani nezapisuje** | Dá sa použiť bez migrácie ... stĺpec už tam je |
| `LocalStorageProvider` je postavený a hardenovaný (MIME whitelist, 10 MB, náhodné hex názvy) ... ale **nikde sa nepoužíva** | Upload infra existuje, len ju treba zapojiť |
| **Nič neservíruje `storage/uploads/`** ... dev router aj produkcia servírujú len `public/` | Bez nového routu by avatary nikdy neboli dostupné ... najväčší objav prieskumu |
| Router podporuje `{path*}` catch-all | `GET /storage/uploads/{path*}` sa dá vyjadriť cez `#[Route]` |
| CSP `img-src` už povoľuje `'self' data: blob:` | Self-hosted avatary aj placeholder fungujú bez zmeny CSP |

Toto je rozdiel medzi dizajnom z predstáv („asi to pôjde cez uploads“) a
dizajnom z faktov („vieme, že nič to neservíruje, preto pridávame route“).
Všetkých päť faktov je zapísaných v design.md ... preto im o rok bude niekto
veriť.

## Fáza 2: Päť súborov ... change sa narodil

Po prieskume vznikol balíček `openspec/changes/2026-08-09-author-profile/` ...
presne tých päť súborov z dielu 2, v rovnakom poradí otázok:

```text
openspec/changes/2026-08-09-author-profile/
├── .openspec.yaml     # ČO to je? ... „author profile pages + avatars"
├── proposal.md        # PREČO? ... aj Non-goals: žiadny bio, žiadne sociálne
│                      # odkazy, žiadne verejné uploady, žiadny cloudinary
├── design.md          # AKO? ... 5 častí: profile, avatar, autor stránka,
│                      # uploads serving, admin upload
├── tasks.md           # ČO KONKRÉTNE? ... checklist implementácie a testov
└── specs/starter-kit/ # AKO OVERÍME? ... 4 nové požiadavky s GIVEN/WHEN/THEN
    └── spec.md
```

Všimnite si, že **spec delta pribudla hneď na začiatku**, nie na konci. Skôr,
než vznikol prvý riadok kódu, už bolo napísané, ako sa to overí. To je celý
zmysel OpenSpec: najprv vieme, čo znamená „hotovo“, až potom to robíme.

## Fáza 3: Implementácia ... tasks.md ako checklist

Potom prišiel kód. tasks.md nie je zoznam želaní ... je to **overiteľný
checklist**, kde každá položka má presnú cestu k súboru:

```text
## Implementation
- [x] BlogArticlesService (nový) ... zdieľaný scan článkov + byAuthorProfileId
- [x] AuthorProfileAction (nový) ... GET /authors/{id}
- [x] ServeUploadedFileAction (nový) ... GET /storage/uploads/{path*}
- [x] LocalStorageProvider::isAllowed() ... whitelist pre servírovací route
- [x] UsersAction ... avatar upload + remove v admin formulári
- [x] UserRepository/User ... perzistencia users.avatar

## Tests ... traversal, dotfiles, whitelist, MIME
- [x] AuthorProfileTest ... 200 pre aktívneho, 404 pre neznámeho/neaktívneho
- [x] … a ďalšie
```

Odškrtnuté = hotové. Ale pozor ... checklist potvrdzuje, že sa **niečo urobilo**,
nie že to **funguje**. Na to je ďalšia fáza.

## Fáza 4: Quality gates ... to isté, čo beží v CI

Každá zmena v tomto repo prechádza rovnakými bránami, aké bežia v GitHub
Actions. Nič zvláštne pre tento change ... **vždy**:

```text
vendor/bin/phpunit      # 188 → 192 → 194 testov (unit + integračné + CSS + architektúra)
composer phpstan        # level 9, 0 chýb
composer cs-fix-dry     # 0 porušení štýlu
composer rector-dry     # 0 navrhovaných zmien
pnpm run build          # Vite build + bin/verify-build.php (staré prehliadače)
```

PHPStan level 9 je nekompromisný ... keď hlási `mixed` offset alebo metódu na
nullable objekte, change nejde ďalej, kým to nie je opravené. Testy sa počítajú:
fáza 2 pridala 6 nových testov a dohromady s revíziami ich je 194.

## Fáza 5: Review ... kód sa píše viackrát, než je dokonalý

A teraz prichádza časť, ktorú na fotke nikdy nevidíš: **kritická revízia**.
Change sa neodovzdá, len preto že testy prešli. Dizajn aj implementácia sa
kontrolujú s otázkou: „Kde to môže zlyhať?“

A fáza 2 mala čo ukázať: **Prvý review pass** našiel, že design protirečí ...
testing sekcia tvrdila
„`.txt` → 404“, ale whitelist obsahuje `txt`/`md`/`pdf`. Takéto rozpory sa
riešia rozhodnutím: zachováme celý whitelist (txt/md/pdf sa servírujú,
ochránené globálnym `nosniff`) a opravíme dizajn.

Zároveň pribudli **e2e testy
cez Kernel** ... pretože priame volanie akcie netestuje dekódovanie URL
(`%2e%2e%2f` → `../`), ktoré sa deje pred routingom. Dvojitý encoding
(`%252e%252e%252f`) prežije lexikálny guard ... a až realpath containment ho
zastaví. To sa v kóde overí, nie odhadne.

**Druhý review pass** našiel tri ďalšie veci a každá sa opravila:

| Nález | Oprava |
|---|---|
| Autorský grid sa pároval cez raw email, stránka je adresovaná id | `byAuthorProfileId()` ... grid kľúčovaný resolved identitou; email prestal opúšťať resolver |
| Nonce substitúcia bežala aj na binárnych odpovediach (drahý scan + korupcia txt/md s placeholderom) | `SecurityHeadersMiddleware` nahrádza placeholder len v `text/html` ... súbory prechádzajú byte-identical |
| Admin preview avatara nekontroloval, či súbor existuje | `exists()` fallback na placeholder ... žiadny broken image |

Toto je najdôležitejšia lekcia dielu: **change nie je dokonalý, keď je
napísaný ... je dokonalý, keď prežije review.** Každý review našiel reálne
problémy, ktoré testy nechytili. Testy overujú, že kód robí to, čo má. Review
overuje, že to, čo má robiť, je správne.

## Fáza 6: Archív ... história rozhodnutí

Posledná fáza je v tomto prípade **ešte pred nami**. Keď budú všetky gates
zelené a change odsúhlasený:

1. Celý balíček sa presunie do `openspec/changes/archive/2026-08-09-author-profile/`
2. Jeho štyri požiadavky sa zlúčia do canonical spec (`openspec/specs/starter-kit/spec.md`)
3. Vznikne commit a change sa stane súčasťou histórie projektu

Od tohto momentu je change **uzavretý** ... nikto ho už nebude meniť, ale každý
si môže prečítať, prečo vznikol, ako bol navrhnutý a čo sa pri ňom rozhodlo.
Presne to sme robili v dieli 2 s jeho predchodcom.

## Live status: kde je author-profile práve teraz?

A tu je meta-vrstva. Tento článok píšem **uprostred fázy 5**. Change má
hotový prieskum, dizajn, implementáciu aj všetky gates ... 194 testov, PHPStan
čistý. Práve prešiel dvoma review passmi a jeho posledné opravy (identity-keyed
grid, HTML-only nonce, preview fallback) sa overujú. Archív a canonical spec
sync naňho ešte čakajú.

To znamená, že keď si toto čítaš, môže byť change už archivovaný ... alebo stále
na ceste. Oboje je správne. **Workflow nie je o tom, že veci sú hotové. Je o
tom, že v každom momente vieme, v akej fáze sú a čo musia splniť, aby postúpili
ďalej.**

## Prečo to celé robíme

Životný cyklus znie ako veľa krokov na jednu autorskú stránku. Ale každý krok
má svojho zákazníka:

| Fáza | Chráni pred |
|---|---|
| Prieskum | Stavbou na neexistujúcom základe („veď uploads už fungujú" ... nefungujú) |
| Spec delta | Zmenou, ktorá sa nedá overiť ... „hotovo" bez definície |
| Quality gates | Regresiou ... rozbitím niečoho, čo fungovalo |
| Review | Chybou v návrhu, ktorú testy nevidia |
| Archív | Zabudnutím ... prečo sme sa rozhodli tak, ako sme sa rozhodli |

V [dieli 4](/blog/spec-ako-zdroj-pravdy-pre-deti) si ukážeme, ako sa spec,
kód, testy a QA navzájom kontrolujú ... a prečo je spec zdrojom pravdy, nie
len ďalším dokumentom. Dočítame sa!


→ [OpenSpec pre deti ... diel 1](/blog/co-je-openspec-pre-deti): čo je OpenSpec
→ [Anatómia change](/blog/anatomia-change-pre-deti): päť súborov, päť otázok
→ [Spec ako zdroj pravdy](/blog/spec-ako-zdroj-pravdy-pre-deti): štyri vrstvy, jeden príbeh

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
