> ARCHIVED 2026-10-14 — implemented (`DiscoveryCache`, `ThemePreference` +
> `PreferenceVote`, contention logging; `DiscoveryCacheTest` +
> `PreferenceContentionTest` green). Migrated verbatim from
> `.ai-factory/plans/rfc-discovery-cache-preference.md`; do not edit,
> history only.

# RFC: DiscoveryCache + PreferenceRegistry (Marko core → nativa-php-core)

Stav: návrh. Zdroje: `../marko/packages/core/src/Discovery/DiscoveryCache.php`
(CACHE_VERSION 4, `var_export` PHP-return súbor, atomický write cez tmp+rename,
trust-checky, verzia + fingerprint validácia),
`Discovery/DiscoveryFingerprint.php` (xxh128 nad `vendor/composer/installed.json`
+ `composer.json` v `modules/` rekurzívne a `app/` jedna úroveň),
`Container/PreferenceRegistry.php` (priority vendor 0 < modules 1 < app 2,
konflikt pri rovnakej priorite = výnimka, reťazce + detekcia cyklu),
`Container/PreferenceDiscovery.php` (`#[Preference(replaces: X)]`,
pre-filter `str_contains($contents, '#[Preference')` + `ClassFileParser` +
reflexia), `Discovery/DiscoveryCompiler.php` (jeden pass, contributor sekcie).

## A. Čo sa nesmie zmeniť

1. **Text-first scan** (`ModuleManifest`): katalóg sa nikdy neloaduje ako trieda.
   Stale-cache nesmie znamenať fatal na chýbajúcu triedu — popis chýbajúceho
   musí zostať možný (dnešná vlastnosť, Marko ju nemá: ich discovery classy
   loaduje + reflektuje).
2. **`module:add/remove` round-trip s identickým hashom** (`docs/modules.md`):
   inštalácia/odobratie + reštart servera, strom bajtovo identický.
   Cache je odvodenina stromu, nie jeho súčasť — patrí do `storage/cache/`
   (gitignored), nikdy do `src/` ani `modules/`.
3. **`bin/cms` beží bez `vendor/`**: invalidácia aj rebuild musia byť plain PHP
   bez composer-autoloadera (dnešný `src/init.php` fallback ostáva).
4. **Odstránenie nikdy nemazľe dáta**: cache-súbor nie sú dáta, mazať ho smie
   len `module:add/remove` a explicitný príkaz — nikdy potichu pri migrácii.
5. **Chybový režim je hlasný, nie tichý**: poškodená/stará cache = zahodiť
   a preskenovať + log, nikdy 500 ani tiché servírovanie cudzieho stromu.

## B. RFC-1: DiscoveryCache

### B.1 Súčasný stav u nás

- `Router` má `useCache` + `cacheFile` (PHP-return súbor, `hydrateFromCache`,
  `writeCache`) — ale len routy, nie celý module-scan.
- `MiddlewareRegistry`, `ThemeRegistry`, `ShellContextProviderRegistry`,
  `MarkdownBlockRegistry` skenujú pri každom boote nanovo (glob +
  prečítanie súborov). `Bootstrapper` má len marker `.migrations-applied`.
- `PageCacheService` je page-cache pre rate-limit fallback, nie discovery-cache.

### B.2 Návrh (port, nie kópia)

Nový `Modules\Core\Application\Services\DiscoveryCache`:

- Payload: `modules` (názov, sourceDir, routes/migrators/middleware/navBadges/
  markdownBlocks/shellProviders/themeModules/assets/dependencies/extra),
  `routes` (flatten pre Router), `middlewareOrder`, `version: 1`.
- Formát: `<?php return [...];` cez `var_export`, atomický write
  (tmp v tom istom dir + `rename`), ako Marko aj náš Router.
