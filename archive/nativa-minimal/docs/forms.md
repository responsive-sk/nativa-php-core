# Forms — contact, poll and quiz

`Modules\Forms` is the kit's bounded context for everything a visitor fills
in and an admin reads back. It has its own `forms.db` and is deliberately not
a comment with a different label:

- a **contact form is a schema**, not one free-text blob — the admin edits
  its fields without a deploy;
- a **poll stores one row per selected option**, so results are a `GROUP BY`,
  not a parse of a JSON string;
- a **quiz also stores a score** and shows it back to the visitor;
- **responses are delivered, not moderated** — the lifecycle is
  `new → read → archived/deleted`, and the inbox is a reading surface, not an
  approval queue.

## Authoring a form

`/mark/forms` lists every definition, filtered by locale and type. Create and
edit share one action (`FormEditorAction`), so the two can never drift into
rendering different fields.

| Type | Renders | Stores |
|------|---------|--------|
| `contact` | typed fields (text, email, textarea, select) | one row per field answer |
| `poll` | one question per row, radios or checkboxes | one row per **selected option** |
| `quiz` | like a poll, with per-question correctness | answers plus `score` / `max_score` |

Status is `draft` / `published` / `closed`. Only a `published` form renders
publicly **and** accepts submissions — a draft or a closed form is a 404 on
both paths, not a silent accept, because a visitor must never believe a
message was delivered when it was not.

Changing the type is a server round-trip: the field rows and the question rows
have genuinely different input names, so duplicating them in JavaScript would
mean two editors to keep in step. `GET /mark/forms/type-switch` returns just
the rows fragment for the chosen type. Both halves share one partial
(`form-editor-rows.phtml`), so the page and the switch cannot render different
markup. The rows it returns are blank — whatever was typed belonged to the
shape the form no longer is.

Only a contact form has fields, so saving the question half clears the field
half. Otherwise a form switched from contact to poll would keep the old fields
attached, surfacing as permanently-empty columns in the submissions inbox and
the CSV export.

### One form per locale

The `locale` column makes the Slovak and English forms genuinely separate
rows with separate slugs (`kontakt` / `contact`). A shared slug is free — the
uniqueness constraint is per locale — and neither locale ever resolves to the
other's definition.

## Embedding a form

Two insertion paths converge on one expansion mechanism, both handled by the
markdown block registry (`#[AsMarkdownBlock]`, in Core):

**In the body** — the shortcode, anywhere markdown renders:

```markdown
Píšte nám, ak máte nápad.

{{form:kontakt}}

A shortcode inside a fenced code block is NOT expanded — a page documenting
the syntax keeps showing `{{form:...}}` literally.
```

**In the front matter** — the whole form below the content:

```yaml
---
title: "Anketa"
form: anketa-tema
published: true
---
```

`PageViewAction` and `BlogArticleAction` append the placeholder for the
`form:` key. They know only the *key*, never `Modules\Forms` — that is what
the block registry buys: a feature can appear inside content without Core,
Pages or Blog importing the feature module.

A shortcode that resolves to nothing — a typo, a draft — vanishes silently.
The visitor sees no raw `{{…}}` and no error; a draft in progress is
invisible.

## The theme surface

**There isn't one.** The block renders under the ACTIVE THEME'S PREFIX —
`st-form`, `er-form`, `ms-form`, `mag-form` — which is the same contract
`CommentSection` has (erik ships `er-comments`). `FormBlockRenderer` takes
the prefix as an argument; the module never imports `Themes\`, so a form
works in every theme without a single theme-side change.

The four stylesheets are generated from one canonical file:

```
Frontend/packages/starter/src/form.css   # edit only this one
        │  node bin/generate/generate-form-css.mjs
        ├── Frontend/packages/erik/src/erik/form.css
        ├── Frontend/packages/moonshine/src/moonshine/form.css
        └── Frontend/packages/magazine/src/magazine/form.css
```

`FrontendFormParityTest` asserts the selector sets stay identical, that no
copy carries a foreign prefix, and that each prefix still equals its
`ThemeModule::prefix()`. A theme swaps a `--form-*` palette block and
inherits the geometry; the WCAG contracts that matter are the two inks a
theme genuinely chooses for itself — `--form-error-ink` and `--form-hint`.

## Submitting

`POST /forms/{slug}`, locale-prefixed (`/forms/kontakt`, `/en/forms/contact`)
because the action resolves the form *in the request locale*.

The response is POST-redirect-GET back to the referring page. The two markers
— `form-sent` and `form-invalid` — **must differ**, and they are both in
`PageCacheService::BYPASS_QUERY_PARAMS`. The page cache keys on the full
query string; a failed submit that redirected to a bare `/contact` would hit
the same cache entry as the clean page, and every visitor would get their
form back silently reset with no error shown. That HTML is session-scoped,
and the cache is shared.

The Referer decides where the visitor returns to, so it is attacker-controlled
input: only a same-origin relative path is accepted, which keeps the redirect
from becoming an open redirect.

## Spam posture

Three layers, cheapest first. None of them is a CAPTCHA, and none of them
promises to stop a determined attacker:

1. **Honeypot** — a field a human never sees (native `hidden`, so no screen
   reader and no keyboard user meets it). Filled ⇒ the submission gets the
   **same silent success** a real one gets, so the bot learns nothing. The
   unit test asserts nothing is stored.
2. **Per-IP rate limit** (`Modules\Security\Application\Services\RateLimiter`,
   the same one comments use) — caps the volume a single address can push.
3. **CSRF** — enforced globally by the middleware; the form carries the
   field.

Every admin write is audited, and the page cache is flushed on each submit so
a cached page cannot serve pre-vote results.

## Delivery

**The inbox and the CSV are the only channels. There is no e-mail and no
webhook** — that is a deliberate scope decision, and it is why the export
cannot be lossy.

`/mark/forms/{id}/submissions` is the reading surface: new/read counters,
per-definition answer columns, pagination. `GET /mark/forms/{id}/submissions.csv`
is a plain GET (a spreadsheet's "download" and a `curl` both work) with:

- a **UTF-8 BOM**, because Excel on Windows reads a bare UTF-8 CSV as
  latin-1 and turns every Slovak diacritic into mojibake;
- **one row per submission, one column per question**, because that is the
  shape a spreadsheet wants — storage ids resolved back to admin labels, a
  multi-answer question joined with `; ` into one cell;
- **formula-injection neutralising**, because every value is
  visitor-supplied. A leading `=`/`+`/`-`/`@` is a formula to Excel, and
  **quoting is not enough** — Excel strips the quotes on import and evaluates
  it anyway. The value is prefixed with an apostrophe, which the spreadsheet
  keeps as text.

## Commands and checks

```
php bin/cms forms:seed        # four demo forms + the /anketa and /test pages
node bin/checks/forms-check.mjs      # the public submit flow over real HTTP
node bin/checks/forms-admin-check.mjs# the /mark/forms surface
vendor/bin/phpunit --filter Forms
```

`forms:seed` is its own command rather than a branch in `bin/cms seed`: the
seed command lives in Core, and Core must not import a feature module.