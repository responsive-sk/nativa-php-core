---
title: "Luanti, Lua a Rust ... tri jazyky, jedna hranica"
date: "2026-09-17"
version: "1.0.2"
tags: ["luanti", "lua", "rust", "architektura", "games"]
excerpt: "Voxelová hra je architektonická lekcia s GUI. Jadro v C++ neruší nič, mody v Lua extensibilitu, map.sqlite drží svet živý medzi sessions ... a každá z troch zón vie presne, čomu verí. Toto repo hrá tú istú hru inými jazykmi — od deterministického semena až po stav mimo kontextu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "luanti-lua-and-rust"
published: true
---

Si vývojár. Spustíš voxelovú hru ... Luanti (dávnejšie Minetest), kocky,
kopeš, staviaš. A mozog ti hovorí „hra“, lebo vyzerá ako hra.

Nemýliš sa úplne. Je to hra, ktorá je súčasne architektonická lekcia
s GUI.

Pod kapotou žijú tri jazyky a každý má presne vymedzenú zónu, čomu
verí. Ten istý vzor, ktorý tento repozitár používa pri blogu, testoch
a agentoch ... len s kockami.

## Tri jazyky, tri zóny dôvery

Luanti nie je napísané v jednom jazyku. Je to rozdelenie práce, ktoré
vyzerá ako učebnica hraníc:

| vrstva | jazyk | čomu verí |
|---|---|---|
| engine — rendering, sieť, meshing | C++ (kompilované) | nikomu — vstup prichádza zo siete |
| mody — logika sveta, pravidla, bloky | Lua (sandbox v LuaJIT) | len API, ktoré engine vystaví |
| svet — bloky, hráči, inventáre | `map.sqlite` | bajty na disku |

Jadro je kompilované a natívne, lebo musí byť rýchle — kresliť tisíce
kociek, meshovať chunky, tlačiť pakety. Nič sa tam neremontuje za behu.

Mody sú v Lua, lebo rozšíriteľnosť chce iný jazyk — dynamický, bez
kompilačného kroku, spustiteľný hneď. Ale nepustia ich do pamäte sveta.
Mody volajú len API, ktoré im engine podá. Hranica nie je slovo ...
je to zoznam schopností.

A svet samotný neleží v pamäti ani v jazyku. Leží v SQLite.

## Sveta z rovnakého semena

Najkrajšia vec na Luanti je generácia. Z jedného semena vzniká rovnaký
svet — dnes, o rok, na inom počítači. Seed je kontrakt: rovnaký vstup,
rovnaký výstup, byte-deterministická realita.

Toto repozitár robí to isté s obálkami. `CoverGenerator` hashuje identitu
(`series:` alebo slug) do (template, palety) páru — rovnaký článok vydá
rovnaké SVG, dvakrát, vždy:

```php
// src/Modules/Blog/Application/Services/CoverGenerator.php — identita
$seed = null !== $series && '' !== trim($series) ? $series : $slug;
$index = abs(crc32($seed)) % count(self::IDENTITIES);
```

Seed sveta, seed obálky. Žiadne časové pečiatky, žiadne náhody
... determinizmus je vlastnosť, nie bonus.

A `map.sqlite`? Toto repo má vlastnú rodinu — `users.db`, `config.db`,
`audit.db`. Svet žije na disku, nie v relácii. Hráč sa odhlási, klient
zavrie, svet zostáva. Presne ten istý zákon sme si napísali v článku
o [stave mimo kontextu](/blog/stav-mimo-kontextu): stav nežije v okne.
Žije v súboroch.

## Sandbox nie je dôvera

Pozor na jedno časté nedorozumenie.

Lua sandbox v Luanti neexistuje preto, že by engine modom nedôveroval
v úmysle. Mody inštaluje admin servera — sú to hosťovaný kód, ktorý
niekto schválil.

Sandbox je **zoznam schopností**. Mód smie to, čo mu API podá — čítať
bloky, meniť inventáre, posielať správy. Nesmie narušiť proces,
pretože proces vôbec nevystavuje. Hranica teda nie je „nedôverujeme ti
v srdci“ ... je to „dávame ti presne tieto ruky“.

