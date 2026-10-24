---
title: "PHP2Shell: tri dni, tri CVE a jeden riadok v php.ini"
date: "2026-10-24"
tags: ["php", "bezpečnosť", "cve", "php2shell", "session", "deployment"]
excerpt: "Dva dni po núdzovom vydaní dvoch RCE v PHP core prišla tretia CVE — a jej mitigácia je konfigurácia, nie kód. Zmerané na tomto stroji: PHP 8.5.12, `^8.5` v composer.json (dnes už `^8.5.12`), nula výskytov `session.upload_progress` a osem volaní `session_start()`."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bezpečnosť"
published: true
---

Tento strom sa chváli tým, že nemá **žiadne runtime Composer závislosti**:
`composer.json` obsahuje len `ext-*` a PHP. Presne preto sa zdá, že sa ho
dodávateľský reťazec netýka. Týka. Dvadsať hodín bežal na interpreteri, ktorý
mal dve vzdialené spustenia kódu ohodnotené 9,2 — a v repe nebolo nič, čo by si
to všimlo.

Toto je zápis toho, čo sa stalo medzi 20. a 23. októbrom 2026, čo z toho platí
pre tento strom a čo v ňom po tejto skúsenosti chýbalo — a čo sa z toho podarilo
zavrieť v ten istý deň. Nie je to návod na paniku; je to prevod jedného týždňa
verejných advisory do konkrétnych riadkov.

---

## Časová os

| Dátum | Čo sa stalo |
|---|---|
| 20. 10. | Detaily dvoch RCE unikli na sociálne siete pred plánovaným zverejnením 22. 10. PHP ohlásilo núdzové vydanie na 21. 10., 14:00 UTC. |
| 21. 10. | Vydané `8.5.12`, `8.4.27`, `8.3.36`, `8.2.35` — opravujú `CVE-2026-109618` a `CVE-2026-109619`. watchTowr v ten deň potvrdil zneužitie v praxi cez honeypot sieť. |
| 21. 10. 20:05 | Tento stroj má PHP `8.5.12` (build z toho istého dňa). |
| 23. 10. | PHP vydáva **informačný** advisory k `CVE-2026-115560` — tretia cesta k spusteniu kódu, tentokrát cez `session.upload_progress`. Bez opravy, tá príde v riadnom vydaní. |

Dve veci z tej osi stoja za zapamätanie. Prvá: od úniku po zneužitie to boli
**hodiny**, nie týždne — čo je pri RCE v interpreteri skôr pravidlo než
prekvapenie. Druhá: `CVE-2026-109618` a `109619` **nie sú** v CISA KEV
katalógu (stav k 22. 10.), čiže „počkáme na KEV" by v tomto prípade znamenalo
neopraviť nič.

---

## Čo tie dve CVE robia

Obidve sú v jadre PHP, nie v aplikácii, a obidve sú neautentifikované. Mechanizmus
je dôležitý preto, lebo určuje, čo je „oprava".

- **`CVE-2026-109618` — OOM počas zostavovania requestu.** PHP minie pamäť v
  strede prípravy requestu a nechá vnútorné štruktúry napoly postavené. Čistiaci
  kód im potom verí.
- **`CVE-2026-109619` — vnútorný stav, ktorý prežije request.** Interná
  štruktúra prežije pamäť, na ktorú ukazuje, takže **nasledujúci request na tom
  istom workeri** pracuje so zvyškami toho predchádzajúceho.

Z druhého bodu vyplýva niečo, čo sa pri bežných závislostiach nedeje: oprava
nie je len „vymeniť binárku". Je to „vymeniť binárku **a** zabiť každý worker,
ktorý ešte beží so starou". Reload nestačí, ak proces nezanikne — a pri
`opcache.preload` alebo pri dlhovekých FPM workeroch nezanikne.

PHP v advisore uvádza, že **žiadna konfigurácia tieto dve CVE nezmierňuje**.
To je výnimočné a treba to brať doslova: `disable_functions`, `open_basedir`
ani WAF nie sú odpoveď. Odpoveď je verzia.

| Vetva | Zraniteľné | Opravené |
|---|---|---|
| 8.5.x | `< 8.5.12` | `8.5.12` |
| 8.4.x | `< 8.4.27` | `8.4.27` |
| 8.3.x | `< 8.3.36` | `8.3.36` |
| 8.2.x | `< 8.2.35` | `8.2.35` |
| 7.4, 8.0, 8.1 | všetky | bez oficiálneho vydania — distro/LTS balík alebo minimálne backporty |
| ≤ 7.3, vrátane PHP 5 | všetky | oprava neexistuje; upgrade |

