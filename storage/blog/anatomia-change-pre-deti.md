---
title: "OpenSpec pre deti ... diel 2: anatómia change ... päť súborov, päť otázok"
date: "2026-08-09"
version: "1.0.2"
tags: ["openspec", "php", "workflow"]
excerpt: "Rozoberieme archivovaný change 2026-08-09-blog-author-linking súbor po súbore. Každý súbor odpovedá na jednu otázku ... a spolu tvoria príbeh jednej zmeny."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "anatomy-of-a-change"
published: true
---

Keď niekto vraví „zmena v projekte“, myslí tým commit. Ale commit je len posledný krok, pred ním je návrh, rozhodnutia, testy a overenie. Ako vyzerá celá cesta?

> Toto je diel 2 série **OpenSpec pre deti**. V [dieli 1](/blog/co-je-openspec-pre-deti)
> sme si povedali, čo je OpenSpec a prečo sa zmeny nepíšu priamo do spec. Dnes
> otvoríme skutočný archivovaný change a pozrieme sa, čo je v každom súbore a
> prečo. V [dieli 3](/blog/zivotny-cyklus-change-pre-deti) potom prejdeme celú
> cestu od nápadu po archív.

Bez štruktúry sú zmeny chaos. Jeden súbor s popisom, druhý s kódom, tretí nikde. O pol roka sa zabúda, prečo sa niečo robilo tak a nie inak.

## Otvárame zásuvku archívu

V `openspec/changes/archive/` leží osem archivovaných zmien. Vyberieme si tú
najčerstvejšiu ... `2026-08-09-blog-author-linking`. Bol to malý change:
prepojenie autora článku s reálnym users účtom. Presne typ zmeny, ktorý
vyzerá na „tri riadky kódu“ ... a predsa má päť súborov:

```text
openspec/changes/archive/2026-08-09-blog-author-linking/
├── .openspec.yaml        # 1. ČO to je?
├── proposal.md           # 2. PREČO to robíme?
├── design.md             # 3. AKO presne?
├── tasks.md              # 4. ČO KONKRÉTNE treba spraviť?
└── specs/starter-kit/    # 5. AKO OVERÍME, že to funguje?
    └── spec.md
```

Každý súbor odpovedá na jednu otázku. Nikto z nich nie je zbytočný a každý sa
dá prečítať sám, bez zvyšku.

## 1. `.openspec.yaml` ... ČO to je?

Meta súbor. Hneď na prvý pohľad povie, čo je to za zmenu, kedy vznikla a kam
patrí:

```yaml
schema: spec-driven
created: 2026-08-09
goal: >-
  Link blog article authors to real user accounts. Articles stay plain .md
  files ... a new optional author_email key is resolved at render time
  against users.db ...
```

`goal` je **jeden odstavec, ktorý musí prežiť aj o rok**. Keď o pol roka
niekto uvidí v archíve neznámy change, prečíta si goal a hneď vie, o čom bol.
Nič viac v tomto súbore nie je ... a ani nemá byť.

## 2. `proposal.md` ... PREČO to robíme?

Tu sa začína každý change. Najprv pôvodná požiadavka (citát z konverzácie),
potom prečo to vôbec riešime a hlavne ... čo zámerne NEROBÍME:

```text
# Proposal: Blog article authors linked to real user accounts

## Original Request   → citát: „Ako by sme prepojili autora článku so
                         skutočným users účtom?"
## Why                → autor bol voľný text: rename usera sa nepremietol,
                         delete zanechal mŕtvu atribúciu
## Approach           → voliteľné author_email vo front matter + resolver
## Non-goals          → žiadne autorské stránky, žiadny admin picker,
                         súbory zostávajú zdrojom pravdy
## Security           → prepared statement, email sa nikdy nerenruje
## Plan settings      → testing: áno, logging: nie, docs: áno
```

**Non-goals sú najdôležitejšia sekcia.** Hovoria, čo sme zámerne nespravili ...
a prečo. Bez nich by sa každý change postupne nafúkol: „keď už robíme autora,
pridajme aj autorskú stránku… a avatar… a komentáre“. Non-goals držia change
malý a ukončený.

## 3. `design.md` ... AKO presne?

Tu sa rozhoduje o technických detailoch. Pre toto repo najdôležitejšie
rozhodnutie znelo: **prepojíme autora cez email, nie cez user id**. A prečo?

