> ARCHIVED 2026-10-14 — implemented (griffe + mono bundles frozen,
> `GriffeBuildFreezeTest`/`MonoBuildFreezeTest` green). Migrated verbatim
> from `.ai-factory/plans/feature-theme-asset-freeze.md`; do not edit,
> history only.

# Zmrazené theme assety — kompilácia CSS/TS, freeze a injekcia do témy (pilot: Griffe)

- **Vetva:** `feature/theme-asset-freeze` (z `master`, 2026-10-12)
- **Režim:** full / pilot na jednej téme, follow-up fáza pre zvyšok
- **Pilot:** Griffe (`griffe` bundle je už deklarovaný v `GriffeTheme::bundle()/jsKey()`, ale balíček ani build neexistujú — najčistejší dôkaz postupu)
- **Stav:** plán, neimplementované

## Original Request

ok potrebujeme plan ako skompilovat a zmrazit css/ts assets a ijektnut ich do temy maximalne skomprimovane a ciste

## Settings

- **Testing:** áno — parity/freeze testy, manifest asercie, live render check
- **Logging:** verbose — implementácia loguje každý krok buildu, rozhodnutia o portoch a merania veľkostí (do progress notes, nie do produkčného JS)
- **Docs:** áno — povinný docs checkpoint na konci (`docs/` + komentáre v balíčku)

## Východiská z prieskumu (pre implementátora)

