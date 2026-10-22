---
title: "O nás"
published: true
seo_title: "O nás"
seo_description: "responsive.sk je ukážkový web postavený na Nativa PHP: malý runtime, ktorý sa dá prečítať celý, a moduly, ktoré sa dajú preukázateľne odobrať."
---

**responsive.sk** je ukážkový web postavený na **Nativa PHP** — malom PHP runtime, ktorý nemá žiadnu produkčnú závislosť okrem PHP a SQLite. Nie je to ďalší framework s vrstvami, ktoré treba obchádzať: je to strom, ktorý sa dá prečítať celý, a moduly, ktoré sa dajú pridať aj preukázateľne odobrať.

## Čo tu nájdete

- **Články** o architektúre, nástrojoch a rozhodnutiach, ktoré za týmto stromom stoja.
- **Dokumentáciu** k jadru, modulom a témam — po anglicky, pre tých, ktorí strom čítajú alebo v ňom pracujú.
- **Otvorený kód**: celý strom je verejný a každé tvrdenie v článku má v repozitári miesto, kde sa dá overiť.

## Ako je postavený

Jadro (`Modules\Core` a `Modules\Database`) drží routing, dvojicu request/response, middleware pipeline, migrátor, markdown renderer a degradované SEO plochy. Všetko ostatné — blog, stránky, komentáre, otázky, bezpečnosť, nastavenia — je modul, ktorý sa do stromu kopíruje príkazom `bin/cms module:add` a odstraňuje príkazom `bin/cms module:remove`.

Z toho vyplýva pravidlo, na ktorom celý strom stojí:

> Súbor jadra nesmie importovať modul. Modul môže importovať jadro.

Plocha, ktorá potrebuje modul, patrí modulu. Preto jadro odpovie `200` na `/`, `/robots.txt`, `/sitemap.xml` a `/docs` aj v strome, kde nie je nainštalovaný ani jeden modul — a preto sa dá odstránenie modulu overiť, nie len sľúbiť.

## Prečo takto

Väčšina aplikácií sa nedá pochopiť po častiach, pretože každá časť predpokladá tú druhú. Tu je hranica nakreslená dopredu a meraná testami: import grafu, tvar modulového katalógu, vlastníctvo konštánt, kontrakty payloadov. Keď sa hranica pohne, padne test — nie až zákazník.