```text
## Why email, not user id

- make db-reset a dist build regenerujú KAŽDÉ user id ako nový random hex
  → id v committed súbore by nikdy nesedelo na čerstvú databázu
- emaily sa seedujú konzistentne (admin@example.test) a sú unikátne
- email sa dá prirodzene napísať rukou do .md súboru
```

Toto je presne ten druh poznatku, ktorý sa v kóde nevidí ... ale v design.md
prežije. Dizajn obsahuje aj reťazec rozhodovania (fallback chain), tabuľku
hraničných prípadov a jedno architektonické rozhodnutie:

```text
Kam s kódom? Blog žije v Modules\Core ... shared kernel. Importovať odtiaľ
Users modul by otočilo smer závislostí. → resolver používa shared kernel
(Database) s vlastným prepared statementom, žiadny import Users.
```

Dizajn nie je esej ... je to **záznam rozhodnutí a ich dôvodov**.

## 4. `tasks.md` ... ČO KONKRÉTNE?

Checklist s presnými cestami k súborom. Každá úloha je overiteľná a viete,
kedy je hotová:

```text
## Implementation
- [x] src/Modules/Core/Application/Services/BlogAuthorResolver.php (nový)
- [x] BlogIndexAction + BlogArticleAction ... napojiť resolver
- [x] storage/blog/hello-world.md ... dokumentácia konvencie

## Tests
- [x] BlogAuthorResolverTest (6 testov: aktívny/neznámy/neaktívny user…)
- [x] BlogIndexTest ... fixture článok s author_email

## Quality gates
- [x] vendor/bin/phpunit ... 172 testov OK
- [x] composer phpstan, cs-fix-dry, rector-dry ... čisto

## Commit plan
- Commit 1: implementácia · Commit 2: testy · Commit 3: docs a článok
```

Všetko odškrtnuté = change je hotový. `tasks.md` je zároveň aj **dôkaz pre
verifikáciu** ... nikto nemusí hádať, či sa niečo zabudlo.

## 5. `specs/starter-kit/spec.md` ... AKO OVERÍME, že to funguje?

Srdce OpenSpec. Nová požiadavka pre canonical spec, zapísaná ako overiteľné
scenáre ... nie ako „treba to spraviť“:

```text
### Requirement: Blog article authors linked to user accounts

#### Scenario: Author resolves to the linked user's name

- GIVEN článok s author_email: eva@example.test a aktívny user
       s menom Eva Autor
- WHEN sa vyrenderuje /blog alebo /blog/{slug}
- THEN sa zobrazí meno Eva Autor
```

Štyri scenáre pokrývajú štyri svety: user existuje, user neexistuje/je
neaktívny, email sa nikdy nezobrazí, databáza chýba. Každý scenár sa dá
**spustiť ako test** ... a naozaj sa spúšťa: `BlogAuthorResolverTest` testuje
presne tie isté prípady.

Päť súborov, päť otázok. Ale všimnite si poradie: **najprv prečo, potom ako,
potom overenie, až nakoniec čo.** Spec a testy vznikajú skôr, než sa napíše
prvý riadok kódu. Preto ten tri riadky kódu vyzerajú takto:

```php
// BlogAuthorResolver ... jediná metóda, ktorá celý príbeh uzatvára
public function resolve(string $email, string $fallback): string
{
    try {
        $stmt = $this->manager->connection('users')->prepare(
            'SELECT name FROM users WHERE email = :email COLLATE NOCASE
             AND is_active = 1 LIMIT 1',
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
    } catch (\PDOException) {
        return $fallback;   // users.db neexistuje? Blog musí renderovať vždy
    }

    // hit → meno z databázy, miss → fallback na author
    return is_array($row) && is_string($row['name'] ?? null) && '' !== $row['name']
        ? $row['name']
        : $fallback;
}
```

Malý kód, veľký príbeh. A príbeh je celý zapísaný v piatich súboroch ...
aby ho o rok vedel prečítať ktokoľvek. V [dieli 3](/blog/zivotny-cyklus-change-pre-deti)
si ukážeme, ako sa príbeh odohráva v čase: od nápadu, cez implementáciu a
quality gates, až po presun do archívu. 

## Zdroje

- [OpenSpec](https://openspec.dev/): oficiálna dokumentácia OpenSpec workflow
- [Conventional Commits](https://www.conventionalcommits.org/): konvencia commit správ spomínaná v `tasks.md`
- [Diel 1, čo je OpenSpec](/blog/co-je-openspec-pre-deti): začiatok série
- [Diel 3, životný cyklus change](/blog/zivotny-cyklus-change-pre-deti): pokračovanie série
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