- Fingerprint (naša verzia Marko `DiscoveryFingerprint`): **nie**
  `installed.json` (vendor u nás nemusí existovať) ale hash nad:
  zoznam `src/Modules/*` + `modules/*` adries + každého `*.php`.
  DVOJÚROVŇOVO (implementované): studené súbory `path:size:mtime`, súbory
  s `mtime >= builtAt - 1` obsahový `md5_file` (`builtAt` je v payloadi,
  verzia payloadu 2). Ruší same-second same-size blind-spot; zvyšok je
  len ručne preservovaný mtime (`discovery:clear`). `sha256` (prenosnosť).
- Load poradie: súbor chýba → scan; `version` nesedí → scan;
  fingerprint nesedí → scan; poškodený → scan + log. Nikdy výnimka von.
- Trust-checky (world-writable/owner) z Marko **nepreberať 1:1** —
  shared-hosting realita: stačí skip pri `0002` bite + log, nie hard-fail.
  Dôvod: zmena oproti Marko, zdokumentovaná tu.
- Invalidácia: `ModuleInstaller::add/remove` mažú cache-súbor (ako dnes
  `invalidateMigrations` + `CacheInvalidator::flush`); nový
  `php bin/cms discovery:clear` (plain-PHP vetva) + `discovery:status`
  (fingerprint aktuálny vs. uložený). First-request `Bootstrapper` po
  novom číta cache, nie glob.

### B.3 Akceptácia

- Cold boot (bez cache) a warm boot (s cache) vracajú identický zoznam
  rout/middleware/providerov (test: `ModuleManifest` z disku vs. z cache).
- `module:add Erik` → fingerprint mismatch → rescan; `module:remove Erik` →
  rescan; hash stromu pred/po round-tripe identický.
- Poškodený cache-súbor (náhodné bajty) = rescan + log, žiadny 500.
- `discovery:clear` + `discovery:status` fungujú s aj bez `vendor/`.
- `composer test`, `phpstan` zelené.

### B.4 Riziká

- Stale-bytecode (`docs/modules.md` round-trip): long-lived `php -S` si pamätá
  triedy; cache to nerieši — reštart servera medzi fázami ostáva povinný.
- Dva writers naraz: `rename` je atomický, čitateľ vidí buď starý, alebo nový
  súbor — nikdy polovičný. Temp-názov `bin2hex(random_bytes(8))` ako Marko.

## C. RFC-2: PreferenceRegistry (ľahká verzia)

### C.1 Súčasný stav u nás

- Téma: `ShellContextProviderRegistry::theme()` — „first non-null wins
  (priority, then discovery order)", `SettingsShellContextProvider`
  `#[AsShellContextProvider(priority: -100)]` = stored-vote (`Design` select),
  whitelist + `ThemeRegistry::has()`, stale stored degraduje na kernel.
  Fixture providery odstránené práve preto, že glob-order hlasovanie kolidovalo.
- Ostatné: `AuthProviderRegistry`, `ThemeResolver` (statická tabuľka kernel
  vždy vyhráva nad discovery — modul nemôže zatieniť fallback).

### C.2 Návrh (úmyselne užší než Marko)

Marko `PreferenceRegistry` je všeobecný (ľubovoľná trieda → náhrada, zdroje
vendor/modules/app, konflikt pri rovnakej priorite = výnimka, reťazce, cykly).
My všeobecný DI-kontajner **nemáme a nechceme** (ServiceContainer je register).
Preto len **dva uzavreté body**:

1. **ThemePreference**: `ThemeResolver` dnes: statická tabuľka → stored vote
   (-100) → whitelist/discovery → kernel. Prepísať ako explicitný záznam
   `theme: <stored> -> <resolved>` s pravidlami: nikdy nie je možné
   preferovať neexistujúcu tému (stale stored = kernel, ako dnes);
   konflikt dvoch stored-hodnôt nemôže nastať (jeden settings kľúč).
   Žiadne reťazce, žiadne cykly — práve preto, že doména je jeden kľúč.