Riziko je najvyššie pri **Apache `mod_php`**, kde sa dá uspieť skôr, než sa
spustí prvý riadok aplikácie. Tento strom beží pod FPM, ale to nie je
útecha — je to len iná hranica.

---

## Čo to znamenalo pre tento strom

Po advisory som nespúšťal scanner. Zmeral som tri veci, ktoré sa dajú overiť
v repe, a jednu, ktorá sa overiť nedá.

**1. Verzia na stroji.** `php -v` hlási `PHP 8.5.12 (cli) (built: Oct 21 2026
20:05:19)`. Fixed. Rozdiel medzi „beží" a „je opravené" bol v tomto prípade
šesť hodín od vydania.

**2. Podlaha v `composer.json`.** Strom deklaroval:

```json
"require": {
    "php": "^8.5"
}
```

`^8.5` znamená `>=8.5.0 <9.0.0`. Je to **určenie vetvy, nie záplata** — a
`8.5.11` ho spĺňa rovnako dobre ako `8.5.12`. Composer pri inštalácii
neoveruje, či váš `8.5.x` má tú ktorú CVE opravenú; `composer.lock` PHP
neobsahuje vôbec, takže sa nedá „uzamknúť" tak, ako sa uzamyká balík.

Dnes je v tom istom poli `^8.5.12`. Caret sa dá použiť aj s patchom —
`^8.5.12` je `>=8.5.12 <9.0.0`, teda podlaha **aj** strop, ktorý drží strom
na osmičkovej vetve. Composer to overuje pri každom `install`/`update`:
`composer prohibits php 8.5.11` na tom poli vráti, že projekt `requires php
(^8.5.12)`. To je celá oprava — jeden znak navyše v constrained verzii
a zraniteľná záplata prestane byť inštalovateľná.

**3. Čo beží v CI.** Workflow má `PHP_VERSION: "8.5"`, takže každý beh dostane
to, čo v ten deň `setup-php` vyrieši ako najnovšiu záplatu. Minulý týždeň to
bolo `8.5.11`; dnes `8.5.12`. To je pohodlné a presne preto to nie je dôkaz:
zelené CI nehovorí nič o verzii na serveri, ktorý obsluhuje čitateľov.

**4. Čo overiť nedá.** Či na produkcii beží ten istý binárny súbor, ktorý
hlási `php -v`. `php -v` v shelli číta CLI konfiguráciu a CLI binárku; FPM má
vlastnú. Toto je jediná položka zoznamu, ktorú z repozitára nezistíte — a je
to tá najdôležitejšia.

Ešte jedna vec, ktorá sa ukázala ako užitočná: `composer audit`, ktorý sme do
CI pridali v ten istý deň, **túto triedu problémov nekryje**. Audit číta
`composer.lock` a `pnpm-lock.yaml`; interpreter nie je balík. Pri stroji bez
runtime závislostí je teda „dependency audit zelený" pravdivé a súčasne
nepoužiteľné ako odpoveď na otázku „som zraniteľný?".

---

## Tretia CVE: `session.upload_progress`

Dva dni po oprave prišiel advisory, ktorý už nebol o jadre requestu, ale o
jednej voliteľnej funkcii: `session.upload_progress` — mechanizmus, ktorý PHP
plní do `$_SESSION`, aby aplikácia mohla kresliť progress bar nahrávania. Je
**zapnutý defaultne**.

PHP tento prípad sám nazýva „nepravdepodobný" a opravu zaradilo do riadneho
vydania. Podmienky sú úzke, ale každá z nich je overiteľná:

| Podmienka | Kde sa nastavuje |
|---|---|
| `session.serialize_handler` je `php_binary` alebo `php_serialize` | `php.ini` alebo ekvivalent |
| aplikácia zavolá `ini_set('session.serialize_handler', 'php')` **pred** `session_start()` | kód aplikácie |
| `session.upload_progress.enabled = 1` | `php.ini` (default) |

Tu je dôvod, prečo je mitigácia konfigurácia a nie patch v aplikácii: spúšťačom
je práve **runtime `ini_set()`**. Keby ste to riešili v kóde, riešili by ste
symptóm toho istého mechanizmu, ktorý útočník používa.