1. Pipeline už existuje a pilot ho len použije: `Frontend/packages/<téma>/` → `createThemeConfig()` z `Frontend/packages/build/vite-theme-factory.ts` (target `es2015`, Chrome 70 baseline, `manifest.json`, `outDir public/dist/<téma>/`, `codeSplitting: false` pri jednom entry, `stripAtLayerPlugin`) → PHP `AssetManager` číta `public/dist/<bundle>/manifest.json` → `FrontendLayout::styleTag()` inlinuje **buildnuté** CSS do `<style>`, `::jsTag()` pridá **jeden** `defer` script. Bez manifestu layout padá na `criticalCss()` súbory — ten fallback ostáva.
2. `AssetManager::knownBundles()` sa odvodzuje z `ThemeRegistry::bundles()` (pýta sa témy na `bundle()`), takže `griffe` už dnes resolvuje — **netreba meniť žiadny PHP register**. Chýba len balíček + build.
3. Dnes Griffe inlinuje 4 skripty ručne v `GriffeTheme::pageWrap()` cez `InlineJs` s per-surface gatingom (`gr-header.js` vždy; `gr-slider.js` / `gr-reveal.js` / `gr-code-highlight.js` podľa obsahu). Baseline: `griffe.css` 13 594 B; JS 1 156 + 3 804 + 1 378 + 12 300 B.
4. `/public/dist/` je v `.gitignore` („produced by a script, never committed"). Freeze = **vedomá výnimka pre pilot**: negácia pre `public/dist/griffe/` + dôvod v komentári. `make assets` ostáva jediná cesta buildu; `make assets-import` a `build-dist.sh --skip-assets` sa nemenia.
5. `pnpm 12.4.1` + `node v24` sú na stroji; `public/dist/` už obsahuje buildy ostatných balíčkov (basic, erik, jednotka, magazine, minimal, moonshine, starter) — griffe/mono chýbajú.
6. Referenčné testy zo showcase stromu tu **nie sú** (`CriticalCssByteParityTest`, `FrontendPackageParityTest`, `BuildCssAuditTest`, `bin/build/verify-build.php`) — freeze stráž sa píše nanovo ako `GriffeBuildFreezeTest`, nie kopíruje.
7. Poradie vykonania sa mení čestne: dnes skripty bežia inline na konci `<main>` počas parsovania; po novom jeden `defer` bundle pred `DOMContentLoaded`. Všetky 4 inity pracujú nad hotovým DOM (slider/autoplay, reveal observer, header scroll, highlight) — ekvivalent, overí live test. Každý init musí byť **self-gating** (návrat pri chýbajúcom selektore), čím single entry nahradí dnešný per-surface gating bez viacnásobných requestov.
8. `gr-code-highlight.js` (12 300 B) je takmer určite duplikát kanonického `code-highlight.ts` (erik/starter balíčky) — fáza 2 rozhodne: parametrizovať kanonický prefixom `gr-`, alebo pinovať duplikát s dôvodom.

## Fáza 0 — Prerekvizity a baseline

- [x] **0.1** `cd Frontend/packages && pnpm install --frozen-lockfile && pnpm build` prejde pre existujúce balíčky (dôkaz, že toolchain na tomto stroji stavia; logovať verzie pnpm/node/vite). HOTOVO 2026-10-12: pnpm 12.4.1 / node v24 / vite 8.2.1, 8 projektov, BUILD_EXIT:0.
- [x] **0.2** Zmerať a zapísať baseline do plánu (HOTOVO 2026-10-12, theme=griffe, bez buildu): bajty `modules/Griffe/assets/*`, počet inline `<script>` blokov + bajtov na griffe homepage / článku / indexe (curl z dev servera), veľkosť `<style>` bloku. Bez týchto čísel sa „maximálne skomprimované" nedá overiť.

Baseline (dev server, prázdna page cache): `/` total=28186 style=11472 inline=5 src=0; `/blog` total=19616 style=11472 inline=3 src=0; fenced článok total=43722 style=11472 inline=5 src=0. Zdrojové súbory: griffe.css 13594 B; gr-header 1156 + gr-slider 3804 + gr-reveal 1378 + gr-code-highlight 12300 B.

## Fáza 1 — Nový balíček `Frontend/packages/griffe/`

- [x] **1.1** Kostru podľa `minimal`/`erik` balíčka (HOTOVO 2026-10-12 — package.json, tsconfig, vite.config, THEME_PORTS 5184/4184, `build:griffe` script): `package.json` (`@nativa/theme-griffe`, scripts `dev/build/preview/typecheck`), `tsconfig.json` (`extends: ../tsconfig.base.json`, `include: [src]`), `vite.config.ts` (jeden entry `{ griffe: 'src/griffe.ts' }`).
- [x] **1.2** `THEME_PORTS` vo `vite-theme-factory.ts` + voľný dev/preview port pre griffe (HOTOVO 2026-10-13 — `griffe: { dev: 5184, preview: 4184 }` vo factory, `build:griffe` vo workspace `package.json`; overené grepom).
- [x] **1.3** `src/griffe.ts` entry (HOTOVO — 4 self-gating inity, poradie header/slider/reveal/highlight): import `./griffe/index.css` + 4 inity (header, slider, reveal, highlight), každý self-gating; `DOMContentLoaded` idiom ako v `erik.ts`.
- [x] **1.4** Port `assets/scripts/*.js` → `src/*.ts` pod prísnym `tsc --noEmit` (HOTOVO — TSC_EXIT:0; `keydown` listener si vyžiadal `HTMLElement` cast, ElementEventMap ho nemá): žiadne `?.`/`??` (es2015 výstup to garantuje, zdroj píš dobovo), žiadne nové závislosti, žiadny framework.
- [x] **1.5** Rozdeliť `griffe.css` (13,6 KB) na `src/griffe/*.css` (HOTOVO — tokens/header/hero-slider/reveal/cases/blog/footer + index.css, poradie = kaskáda): (tokens, shell/header-footer, hero-slider, case-grid, article/highlight `tok-*`, forms) + `index.css` s importami v poradí = dnešná kaskáda. **Kaskáda sa nesmie zmeniť** — overí vizuálny diff vo fáze 4.
- [x] **1.6** `pnpm --filter @nativa/theme-griffe run build` + `typecheck` zelené (HOTOVO — JS 10798 B / CSS 9261 B): manifest obsahuje resolvovateľný entry (`griffe` alebo `src/griffe.ts` — obe formy kryje `AssetManager::manifestEntry()`).

## Fáza 2 — Maximálna kompresia a čistota

- [x] **2.1** Overiť výstup (HOTOVO — 1×.js + 1×.css, žiadne sourcemapy, žiadny `import`/`export`, `.gr-header` 9× / `--live` 4× / `-armed` 3× / `.tok-keyword` v CSS): minifikovaný jeden `.js` + jeden `.css` na entry, **žiadne sourcemapy**, **žiadny `import`/`export` v bundli** (Chrome 70 kontrakt — single-file, `defer` bez `type=module`), `@layer` preč (factory plugin).
- [x] **2.2** Highlighter rozhodnutie (HOTOVO — mechanický port: rule tables duplikované podľa repo konvencie, badge + idempotentné tlačidlá ostávajú, lebo kanonický init ich nemá): diff `gr-code-highlight.js` vs kanonický `code-highlight.ts`; preferencia: jedna parametrizovaná kópia. Výsledok zapísať s dôvodom.
- [x] **2.3** Class-coverage audit (HOTOVO — ako `theBuiltStylesheetCoversEveryFallbackClass` vo freeze teste + tok-paleta; presunuté z GriffeThemeTest, lebo porovnáva build): každá `gr-*` trieda emitovaná `GriffeTheme` má pravidlo v built CSS (rozšíriť existujúci test v `GriffeThemeTest`, nie nový súbor).
- [x] **2.4** Stanoviť a zapísať size budget (HOTOVO — JS ≤ 14000 B, CSS ≤ 12000 B raw, ~30% nad freezing buildom; asercia vo freeze teste): (JS gzip + CSS gzip vs baseline z 0.2) — budget sa stane aserciou vo freeze teste.

## Fáza 3 — Freeze (zmrazenie buildu)

- [x] **3.1** `.gitignore`: negácia pre pilot (HOTOVO — `/public/dist/*` + `!/public/dist/griffe/`; holý adresár by negáciu pohltil, overené cez `git check-ignore`): (`/public/dist/griffe/` — manifest + hashované súbory), s komentárom prečo je výnimka (reprodukovateľný deploy bez Node; `make assets` ostáva jediný zápis).
- [x] **3.2** Commitnúť `public/dist/griffe/` (HOTOVO 2026-10-13 — `327942a feat(griffe): freeze the griffe build in git`; `git ls-files` ukazuje manifest + `.js` + `.css` trackované).
- [x] **3.3** Nový `modules/Griffe/tests/GriffeBuildFreezeTest.php` (HOTOVO — 6 testov / 29 asercií, zelené): manifest v gite existuje; `AssetManager('griffe')` resolvuje `jsUrl('griffe')` + `cssUrls('griffe')` a súbory existujú; built CSS obsahuje kľúčové selektory (`.gr-header`, `.gr-hero-slider`, `.tok-`); built JS neobsahuje `import`; veľkosti držia budget z 2.4.
- [x] **3.4** CI: asercia griffe entry v manifeste (HOTOVO — krok `Assert the frozen griffe manifest`, YAML valid): (vzoru moonshine kontrol v `.github/workflows/ci.yml`).

## Fáza 4 — Injekcia do témy a overenie

- [x] **4.1** `GriffeTheme::pageWrap()`: zmazať per-surface `inlineScript()` gating (HOTOVO — holý `<main>`, helper + import zmazané): (header/slider/reveal/highlight) — JS po novom dodá `FrontendLayout::jsTag()` z manifestu. `criticalCss()` **ostáva** (fallback kontrakt layoutu + `ThemeAssetCompletenessTest`).
- [x] **4.2** Zmazať `modules/Griffe/assets/scripts/*.js` (HOTOVO — 4 súbory preč, `griffe.css` fallback ostáva): (zdroj sa presťahoval do `Frontend/`) — `griffe.css` fallback ostáva + test, že built CSS je jeho nadmnožinou (selektory fallbacku existujú v builde).
- [x] **4.3** Sync slice (HOTOVO — `diff -r` čistý): `cp -r modules/Griffe/. src/Modules/Griffe/` + `diff -r` čistý.
- [x] **4.4** `GriffeThemeTest` (HOTOVO — `pageWrapShipsNoInlineScripts` + `renderedDocumentDefersTheFrozenBundle`, gating testy nahradené, lebo mechanizmus sa zmenil; 23 testov zelených): (defer) a inlinované built CSS; bez manifestu fallback bezo zmeny (dočasne premenovaním dist v teste).
- [x] **4.5** Brány (HOTOVO — 106 testov exit 0, phpstan/cs/rector exit 0):
- [x] **4.6** Live Chromium verify pod `theme=griffe` (HOTOVO — defer bundle na všetkých povrchoch, zvyšné 2 inline skripty sú layout-level init+toggle, `gr-slider--live` + `gr-reveal-armed` v DOM, 2 copy-btn + badge + tok-spans na článku; `/` 28186→18830 B, článok 43722→26674 B): slider (autoplay + šípky + paginácia), reveal, header hide-on-scroll, highlight na článku — všetko z bundlu, **žiadny** inline per-surface `<script>` v `<main>`, jeden `<style>` s built CSS.
- [x] **4.7** Freeze dôkaz (HOTOVO — rebuild byte-identický, `git status public/dist/griffe/` prázdny): (byte-identický dist).

## Fáza 5 — Follow-up (mimo pilotu, len náčrt)

- [ ] **5.1** Erik: balíček existuje — doplniť homepage CSS (`er-*` z `modules/Erik/assets/critical/erik.css`) do `Frontend/packages/erik/src/`, re-freeze `public/dist/erik/`, rovnaké testy.
- [ ] **5.2** Mono: nový balíček `Frontend/packages/mono/` (30,6 KB CSS + `mo-variants.css` + 3 skripty; pozor na `mo-variant-switcher` + theme-toggle interakciu), freeze + injekcia.
- [ ] **5.3** Po všetkých troch: vyhodnotiť, či `.gitignore` výnimka zovšeobecní na `/public/dist/*` (s pravidlom „dist sa edituje len rebuildom").

## Commit Plan

- Commit 1: fáza 0 + 1 (balíček + porty, bez PHP zmien) — `feat(griffe): build griffe theme assets with Vite`
- Commit 2: fáza 2 (kompresia, highlighter, budget) — `refactor(griffe): minimize and clean the griffe bundle`
- Commit 3: fáza 3 (freeze dist + test + CI) — `feat(griffe): freeze the griffe build in git`
- Commit 4: fáza 4 (injekcia, mazu scriptov, testy, docs) — `feat(griffe): inject the frozen bundle into GriffeTheme`

## Riziká

- `.gitignore` doktrína „dist sa nikdy necommituje" dostáva prvú výnimku — je vedomá, úzka (len `dist/griffe/`) a zdokumentovaná; fáza 5.3 ju prehodnotí.
- `pnpm install --frozen-lockfile` môže na CI chcieť iné lock-verzie — fáza 0 to odhalí skôr, než sa čokoľvek zmaže.
- Single entry posiela slider JS aj na stránky bez slidera (~3,8 KB raw) — daň za jeden request a Chrome-70 kontrakt; self-gating inity ju robia neškodnou.
