---
title: "Bonus pre členov ... média: cudzie súbory v tvojom dome"
date: "2026-09-17"
version: "1.0.2"
tags: ["media", "uploads", "php", "security", "architecture"]
excerpt: "Piaty bonus len pre registrovaných. Upload je druhá brána, ktorou do appky vstupuje cudzie ... tentoraz nie text, ale súbory. Prečo meno súboru je tvrdenie a nie fakt, prečo každý asset pamätá svojho poskytovateľa a prečo aj mazanie má svoje poradie, na reálnom kóde z tohto repa."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-media"
published: true
---

> Toto je piaty **bonus pre členov**. Predchádzajúce diely: [zámok
> members-only](/blog/bonus-members-only-pre-deti), [immutable audit
> log](/blog/bonus-audit-log-pre-deti), [fulltext search cez
> FTS5](/blog/bonus-fts-search-pre-deti) a [komentáre s
> moderáciou](/blog/bonus-komentare-pre-deti). Dnes druhá brána pre
> cudzincov ... upload médií. Komentár píše riadok do databázy. Súbor sa
> vpúšťa do filesystemu, kde appka žije. A to je úplne iný druh hosťa.

Si vývojár. Minule tu bol formulár, ktorý vpúšťal cudzí text do tvojej
databázy, a verilo mu nula.

Komentáre boli brána číslo jedna.

Upload je brána číslo dva. Nepíše riadky. Vpúšťa súbory do filesystemu,
do miesta, kde leží appka, ktorú si písal celý týždeň.

Pravidlo oboch brán je to isté: cudzie vstúpi, ale len pod tvojimi
podmienkami.

## Meno súboru je tvrdenie, nie fakt

Návštevník nahraje súbor. Prehliadač hlási meno, veľkosť aj typ.

Nič z toho nie je fakt. Všetko sú to tvrdenia jednej strany.

Preto `LocalStorageProvider` zaobchádza s hlásením ako s výpoveďou, ktorú
treba overiť, a normalizuje raz, na boundary:

```php
// src/Modules/Core/Infrastructure/Storage/LocalStorageProvider.php — boundary
$size = is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0;
$this->validateFile($tmpName, $originalName, $size);
```

Veľkosť sa pretypuje raz. Cesty sa kontrolujú,`../` v mene súboru je
pokus o prechod mimo vyhradeného adresára a končí odmietnutím.

A typ? Prehliadač hovorí `image/png`. Provider sa nepýta prehliadača.
Pýta sa bajtov:

```php
// LocalStorageProvider.php — skutočný typ, nie hlásený
$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath);
```

`finfo` číta obsah. Rovnako ako meno hosťa v komentári, tvrdenie
prišlo od cudzinca, fakt sa zistil sám.

## Typ rozhoduje, koncovka sa prispôsobí

Zistený typ neslúži len na záznam. Rozhoduje, ktoré koncovky sú ešte
legálne:

```php
// LocalStorageProvider.php — whitelist viazaný na zistený typ
return in_array($extension, self::ALLOWED[$mime] ?? [], true);
```

`ALLOWED` je mapa, pre `image/png` pár legálnych koncovok, pre
`application/pdf` iné. Neznámy typ nemá žiadnu vstupnú koncovku.

Súbor premenovaný na `fotka.png` teda neprejde ako obrázok, ak bajty
hovoria niečo iné. Whitelist nie je zoznam povolených mien, je to
zoznam povolených pravd.

## Dva pravdy o jednom súbore

Asset má vždy dve pravdy. Súbor na disku a riadok v `media.db`.

Nahranie ich vytvorí v poradí: najprv `store()` na disku, potom
`insert()` do databázy.

```php
// src/Modules/Media/Application/Services/MediaService.php — upload
$result = $storage->store($file);
$asset = MediaAsset::create(/* ... z výsledku */);
$this->repository->insert($asset);
```

Mazanie ide opačne, a poradie nie je ľahostajné:

```php
// MediaService.php — delete: najprv súbor, potom riadok
$storage = self::resolveProvider($asset->provider(), $this->storage);
$storage->delete($asset->path());
$this->repository->deleteTagsForAsset($id);

return $this->repository->delete($id);
```

Buďme úprimní: medzi filesystemom a SQLite nepíšeš `BEGIN COMMIT`.
Transakcia cez dva svety neexistuje.

Poradie preto vybralo stranu, na ktorej je chyba viditeľná. Riadok bez
súboru sa dá dohľadať a domazať. Súbor bez riadku je sirota, ktorú
nikto neuloží, appka o ňom nevie, že existuje.