**Zmerané v tomto strome:** `git grep upload_progress` vráti **0** výskytov —
funkcia nie je použitá nikde v trackovanom kóde. Session nastavuje
`config/session.php`, ktorý v repe **je** (deväť `ini_set` volaní: `name`,
`use_strict_mode`, `use_only_cookies`, `cookie_httponly`, `cookie_samesite`,
`cookie_secure` podľa HTTPS, `gc_maxlifetime`, `auto_start`, `save_path`) — a
ani jedno z nich nie je `session.serialize_handler`. V `src/` a `modules/` nie
je `ini_set` na `session.*` ani jeden; ostatné `ini_set` v produkte sú
`log_errors`, `error_log` a `display_errors` v `src/http.php`. `session_start()`
sa volá lenivo na **ôsmich miestach v šiestich súboroch** nainštalovaného slice
(CSRF, layout, page cache, locale, settings, auth).

To znamená: prvú a druhú podmienku strom sám nevytvára — handler ostáva na
`php`, čo je default. Štvrtú — zapnutý `upload_progress` — áno, ale len preto,
že ju nikto nevypol. A je to jediná zo štyroch, ktorú appka neriadi: cookie
flagy a strict mode už v `config/session.php` nastavené sú.

To je presne ten rozdiel, ktorý sa v incidente stráca: **kód je čistý a
konfigurácia je default.** Bezpečnostná diera nemusí byť v tom, čo ste
napísali.

### Prečo `php_admin_flag` a nie `ini_set`

Ak by ste mitigáciu dali do PHP kódu (`ini_set('session.upload_progress.enabled', '0')`),
nesplnili by ste nič: exploit **je** `ini_set`. Musí to byť administratívna
hodnota, ktorú aplikácia nemôže prepísať.

```ini
; FPM pool
php_admin_flag[session.upload_progress.enabled] = off

; Apache mod_php
php_admin_flag session.upload_progress.enabled off
```

A potom **restart, nie reload** — plus overenie na tom SAPI, ktoré naozaj
obsluhuje požiadavky. `php -i` v shelli je CLI konfigurácia a pri otázke
„platí to pre web?" odpovedá na inú otázku, než akú ste položili:

```sh
php-fpm -i | grep -E 'session\.(upload_progress|serialize_handler)'
```

### Kde to v tomto repe žije

Jedna direktíva, jeden súbor: `deploy/php/99-nativa-session.ini`. Inštalácia je
kopírovanie, nie editácia `php.ini` — a to je celý rozdiel medzi mitigáciou,
ktorá sa dá reviewovať v PR, a mitigáciou, ktorá existuje len na jednom serveri:

```sh
sudo cp deploy/php/99-nativa-session.ini /etc/php/conf.d/
php -i | grep '^session.upload_progress.enabled'     # Off
```

Prečo je v ňom len jeden riadok: `config/session.php` už nastavuje strict mode,
`cookie_httponly`, `cookie_samesite` aj `cookie_secure` podľa HTTPS. Skopírovať
tie štyri do ini súboru by nepridalo ochranu, len by to vyzeralo ako pokrytie —
pričom runtime `ini_set()` v appke aj tak vyhráva. Jediné, čo aplikácia
ovplyvniť nesmie, je `upload_progress`, a to je jediné, čo tam je.

Na tomto stroji to bolo merateľné do písmena: `/etc/php/php.ini` je
`php.ini-production` z PHP 8.5.12 a všetkých šesť `upload_progress` direktív je
v ňom **zakomentovaných** — rozhodujúci riadok 1453 je
`;session.upload_progress.enabled = On`. Default `On` teda nebol nikde
prepísaný a jediná stopa bola v advisory, ktoré si prečíta len ten, kto ho
hľadá.

---

## Runbook, ktorý z toho vznikol

Šesť krokov, v tomto poradí. Prvý je diagnostika, nie oprava — bez neho sa
opravuje neznáma binárka.

1. **Zisti, čo naozaj obsluhuje requesty.** `ps -eo pid,args | grep -E
   'php-fpm|httpd|php-cgi'` a k tomu `readlink -f /proc/<pid>/exe`. Ak sa cesta
   nedá prečítať, nemáte inventár, máte zoznam dohadov.
2. **Zisti verziu tej binárky**, nie tej z `PATH`: `php-fpm -v` z toho istého
   prefixu, alebo `/proc/<pid>/exe -v`.
3. **Oprav.** `8.5.12` / `8.4.27` / `8.3.36` / `8.2.35`. Pri distribúcii
   aktualizujte balík, nie zdroj; distribúcie mali záplaty pred vydaním.
4. **Vypni `session.upload_progress`** cez `php_admin_flag` (vyššie) alebo
   drop-inom z `deploy/php/99-nativa-session.ini`. Jedna línia, ktorá platí aj
   pre `CVE-2026-115560`, aj ako hygiena.
5. **Restartuj každý worker.** `systemctl restart php8.5-fpm` a k tomu
   `opcache` reset. Reload necháva staré procesy žiť.
