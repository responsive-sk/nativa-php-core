---
title: "Dva tóny ... písanie v EN a SK bez straty duše"
date: "2026-08-29"
version: "1.0.1"
tags: ["writing", "narrative-rhythm", "technical-writing", "blog", "bilingual"]
excerpt: "EN je Steinbeck, priame, aktívne, každé slovo pracuje. SK je Dostoevskij, hlbšie, reflektívne, širší kontext. Rovnaký rytmus, iná duša."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Writing"
translation: "rhythm-part-5-bilingual"
published: true
---

Prečítajte si dve verzie toho istého úvodu:

```markdown
# EN
Click page 3. The pager doubled.
The code was correct. Or so it seemed.

# SK
Kliknite stránku 3. Pager sa zdvojnásobil.
Kód bol správny. Alebo sa tak zdalo.
```

Rovnaká štruktúra. Rovnaký rytmus. Iná duša. EN verzia je priama. SK verzia má mierne širšiu pauzu, „alebo sa tak zdalo“ nesie väčšiu váhu než „or so it seemed.“ To je rozdiel medzi Steinbeckom a Dostoevským.

## EN: Steinbeck direct

EN technické písanie nasleduje Steinbeckovu hospodárnosť: každé slovo musí pracovať. Žiadne výplne. Žiadne „it is important to note that.“ Aktívny rod. Krátke vety.

```markdown
# Zle EN
It should be noted that the caching mechanism
is not suitable for user-specific data.

# Dobre EN
Cache has no business with user data.
```

Dobrá verzia má 7 slov. Zlá má 15. Obe hovoria to isté. Dobrá hovorí s rešpektom k časovej investícii čitateľa.

Pravidlá pre EN:
- Aktívny rod všade: „Server returns“ nie „The server is returning“
- Žiadne „it is important“, povedz „Important:“
- Krátke vety, minimum prídavných mien
- Code komentáre v EN
- Téma: ľudská, nie akademická

Oprava: nahraďte pasívne aktívnym, vyhoďte výplne, dôverujte čitateľovi, že pochopí bez vodítok.

## SK: Dostoevsky depth

SK technické písanie povoľuje širší kontext, širšie súvislosti, mierne reflektívny tón. Fáza noci môže byť dlhšia. Téma môže niesť filozofický podtón.

```markdown
# Zle SK (priamy preklad z EN)
Cache nemá čo robiť s user datami.

# Dobre SK
Cache zostáva dobrým sluhom.
Chyba je nechať ho stať sa pánom.
```

SK verzia pridáva metaforu, „sluha/pán“, ktorú EN nepotrebuje. SK čitatelia očakávajú tú extra vrstvu. Nie je to objemnosť, je to hĺbka.

Pravidlá pre SK:
- Širší kontext, viac reflektívna fáza noci
- Code komentáre v SK (ak je článok SK)
- Téma: ľudská, s filozofickým podtónom
- Metafory sú vítané (ak pridávajú význam)
- „Alebo sa tak zdalo“ funguje lepšie než „Or so it seemed“

## Frontmatter kontrakt

Oba zdieľajú `translation` kľúč:

```yaml
# EN verzia
translation: "rhythm-part-5-bilingual"

# SK verzia
translation: "rhythm-part-5-bilingual"
```

Blog engine používa tento kľúč na prepojenie oboch verzií. Slug zostáva rovnaký. Názov a excerpt sa menia. Rytmus zostáva.

## Rovnaká štruktúra, iný hlas

Tu je plná sekcia vedľa seba:

```markdown
# EN — HMLA fáza
We had a finished, tested, secure system.
PasswordHasher used PASSWORD_ARGON2ID.
Everything worked on development, in Docker, on VPS.

Then came shared hosting.

First login attempt: server crashed.

# SK — HMLA fáza
Mali sme hotový, otestovaný, bezpečný systém.
PasswordHasher používal PASSWORD_ARGON2ID.
Všetko fungovalo na vývoji, v Dockeri, na VPS.

Potom prišiel shared hosting.

Prvý prihlasovací pokus: server spadol.
```

Identická štruktúra. Identický rytmus. Iný jazyk. SK verzia má mierne dlhšie slová, to je normálne. Rytmus (krátke, krátke, krátke, pauza, krátke) zostáva.

## Kedy písať čo

Píšte EN keď:
- Publikum je medzinárodné
- Článok je súčasťou EN série
- Chcete Steinbeck directness

Píšte SK keď:
- Publikum je slovenské/české
- Článok je súčasťou SK série
- Chcete Dostoevsky depth

Píšte oba keď:
- Projekt je bilinguálny (ako Nativa Minimal)
- Chcete maximálny dosah
- `translation` kľúč ich spája

---

Jazyk mení tón, nie pravdu. Rytmus zostáva. Duša sa prispôsobí.

---

→ [Part 6, Rhythm Profiles](/blog/rhythm-part-6-profiles): výber rytmu pre bug story, tutorial a filozofiu
→ [Part 4, Tempo](/blog/rhythm-part-4-tempo): prečo dĺžka vety je dizajnové rozhodnutie

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