## Každý asset pamätá, odkiaľ prišiel

Dnes konfiguruješ jeden storage provider. O rok ho vymeníš.

Súbory ostali. Preto každý riadok v `media.db` nesie stĺpec `provider`,
a mazanie si hostiteľa vyhľadá po jednom:

```php
// MediaService.php — resolveProvider pri každom zásahu
return match ($provider) {
    'local' => new LocalStorageProviderAdapter(new LocalStorageProvider()),
    'cloudinary' => new CloudinaryProvider(),
    default => $default,
};
```

Asset nahraný do cloudu sa maže v cloude, aj keď je dnes nastavený
lokálny disk. Konfigurácia je súčasná pravda. História súborov je
skutočnosť zapísaná v riadku.

Rovnaká disciplína aj pri nahrávaní: cloud prijíma len obrázky.

```php
// MediaService.php — guard pred uložením
if ('cloudinary' === $provider && !$this->isImageMimeType($mimeType)) {
    throw new \RuntimeException('Cloudinary only accepts images (jpg, png, gif, webp).');
}
```

## Usporiadanie je metadáta, nie štruktúra

Kategória je stĺpec. Tagy sú spojovacia tabuľka `media_tags`.

`setTagsForAsset` najprv zmaže staré a potom vloží nové, zoznam tagov
je vždy celá pravda, nie prírastok:

```php
// src/Modules/Media/Infrastructure/Persistence/MediaRepository.php — tagy
$this->deleteTagsForAsset($id);

foreach ($tags as $tag) {
    $tag = trim(strtolower($tag));
    // ... insert
}
```

Aj tag sa normalizuje na boundary, trim a malé písmená. Bez toho by
`PHP` a `php` boli dva svety. Filtrovanie podľa typu beží cez prefix MIME,`LIKE 'image/%'`.
Poradie v prehľade zvládne aj `view_count` ... čo si pozeral, to sa počíta.

## Dve brány na jednom mieste

Komentáre a média sú dva write pathy pre cudzie. Majú rovnaký rámec:

| | Komentáre | Média |
|---|---|---|
| čo vstupuje | text | súbor |
| kam | comments.db (pending) | filesystem + media.db |
| prvá obrana | rate limit skôr než validácia | finfo + whitelist na obsahu |
| publikovanie | moderácia v `/mark/comments` | okamžite, whitelist je brána |
| audit | zapisuje každý zásah | zapisuje každý zásah |
| cache | flush po zápise | flush po zápise |

Oba cesty končia v audit logu a oba zmyjú cache (`CacheInvalidator::flush()`),
aby rate-limit fallback nikdy neservoval pred-zápisový obsah.

## Kedy whitelist neplatí (a povinná úprimnosť)

Whitelist je obrana pri vchode, nie pri vydávaní.

Súbor, ktorý prešiel, sa servuje pod hlavičkou, ktorú určuje server,
a CSP rozhoduje, čo smie obsah spustiť. Nahraný súbor preto nie je
„bezpečný“ ... je to len nepriateľ, ktorého pustili vnútro s pásom
na rukách.

Druhá úprimnosť: `batchDelete` vracia zoznam ID, ktoré sa podarilo
zmazať. Čiastočný úspech je tu úprimný výsledok, nie chyba API.

A tretia: tu nie je resizovacia pipeline. Súbor má takú veľkosť,
akú prišiel, kto chce náhľady, musí ich nahrať alebo doplniť
generátor.

## Prakticky

1. **Nahraj cez `/mark/media/upload`** ... voľba providera je vo formulári,
   audit log si akciu zapíše sám.
2. **Popíš asset** ... alt text, popis, kategória a tagy sú metadáta,
   z ktorých vzniká vyhľadávanie v správcovi.
3. **Nechaj picker pracovať** ... `/mark/media/json` je JSON endpoint,
   ktorý volá editor článkov, keď vyberáš obrázok.

Picker potom odpovedá na otázku „ktorý súbor“ ... nie „kde sa nachádza“.

## Zdroje

- [OWASP, File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) ... rámec, do ktorého whitelist a servovanie patria
- [PHP, finfo](https://www.php.net/manual/en/book.fileinfo.php) ... zisťovanie typu z obsahu, nie z mena
- [PHP, move_uploaded_file](https://www.php.net/manual/en/function.move-uploaded-file.php) ... jediný legitímny presun nahraného súboru
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
- [docs/blog-voice.md](/docs/blog-voice) ... príručka, v ktorej série bonusov žije
