---
title: "Font set, ktorý nemal kde vziať znaky : a nástroje, ktoré to nevedeli"
date: "2026-10-21"
tags: ["fonty", "woff2", "fonttools", "subsetting", "cache", "boson"]
excerpt: "Bosonove fonty mali 24 chýbajúcich slovenských znakov. Zmerané: prečo subsetter nedokáže pridať glyf, ako vyzerá oprava z toho istého buildu, čo nesmie zmeniť metriky : a prečo nový font musí mať novú URL."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Nástroje"
published: true
---

## Časť I > Symptóm, ktorý vyzerá ako chyba prehliadača

Veta „Čo z toho zostáva" je nadpis.
Veľké `Č` v nej bolo vykreslené iným písmom než zvyšok slova >
fallbackom, ktorý má diakritiku, kým náš webfont ju nemá.
Prehliadač sa nezbláznil; urobil presne to, čo má:
pre znak >
ktorý v fonte chýba, siahol po ďalšej rodine v `font-family` reťazi.

Otázka teda nebola „prečo to blbne" >
ale „prečo ten font nemá `Č`, keď sme pred chvíľou tvrdili, že je opravený".
Odpoveď má dve časti a obe sú merateľné >
**v súbore chýbalo 24 znakov** >
a **prehliadač mal v cache starú verziu toho istého súboru**.

---

## Časť II > Čo bolo v súboroch

Dva fonty, ktoré Boson reálne sťahuje, boli *latin-only* subset-y:

```
public/fonts/inter-400.woff2               upm 2048   cmap 230   chýba 24
public/fonts/roboto-condensed-400.woff2    upm 2048   cmap 229   chýba 24

chýba: Č Ď Ĺ Ľ Ň Ŕ Š Ť Ž   č ď ĺ ľ ň ŕ š ť ž   Ě Ř Ů   ě ř ů
```

Prvých osemnásť je slovenská sada (mäkké spoluhlásky a dlhé slabikotvorné >
`ĺ ŕ`), zvyšných šesť je česká nadstavba.
`á ä é í ó ô ú ý` tam boli >
tie sú v latin-1 a do `latin` subsetu patria.
Chýbali teda presne tie znaky, ktoré odlišujú slovenčinu od angličtiny >
a to je najhorší možný výber:
text je čitý >
len občas „prepne písmo" a pri skenovaní to vyzerá ako rozbitý layout.

---

## Časť III > Nástroje, ktoré tam boli, a čo naozaj robia

V `/home/evan/dev/10` sú dva python nástroje a zoznam fontov.
Prešiel som ich CLI, aby som vedel, či problém vedia vyriešiť:

| nástroj | vstup → výstup | čo robí |
|---|---|---|
| `font-subsetter/font_subsetter.py` | `--font X.ttf --inputs *.html` | `pyftsubset` wrapper: ttf + woff + woff2 + css |
| `font-subsetter/batch_subsetter.py` | `--inputs sk-cz-test.txt --fonts fonts/*.ttf` | to isté dávkovo |
| `FontGenerator/combine.py` | `COMBINED_CHARSET` (SK+CZ+US) | subset jedného fontu na kombinovanú sadu |
| `FontGenerator/create_font_from_html.py` | jazykové sady `sk-minimal`…`cz-full` | subset podľa vopred definovanej sady |
| `FontGenerator/analyze_font.py` | font → zoznam znakov po blokoch | diagnostika pokrytia |

Všetky štyri „generátory" robia jednu operáciu:
**odoberajú glyfy**.
`pyftsubset` je jednosmerný >
zo vstupu vyberie znaky, ktoré tam sú; ak tam >
nie sú nemá ich odkiaľ vziať.
A ich vlastné fonty sú iné verzie než tie, ktoré Boson používa:

```
fonts/sans-serif/roboto/RobotoCondensed-Regular.ttf   Version 1.100141; 2013
~/Documents/fonts/inter/Inter-Regular.otf             Version 3.019
public/fonts/inter-400.woff2                          Version 4.001;git-66647c0bb
public/fonts/roboto-condensed-400.woff2               Version 3.008; 2023
```