2. **`#[AsPreference(replaces: X)]` len pre porty** (`ShellContextProvider`,
   `AuthProvider`): neskorší modul vyhráva len v rámci portu, nie globálne;
   rovnaká priorita + rôzne moduly = posledný objednaný vyhráva + log
   (nie výnimka — dôvod: `module:add/remove` round-trip nesmie spadnúť na
   dočasný stav počas kopírovania; Marko výnimka by tu bola horšia).

Atribút pomenovať `AsPreference` (náš `As*` idiom), nie `Preference`
(Marko idiom) — konzistentne s `AsMiddleware`/`AsMigrator`.

### C.3 Akceptácia

- Stored `erik` → `erik-shell`; po `module:remove Erik` → `kernel-shell`
  bez fatalu (dnešné správanie, testy
  `SettingsShellContextProviderTest::aStoredGarbageThemeAbstains`,
  `resolvePathFollowsTheStoredTheme` zelené).
- Dva providery rovnakého portu pri rovnakej priorite: deterministický
  víťaz (abeceda) + log, nie 500.
- Žiadna zmena `ServiceContainer` API; `bin/cms` bez `vendor/` funguje.

### C.4 Riziká / čo vedome nerobíme

- Žiadne generované proxy (Marko `InterceptorClassGenerator`) — mimo
  zero-dep rozpočtu a mimo potreby: naše hook-pointy sú 2–3, nie „každá
  verejná metóda".
- Žiadna výnimka pri konflikte (odchýlka od Marko, dôvod vyššie).
- Žiadne preferencie pre ľubovoľné triedy — len porty. Všeobecný kontajner
  by znamenal prepis wiringu všetkých modulov.

## C.5 Implementované (odchýlky od návrhu)

- `ThemePreference` (`src/Modules/Core/Domain/`): čistá `resolve()` /
  `isResolvable()` nad `(stored, available, fallback)` — zdieľa ju
  `ThemeResolver::resolvePath()` aj Settings provider (duplicita pravidla
  zrušená, správanie pinované `tests/Domain/ThemePreferenceTest.php`).
- `PreferenceVote::select()` (`Application/Services`): čistý first-wins +
  `ignored` zoznam; `ShellContextProviderRegistry` (scalar otázky aj
  `navigation()`) a `AuthProviderRegistry::first()` logujú same-priority
  prebitých cez `error_log` (`[shell]` / `[auth]`).
- ODCHÝLKA 1: víťaz ostal FIRST (nie last, ako RFC navrhovalo) — obrátený
  glob-order je rovnako arbitrárny a prehodenie by dnes zmenilo, ktoré menu
  sa renderuje. Zlepšenie je viditeľnosť (log), nie nový víťaz.
- ODCHÝLKA 2: žiadny nový `#[AsPreference]` atribút — prioritné atribúty
  (`AsShellContextProvider`, `AsAuthProvider`) už nesú presne tú sémantiku;
  tretí atribút by ju len duplikoval.
- Fixture-test (`tests/Discovery/PreferenceContentionTest.php`) stráži
  stabilitu víťaza s/bez kontendenta naživo (merané: ignorant sa naozaj
  zalogoval). `modules/Settings` ↔ `src/Modules/Settings` držané v syncu
  (katalóg je zdroj, inštalovaná kópia je operator-state).

## D. Postup

1. RFC-1 najprv: `DiscoveryCache` + `discovery:clear/status`, testy
   cold-vs-warm + poškodený súbor + round-trip hash. HOTOVÉ.
   Následne rozšírené: payload `VERSION 3` nesie aj `middleware` sekciu
   (`MiddlewareRegistry::discoveredClasses()` → `prime()`; discovery bez
   instantií, `all()` chovanie nezmenené), `discovery:status` hlási aj
   počet middleware. Fingerprint je dvojúrovňový (VERSION 2, `builtAt`).
2. RFC-2 potom: `ThemePreference` prepis + `AsPreference` pre porty, testy
   stored/stale/konflikt.
3. Obe: `composer test`, `phpstan`, `module:add/remove` round-trip
   (`Erik` ako fixture), `build-dist.sh --skip-assets --skip-smoke`
   na overenie `storage/cache/` chovania v artefakte.
