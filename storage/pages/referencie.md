---
title: "Referencie"
published: true
translation: "references"
seo_title: "Referencie"
seo_description: "Čo si na tomto webe viete otvoriť a overiť: stránka, jadro Nativa PHP, šesť tém a dokumentácia — bez log, ktoré sa nedajú skontrolovať."
---

Tento web je ukážkový, a preto aj jeho referencie vyzerajú inak: **namiesto log sú tu odkazy, ktoré si otvoríte a skontrolujete sami.** Každá položka nižšie je niečo, čo v tomto strome naozaj beží — nie prezentácia o ňom.

## Tento web

Stránku, ktorú práve čítate, si viete prejsť celú: [sitemapa](/sitemap.xml) vypisuje všetky verejné adresy, [robots.txt](/robots.txt) hovorí, čo má crawler vidieť, a [`/health`](/health) vracia stav jednotlivých sond. Beží na tom istom jadre, ktoré popisuje stránka [O nás](/about): PHP bez Composer závislostí, obsah v markdown súboroch a administrácia na `/mark`.

## Nativa PHP Core

Jadro je [verejný repozitár](https://github.com/responsive-sk/nativa-php-core). Každé tvrdenie z [článkov](/blog) má v kóde miesto, kde sa dá overiť, a odstránenie modulu nie je sľub: `php bin/cms module:remove Blog` ho naozaj odstráni a testy v repozitári overujú, že strom po odstránení zostane funkčný.

## Šesť tém

Boson, Erik, Griffe, Jednotka, Mono a Nativa. Všetky sú v `themes/gallery`, buildí ich `make assets` a výsledok je v `public/dist/nativa`. Téma je nastavenie v `/mark` → Settings, nie zásah do kódu — preto si viete porovnať, ako sa tá istá stránka správa v šiestich rôznych šatách.

## Dokumentácia a články

- [`/docs`](/docs) — dokumentácia k jadru, modulom a témam, po anglicky, pre tých, ktorí strom čítajú alebo v ňom pracujú.
- [`/blog`](/blog) — články s odkazmi na konkrétne súbory a commity, nie zhrnutia bez zdroja.

## Prečo tu nie sú logá

Referencia, ktorú si neviete otvoriť, je len obrázok. Táto stránka preto ukazuje to, čo sa overiť dá — a na konkrétny projekt sa [opýtajte priamo](/contact).