Pustiť `combine.py` na `RobotoCondensed-Regular.ttf` >
by teda vyrobilo *menší* súbor z *inej* verzie rodiny >
a slovenské znaky by v ňom chýbali ďalej, len v menšom balení.
Nástroj nebol zlý; len na tento problém nesedel.

---

## Časť IV > Jednosmernosť subsettingu

Subset je deštruktívna operácia:
vstup `F`, sada `S` → `F|S`.
Nikdy `F ∪ D`.
Preto sa „oprava font setu" nedá spraviť tým istým prístrojom >
ktorý ho pokazil.
Dá sa to len jednou z troch ciest:

1. **Nový subset z plného zdroja** tej istej rodiny a verzie (`F_full|S ∪ SK`).
2. **Transplantácia glyfov** z iného súboru, ktorý ich má (merge).
3. **Druhá rodina s `unicode-range`** : kosmeticky viditeľné, ale bez tofu.

Cesta 1 je najčistejšia, ak plný zdroj existuje v tej istej verzii.
Tu neexistoval:
na disku bolo Inter 3.019 a Roboto Condensed 1.1 >
kým súbory v projekte sú 4.001 a 3.008.
Cesta 3 by znamenala, že v každom slove s diakritikou máme iné písmo.

Zostala cesta 2 >
s podmienkou, ktorá celý zásah drží pokope:

> donor musí byť subset **toho istého buildu**: rovnaká rodina, verzia, `upm`
> a rovnaké obrysy pre glyfy, ktoré majú oba súbory spoločné.

Keď to platí, kopírovanie glyfu nie je „približná náhrada" >
je to presne ten tvar, ktorý by tam bol, keby subset nikdy neodrezal.

---

## Časť V > Ako sa overí „ten istý build"

Dve merania stačili.

**Inter.**
`public/fonts/inter-400.woff2` má md5 `da03732a27e1…` >
a latin subset z Google Fonts má **ten istý md5**.
To nie je „podobný font", to je identický súbor >
takže `latin-ext` subset tej istej rodiny je donor z toho istého buildu.

**Roboto Condensed.**
md5 nesedel (náš súbor je custom subset s 363 znakmi) >
takže sa porovnávali obrysy a metriky:

```
                       náš súbor              GF latin
verzia                 3.008; 2023            3.008; 2023
upm                    2048                   2048
hhea ascent/descent    1900 / -500            1900 / -500
'A' advance            1181                   1181
'g' advance            1010                   1010
'o' advance            1028                   1028
```

Rovnaká verzia, rovnaké `upm` >
rovnaké vertikálne metriky a **rovnaké obrysy aj advance** >
pre spoločné glyfy → donor je ten istý build.

---

## Časť VI > Transplantácia bez zmeny metrík

Merge robil `fontTools` (engine, na ktorom stoja aj interné skripty >
ich CLI na to nemá príkaz).
Postup na jeden súbor:

```python
# 1. chýbajúce znaky: tie, ktoré v cmap nie sú
missing = [c for c in "ÁÄČĎ…žĚŘŮěřů" if ord(c) not in dst_cmap]

# 2. pre každý znak skopíruj glyf z donoru > aj komponenty, ak je zložený
# 3. pridaj advance do hmtx a záznam do každého unicode cmap podtably
# 4. dopočítaj maxp.numGlyphs a hhea.numberOfHMetrics
# 5. ulož ako woff2
```

A to, čo sa **nesmie** stať:
prepočítať `hhea` >
`OS/2` (`xAvgCharWidth`) alebo `hmtx` existujúcich glyfov.
Tie čísla totiž nie sú dekorácia >
Boson má na nich postavený CLS fix:

```
size-adjust       = (webfont avg/upm) / (fallback avg/upm)
ascent-override   = (webfont ascent/upm) / size-adjust
descent-override  = (|webfont descent|/upm) / size-adjust
line-gap-override = (webfont lineGap/upm) / size-adjust
```

`size-adjust: 107.6 %` pre Inter >
a `88.61 %` pre Roboto Condensed sú **vlastnosť fontu**, nie vkus.
Keby merge prepočítal `xAvgCharWidth` >
fallback by prestal sedieť na riadkový box a `font-display: swap` >
by vrátil presne ten reflow, ktorý má odstrániť.
Preto invarianty:

```
head.unitsPerEm              nezmenené
hhea ascent/descent/lineGap  nezmenené
OS/2 xAvgCharWidth           nezmenené
hmtx pre pôvodné glyfy       nezmenené
mená a ID pôvodných glyfov   nezmenené
```

Výsledok po merge:

| súbor | pred | po | cmap | glyfov |
|---|---|---|---|---|
| `inter-400` | 23 664 B | 24 148 B (+484) | 230 → 254 | 518 → 540 |
| `roboto-condensed-400` | 51 412 B | 52 196 B (+784) | 363 → 411 | 363 → 411 |

Dva fonty, **+1 268 bajtov** spolu, a žiadny nový request >
face 600 v Boson CSS smeruje na tú istú 400 URL >
takže obe váhy sú opravené jedným súborom.

---

## Časť VII > Prečo to aj tak nefungovalo

Po merge bolo pokrytie čisté (`missing: none`) >
a headless test v čerstvom Chromiu ukazoval správne šírky.
A predsa:
na obrazovke fallback.
Príčina bola v `public/.htaccess`:

```apache
# Web fonts (1 year, immutable > self-hosted woff2 never changes; a new font
# ships under a new URL).
Header set Cache-Control "public, max-age=31536000, immutable"
```

Prepis bajtov na tej istej URL je presne to, čo tento header zakazuje.
Prehliadač mal starý súbor v cache a pokojne ho používal ďalej >
a keby aj chcel overiť, `immutable` mu povie, že nemá.
Test v studenej cache tento problém **nemôže** odhaliť; to je jediná chyba >
ktorú mi tento postup spočiatku nezachytil.

Oprava preto nie je „prepíš súbor", ale **nová URL s obsahom v názve**:

```
/fonts/inter-400-0c9c05bd.woff2
/fonts/roboto-condensed-400-c05481f9.woff2
```

Staré súbory zostali na svojich URL s pôvodnými bajtmi >
mazanie by znamenalo 404 pre každé HTML >
ktoré má ešte starú URL v cache.
Neostali však len ako mŕtva váha:
`Frontend/packages/griffe/src/styles/fonts.css` ukazoval práve na ne >
takže na griffe stránke prišlo každé `Č` v nadpise `Časť` z fallbacku >
až do novembra.
V novembri ten súbor ukázal na tie isté dve hashované URL >
ktoré Boson používa od októbra.
Súbor `roboto-condensed-400-c05481f9.woff2` potom dostal ešte jednu adresu >
`roboto-condensed-400-99aca383.woff2` >
lebo v ňom chýbal glyf `space` a medzery v nadpisoch kreslil fallback.
Admin ich nikdy nesťahoval >
jeho písmo je Gilroy z `public/fonts/mark`.

---

## Časť VIII > Druhá pasca: nie CSS, ale bundle

Zdrojové `modules/Themes/Boson/assets/critical/boson.css` som mal opravené >
testy zelené >
a stránka aj tak inlinovala staré URL.
Boson totiž pri existujúcom manifeste inlinuje **minifikovaný bundle** >
z `public/dist/boson/`, nie zdrojový súbor:

```
BosonTheme::criticalCss()  →  Modules/Boson/assets/critical/boson.css
FrontendLayout            →  public/dist/boson/assets/boson-*.css   (ak manifest existuje)
```

Bundle sa skladá >
z toho istého zdroja (`Frontend/packages/boson/src/boson.ts` importuje >
`modules/Themes/Boson/assets/critical/boson.css`) >
takže stačilo prebuildiť:

```bash
cd Frontend/packages && pnpm --filter @nativa/theme-boson run build
# → public/dist/boson/assets/boson-BHUC2EPm.css
```

`public/dist/` je gitignored build artefakt.
To znamená dve veci:
v gite je zmena len v CSS a fontoch (správne) >
a na stroji bez buildu sa inlinuje zdrojové CSS >
ktoré je tiež už správne.
Obe cesty teda vedú k opraveným URL, len jedna z nich je minifikovaná.

---

## Časť IX > Dôkaz, ktorý nie je „vyzerá to dobre"

Pokrytie v cmap hovorí, že glyf existuje.
Nehovorí, že ho prehliadač použije.
Preto posledné meranie:
koľko má znak **advance** v prehliadači vs.
v tabuľke fontu.
Ak by prehliadač siahol po fallbacku, šírka by bola iná.

```
                 namerané v Chromiu    advance z fontu     fallback
Inter   'Č'          29.22 px             29.22 px          24.00
Inter   'ľ'          13.59 px             13.59 px            —
RC      'Č'          22.68 px             22.68 px          24.00
RC      'š'          18.24 px             18.24 px            —
RC      'ľ'          12.11 px             12.11 px            —
```

A to isté priamo na nadpise z článku, s computed štýlom:

```
h2      "Časť XI — Čo z toho zostáva"
font    600 25.6px "Roboto Condensed"
Č       15.03 px   =  1161/2048×25.6 (14.51) + 0.52 syntetický bold
fallback            15.36 px
```

Tých 0.52 px je syntetický bold >
face 600 je v skutočnosti 400 súbor, takže prehliadač priťahuje obrysy.
To je vlastnosť, ktorá tam bola aj predtým >
nie je to regresia a nie je to predmetom tejto opravy.
Podstatné je, že 15.03 nie je 15.36 a že sedí na advance z fontu.

---

## Časť X > Čo by mal nástroj vedieť, aby toto robil sám

Interné skripty sú subsettery a to je v poriadku >
len ich CLI končí pri `F|S`.
Chýbajúci režim je malý a presne ohraničený:

```bash
font_subsetter.py \
  --font Inter-Regular.otf \
  --repair public/fonts/inter-400-0c9c05bd.woff2 \
  --donor inter-latin-ext.woff2 \
  --charset sk-cz-test.txt \
  --out public/fonts
```

Čo by musel urobiť, aby nevyrobil tichú chybu:

```
1. overiť, že donor a cieľ sú z toho istého buildu  (verzia, upm, obrysy, advance)
2. skopírovať len chýbajúce znaky (+ komponenty)
3. assertnúť invarianty metrík: head, hhea, OS/2, hmtx pôvodných glyfov
4. zapísať woff2 pod názov s content-hashom  (cache v .htaccess je immutable)
5. vypísať cmap pred/po a zoznam pridaných znakov
```

Bez bodu 1 je to hazard >
bez bodu 3 tichá CLS regresia a bez bodu 4 to neuvidí nikto >
kto má starý súbor v cache >
teda presne ten človek, ktorý to hlásil.

---

## Časť XI > Zhrnutie v piatich vetách

Subset je jednosmerný >
takže oprava vyžaduje buď plný zdroj tej istej verzie >
alebo donor z toho istého buildu.
Transplantácia glyfov je presná len vtedy, keď sa overí verzia >
`upm` a obrysy >
inak je to nový font s cudzími znakmi.
Metriky sa nesmú prepočítať >
inak sa rozpadne metricky zladený fallback a vráti sa CLS >
ktorý má `size-adjust` odstrániť.
Fonty sa v tomto projekte servírujú immutable na rok >
takže opravený font **musí** prísť na novej URL.
A testovať treba aj to, čo sa nesťahuje z disku:
bundle, cache a to >
ktorý súbor prehliadač naozaj použil.
