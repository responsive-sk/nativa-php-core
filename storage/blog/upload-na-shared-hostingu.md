---
title: "Upload na shared hostingu ... príbeh troch hlášok"
date: "2026-09-25"
version: "1.0.2"
published: true
tags: ["php", "hosting", "security", "beginners"]
excerpt: "WAF zablokuje uloženie článku, upload skončí barom bez slov a log vráti 500 ... tri vrstvy nad appkou, tri mlčanlivé hlášky a jedna cesta okolo nich bez tiketu na podporu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "uploads-on-shared-hosting"
---

Máš blog. Lokálne mu všetko funguje ... admin panel, editor článkov, uploady obrázkov. Deployneš na shared hosting a prvá vec, ktorú skúšaš, je uložiť článok o pacmane.

Vráti sa hláška: BLOCKED BY WEB FIREWALL.

Skúšaš teda upload obrázka. Progress bar sa naplní, zafarbí sa načerveno ... a žiadna správa. V logoch medzitým pribudol riadok so statusom 500.

Toto nie je jeden problém. Sú to tri vrstvy nad tvojou aplikáciou a každá ti povedala len toľko, koľko musela.

## Tri hlášky

| Hláška | Kto ju povie | Čo znamená v skutočnosti |
|---|---|---|
| BLOCKED BY WEB FIREWALL | firewall hostingu | POST obsahuje reťazec, ktorý vyzerá ako útok |
| červený bar bez slov | JS formulára | chybové telo sa zahodilo skôr, než si ho stihol prečítať |
| Invalid file upload | aplikácia | PHP kód chyby sa zahodil ešte skôr |
| tempnam(): open_basedir restriction | log kernelu | systémový temp adresár je mimo allowlistu |

Čítaj zhora nadol ... presne v poradí, v akom prichádzajú.

## Hláška 1 ... firewall

Technický blog o Linuxe píše o shell príkazoch. Telo článku o pacmane obsahuje `sudo pacman -S` a `curl … | bash` — firewall hostingu číta POST ešte predtým, než sa dostane k PHP. Vidí shell príkazy v požiadavke a rozhodne: útok.

Nie je. Je to článok pre deti o rebríku dôvery.

Riešenie nerieši firewall — obchádza obsah. Skript formulára kóduje telo do base64 ešte pred submitom:

```js
// src/Modules/Blog/Interfaces/HTTP/assets/scripts/article-form-guard.js
encoded.value = btoa(unescape(encodeURIComponent(body.value)));
```

Server dekóduje naspäť; pokazený payload padne na surové pole, takže formulár funguje aj bez JavaScriptu:

```php
// src/Modules/Blog/Interfaces/HTTP/Actions/ArticleFormPayload.php
$decoded = base64_decode($encoded, true);
if (is_string($decoded)) {
    return $decoded;
}
```

Firewall uvidí písmená. Článok si `sudo pacman -S` nechá presne tak, ako ho napíšeš.

## Hláška 2 ... bar bez slov

Upload formulár má vlastnú vrstvu ticha. Prvá patrí JS: pri chybe zahodil `responseText` — server správu poslal, ale bar sa len zafarbí.

Druhá je hlbšie. Keď telo POSTu presiahne `post_max_size`, PHP ho zahodí celé — `$_FILES` aj `$_POST` prídu prázdne a kontrola limitu v aplikácii sa ani nespustí.

Oprava má dva kroky. Server rozozná zahodené telo a odpovie 413 so správou ... a formulár dostane limit, ktorý server má nastavený:

```php
// src/Modules/Media/Interfaces/HTTP/MediaUploadLimits.php
$base64Max = min((int) ($post * 3 / 4), self::APP_LIMIT_BYTES);
```

Browser potom odmietne 12 MB súbor vetou, nie farbou — ešte pred prvým bajtom.

## Hláška 3 ... tmp adresár

Keď chybové hlášky fungujú, pravda vyjde navrch: Invalid file upload → po namapovaní PHP kódov → Server misconfiguration: the PHP temporary directory is missing.

`upload_tmp_dir` je `PHP_INI_SYSTEM` — `.htaccess` aj `.user.ini` sú mimo hry. A vlastný pokus o dočasný súbor padol tiež:

```text
[KERNEL] ErrorException: tempnam(): open_basedir restriction in effect.
File(/home/resp/.inetadmin-php-tmp) is not within the allowed path(s) ...
```

Bajty preto idú v poli ako base64 a `MediaUploadPayload` z nich poskladá syntetickú `$_FILES` entry — tmp súbor sa staguje v strome appky, pod allowlistom:

```php
// src/Modules/Media/Interfaces/HTTP/MediaUploadPayload.php
$dir = AppPaths::instance()->storage('cache' . DIRECTORY_SEPARATOR . 'media-uploads');
```

Validácia, MIME detekcia aj audit log bežia nezmenene — syntetická entry vyzerá ako pravá `$_FILES`. Bonus: `upload_max_filesize` medzitým prestal platiť ... ten viaže len multipart staging.

## Čo tento trik nie je

Nie je to oprava hostingu. Multipart staging zostáva rozbitý — base64 cesta je obejdenie, nie náprava. Hosting, ktorý nízko capne aj `post_max_size`, ti ňou nepomôže ... vtedy ostáva tiket.

Bajty napučia zhruba o tretinu — 8 MB súbor cestuje ako ~10,7 MB textu a `post_max_size` sa počíta z napučanej verzie.

Nie je to rýchlejšie. Kódovanie a dekódovanie je práca navyše na oboch stranách; pri obrázkoch do pár MB ju nepostrehneš.

Bez JavaScriptu nefunguje — fallback ostáva multipart. Kde staging funguje, funguje aj stará cesta.

## Prakticky

1. Prečítaj log skôr, než skúšaš druhýkrát — riadky `[KERNEL]` v `storage/logs` povedia súbor aj riadok; browser videl len 500.
2. Daj klientovi limit, ktorý server reálne má — odmietnutie vetou stojí menej než odmietnutie farbou.
3. Keď staging nefunguje, pošli bajty v poli — *„Diagnostika potom odpovedá na otázku, ktorá vrstva mlčí ... nie na otázku, čo je rozbité.“*

## Zdroje

- [PHP: Error Messages Explained](https://www.php.net/manual/en/features.file-upload.errors.php) — zoznam `UPLOAD_ERR_*` kódov
- [PHP: open_basedir](https://www.php.net/manual/en/ini.core.php#ini.open-basedir) — čo allowlist obmedzuje
- [PHP: List of php.ini directives](https://www.php.net/manual/en/ini.list.php) — ktoré nastavenie je `PHP_INI_SYSTEM`
- [PHP: .user.ini](https://www.php.net/manual/en/configuration.file.per-user.php) — limity zmien per adresár
- [Nativa Minimal — docs](https://github.com/evan70/nativa-minimal/tree/main/docs) — repozitár, z ktorého tento príbeh pochádza