Toto repo stavia hranice rovnakým spôsobom — cez schopnosti, nie cez
nádeje. Upload neverí menu súboru, pýta sa bajtov (`finfo`). Komentár
začína ako pending a čaká na moderáciu. Agent číta revíznu správu
z git content modelu, lebo mtime je falosná stopa. Každá z týchto
hraníc je zoznam, čo vstup smie ... nie modlitba, že to neurobí niečo
iné.

## Kedy Lua nestačí (a kde vstupuje Rust)

Lua v Luanti má svoj strop. Hot loop — tisíce iterácií cez stromy
blokov — trávi čas na prechode cez API hranicu a v interpreteri.
Engine preto časom presúva horúce časti k sebe, do natívneho C++.

Vo svete web vývoja je mapa rovnaká, len mená sa vymenia:

| práca | jazyk tu | prečo |
|---|---|---|
| request dnu, HTML von | PHP (tento kit) | shared-nothing, rýchle iterácie |
| dlhobežný analyzer, compiler, CLI | Rust (Lekalo) | kontrakty v čase kompilácie, determinizmus |
| rozšíriteľnosť, skriptovanie | Lua/... | dynamika bez kompilácie |

PHP je tu engine pre webový request — presne tak, ako C++ je engine
pre render. Rust netlačí do jadra webovej appky; žije v toolingu,
kde sa oplatí sila kontraktov (diel 1 série o tom napísal celú sekciu
[Pravda nesie typ](/blog/preco-rust-pre-deti)). A Lua má svoje miesto
tam, kde chceš dynamiku — len si musíš priznať cenu: interpreter
a hranica.

Tri jazyky nie sú chaos. Sú tri odpovede na tri otázky: **ako rýchlo**,
**ako bezpečne rozšíriteľné**, **ako dlho vydrží pravda**.

## Kedy to neplatí (a povinná úprimnosť)

Buďme úprimní: toto repo neembeduje Lua a nebude.

Žiadna šablónovací jazyk navyše, žiadny plugin runtime — filozofia
zero-dependency znamená, že hranice sa riešia procesmi a testami,
nie ďalším interpreterom. Luanti si Lua berie, lebo je to hra
pre tisíce modov. Tento kit mod ekosystém nemá.

Druhá úprimnosť: Luanti nie je ukážka bezpečnostného modelu proti
nepriateľskému kódu. Mód v sandboxe je dôveryhodný hosť s obmedzenými
rukami — proti nepriateľskému kódu sa striehne inde (a aj toto repo
rieši cudzí vstup v komentároch a uploadoch, nie v pluginoch).

A tretia: „Rust by spravil engine lepší“ je tvrdenie bez typu. Engine
C++ tu beží desaťročie a má za sebou celú históriu. Jazyk nie je
argument sám o sebe — kontrakt je.

## Prakticky

1. **Stiahni Luanti a pozri sa na mod** ... `init.lua` akéhokoľvek
   módu je zoznam volaní do API — čítaj ho ako zoznam schopností,
   nie ako kód.
2. **Nasaj svet zo semena** ... vytvor nový svet s rovnakým seedom
   dvakrát. Potom pusti `bin/cms generate-images` dvakrát a skontroluj
   `git diff` — determinizmus má v tomto repe test, nie dojem.
3. **Nájdi hranicu v svojom projekte** ... jedna otázka: ktorý vstup
   dnes verí viac, než by mal? Tam tvoj sandbox chýba.

Tri jazyky potom nie sú hra ... sú mapa, kto čomu verí.

## Zdroje

- [Luanti — oficiálna dokumentácia](https://docs.luanti.org/) ... engine, modding API, world formát
- [Lua 5.1 — Reference Manual](https://www.lua.org/manual/5.1/) ... jazyk, ktorý engine embeduje
- [LuaJIT](https://luajit.org/) ... JIT kompilátor Lua, na ktorom bežia mody
- [The Rust Programming Language](https://doc.rust-lang.org/book/) ... kontrakty v čase kompilácie
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