6. **Over na SAPI.** `php-fpm -i | grep session.upload_progress` musí vrátiť
   `Off`, nie `On`.

Body 1 a 2 sú tie, ktoré sa najčastejšie preskočia. Pri `mod_php` je rozdiel
medzi „aktualizoval som PHP" a „Apache používa nové PHP" celý incident.

---

## Čo tomuto repu chýbalo

Týždeň ukázal tri diery, ktoré neboli v kóde, ale v tom, čo je zapísané. Dve
z nich sa dali zavrieť v ten istý deň:

| Chýbalo | Dôsledok | Stav k 24. 10. |
|---|---|---|
| **Runtime podlaha** — `^8.5` namiesto `^8.5.12` | constraint spĺňal aj zraniteľnú verziu; nikde nebola veta „pod 8.5.12 neinštaluj" | **zavreté** — `composer.json` má `^8.5.12`, `8.5.11` už neprejde |
| **`SECURITY.md`** — žiadny kontakt ani rozsah podpory | niet kam nahlásiť zraniteľnosť; niet ako zistiť, či verzia, ktorú používate, je tá podporovaná | **zavreté** — kontakt, rozsah, non-goals a pravidlo pre CVE |
| **Deployment konfigurácia** — žiadny `php.ini`/pool config v repe | `session.upload_progress` sa nedal reviewovať v PR; bol len na serveri | **čiastočne** — `deploy/php/99-nativa-session.ini` je v gite a reviewovateľný, ale nainštalovať ho musí operátor |

Prvá položka bola čisto inžinierska a lacná: jeden znak navyše v constrained
verzii. Druhá je hodina písania, ktorá sa odkladá najľahšie. Tretia zostáva
čiastočne otvorená a nezatvorí ju žiadny commit: `deploy/php/99-nativa-session.ini`
dáva obsahu mitigácie stopu v gite, ale či ho konkrétny server načítal, vie iba
ten server. Rozdiel je v tom, že už sa dá v PR nesúhlasiť s obsahom — a nie len
zistiť po incidente, že nikto nevie, čo v `/etc` je.

A jedna vec, ktorú tento článok **nerieši**: či je `FileSessionHandler`
v `Infrastructure/Session/` vôbec zapojený. `session_set_save_handler` sa
v strome nevyskytuje ani raz, takže trieda vyzerá ako mŕtvy kód — a mŕtvy
kód v bezpečnostnej ceste je horší než žiadny, lebo vyzerá ako rozhodnutie.
To je samostatná otázka, nie dodatok k CVE.

---

## Pravidlo, ktoré zostáva

Tri vety, ktoré by som chcel mať napísané minulý pondelok:

1. **Závislosti nie sú celý dodávateľský reťazec.** Interpreter, SAPI a
   konfigurácia sú tiež verzie, ktoré starnú — len nie sú v lockfile.
2. **Pri CVE v jadre nestačí vymeniť súbor.** Musí zaniknúť každý proces,
   ktorý drží starý stav; inak sa oprava netýka toho, čo obsluhuje requesty.
3. **Mitigácia, ktorá sa nedá reviewovať, neexistuje.** `php_admin_flag`
   v `/etc` je bezpečnostné rozhodnutie bez stopy v repozitári — a preto sa pri
   ďalšej CVE bude znovu objavovať. Drop-in je kompromis, ktorý sa dá aspoň
   prečítať: obsah v gite, načítanie na serveri.

---

*Merané 24. októbra 2026 na tomto stroji: PHP `8.5.12` (build 21. 10. 20:05),
`composer.json` s `"php": "^8.5"` v čase merania a `^8.5.12` po oprave,
`PHP_VERSION: "8.5"` v CI, 0 výskytov
`session.upload_progress` v trackovanom kóde, 9 volaní `ini_set` na `session.*`
v `config/session.php` (žiadne na `serialize_handler`), 0 v `src/` a `modules/`,
8 volaní `session_start()` v šiestich súboroch nainštalovaného slice, a
`/etc/php/php.ini` (php.ini-production, 8.5.12), kde je riadok 1453
`;session.upload_progress.enabled = On` — teda default, nikým neprepísaný. Fakty
o CVE sú z advisory PHP, z [FAQ watchTowr](https://watchtowr.com/intelligence/php-cve-2026-109618-faq/)
a z [php.net/releases](https://www.php.net/releases/); čísla o strome sú
z behu, nie z dokumentácie. Ak niektoré už neplatí, je to dôvod na ďalšie
meranie, nie na vymazanie odseku.*
