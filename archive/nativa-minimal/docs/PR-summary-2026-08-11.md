# PR: Blog search, Clean code série a DRY refaktoring

**Dátum:** 2026-08-11
**6 commitov · 28 súborov · +2302/−74 riadkov**

## Prehľad

Táto séria uzatvára blog feature set starter kitu: **full-text search**,
**záverečnú učebnú sériu článkov** a **DRY refaktoring** duplicitnej logiky —
všetko prešlo quality gates, dist buildom aj vizuálnym overením.

## Feature: Full-text search (`/blog?q=`)

| Súbor | Zmena |
|---|---|
| `BlogSearchService.php` | **nový** — in-memory SQLite FTS5 index z `storage/blog/` na každý request |
| `BlogIndexAction.php` | `?q=` handling, search box, empty state |
| `BlogArticlesService::bySlugs()` | výsledky FTS → karty v rank poradí |
| `FrontendLayout.php` + `styles.css` | `.st-blog-search` CSS (built + critical parita) |
| `BlogSearchTest.php` | **nový** — 12 testov |

**Kľúčové vlastnosti:**

- **Bezpečná sanitizácia** — query sa rozdelí na slovné tokeny obalené ako
  `"token"*`; FTS5 operátory (AND/OR/NOT, úvodzovky) sa nikdy nedostanú do MATCH
- **Slovenčina** — unicode61 fodlí diakritiku (`cisty` nájde „čistý")
- **Prefix matching** + **bm25 ranking** (title=10 > excerpt=5 > tags=3 > body=1),
  limit 20
- **Zero-dep** — žiadna migrácia, konzistentné s „no generated maps"

## Content: „Clean code pre deti" séria (6 dielov)

Kompletné učebné články na reálnych príkladoch z repo, každý vynútený
architektúra testom:

| Diel | Princíp |
|---|---|
| 1 | Čo je čistý kód — príbeh nad detailom |
| 2 | Názvy sú dokumentácia |
| 3 | Jedna vec (SRP) — metóda aj trieda |
| 4 | DRY vs predčasná abstrakcia (baseline 0) |
| 5 | Smoke testy — tokeny, nie text |
| 6 | Modulová izolácia — C-pattern hranice |

## Refaktor: DRY fix `resolveCover()`

`BlogArticleAction` mal skopírovanú cover logiku aj s natvrdo `% 4` namiesto
`count()` — dve miesta pravdy, ktoré by sa pri 5. obrázku rozišli. **Fix:**
`BlogArticlesService::resolveCover()` sa stal jediným zdrojom pravdy (public),
action deleguje cez `new BlogArticlesService($resolver)` (reuse — žiadna nová
závislosť). **Regresný test** `BlogCoverTest` stráži paritu index/článok.

## OpenSpec workflow

- 2 archivované changes: `clean-code-series` (+1 requirement),
  `blog-fts-search` (+3 requirements) → canonical spec **48 requirements**
- docs sync: AGENTS.md + ROADMAP (nové milníky)

## Verifikácia

- **209 testov / 503 assertions** OK (vrátane 12 FTS + 3 cover parity)
- PHPStan level 9 · CS-Fixer · Rector — 0 chýb
- `make dist` + `make build` — verify-build 4/4 PASS (es2015, no `@layer`,
  `@supports` fallbacky, self-hosted)
- Overené v prehliadači: `/blog`, `/blog?q=smoke|cisty|chunky`, články
  s cover obrázkami — **0 console errors**

## Commity

```
649d0bc docs: sync AGENTS.md + ROADMAP with blog features and content series
43b502f docs(openspec): archive clean-code-series + blog-fts-search, sync canonical spec
ddd5a58 refactor(blog): DRY fix — resolveCover() žije len v BlogArticlesService
5466807 feat(blog): full-text search na /blog?q=… cez in-memory SQLite FTS5
5c7828c docs(blog): clean code series diel 5-6 — smoke testy a modulová izolácia (séria kompletná)
4d427e3 docs(blog): clean code series diel 3-4 — SRP a DRY vs predčasná abstrakcia
```
