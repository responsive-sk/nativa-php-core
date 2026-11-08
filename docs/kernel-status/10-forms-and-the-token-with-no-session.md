# Part 10 > the form nobody owned, and the token with no session

**Boot 47.** One round, one question: *a page has said `form: contact` in its
front matter since the module catalogue was imported, so what actually answers
at that address?* Nothing did. The key was a string the page editor copied into
YAML and the theme printed a heading for; there was no definition, no endpoint,
no inbox and no owner of the word "required". This round gives it one, and the
gate that proves it caught a defect in its own first draft.

## What was read first

`docs/pages.md` was the contract that had to survive: `form` is a front-matter
key whose value is a slug. It said nothing about where the slug resolves,
because nothing did. The other readings were the shapes this module had to
match rather than invent:

| read | what it settled |
|---|---|
| `modules/Questions` | the public write posture: honeypot before lookup, per-IP window before any write, refusal with no `Location` |
| `Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockInterface` | a block cannot read the request, so the page action hands it `path` and a `blockState` |
| `Frontend/packages/starter/src/form.css` | the class set is the theme's, prefixed at render (`je-`, `st-`), and no class may be emitted that the stylesheet does not name |
| `Modules\Pages\…\PagesCreateAction` | the front-matter writer: an empty value must remove the key, and an edit that does not submit it must leave it alone |

## What changed

**A catalogue module** at `modules/Forms/`, 28 runtime PHP files and 3 178
lines, importing `Modules\Core\*`, `Modules\Pages` and `Modules\Blog` and
nothing else. The dependency is one-way and declared: `FormSubmitAction`
re-renders the embedding subject on a validation failure, so a reader's words
survive and there is one renderer per surface. `module:show Forms` prints that
closed set.

**Two tables and nine routes.** `forms` is unique over `(slug, locale)`;
`form_submissions` attaches by `form_id`, so a rename keeps its answers and a
delete takes them in one explicit transaction. Eight of the routes sit behind
`/mark/forms`, which the existing `AdminRoutePolicyCoverageTest` classifies
through its prefix; the ninth is `POST /forms/{slug}`, the only public write
this round added.

**A schema that owns its vocabulary, as an enum.** `FormFieldType` was first a
class of string constants, and that was a defect the ownership gate measured
immediately: `TEXT = 'text'` and `EMAIL = 'email'` charged 182 unrelated places
in the tree (mail settings, user records, theme assertions) as re-spellings of
a value this module owns. The vocabulary is a backed enum now, so a case owns
its token without claiming an English word, and every limit stays a constant on
`FormSchema`: 20
fields, a key matching `[a-z0-9][a-z0-9_-]{0,39}`, three reserved keys
(`form_return`, `form_website`, `_token`), 10 options of 80 characters, a
length ceiling of 5 000. A checkbox is one consent statement and may not
declare options, because every other type answers exactly one string and the
result bars divide by the submissions that answered.

**The placeholder got an owner.** `{{form:<slug>}}` expands through
`#[AsMarkdownBlock(priority: 60)]`, after the content is already HTML, so
neither `Pages` nor `Blog` imports `Forms`. A draft, an unknown slug, an
unparseable schema and a missing table each render `''` and log one line.

**The page editor learned one field.** `PagesCreateAction` and
`PagesEditAction` normalise `form` against the same grammar, keep it out of
front matter when empty, and preserve it across an edit that does not submit
it. `Pages` never resolves the slug: an unknown or unpublished form is the
block's decision at render time, and the field stays a string.

## What was measured

| measured | number |
|---|---|
| routed actions | 9 (8 admin, 1 public) |
| module tests | 50 tests, 145 assertions, 0 failures |
| ownership gate | 404 literal places, 284 declarations, 225 names |
| `admin-smoke` assertions in the Forms block | 22, all passing |
| form definitions a locale's list holds | 20 fields per definition, unbounded definitions |
| result bars read | the newest 500 submissions, the divisor printed beside the total |
| `lekalo` model | `lekalo/modules/forms/`, 6 files, 356 lines |

The end-to-end path the gate walks, through the product only: a definition
created at `/mark/forms/create` in `sk`, a page created at `/mark/pages/create`
naming it, the served page carrying the theme-prefixed markup and the
definition's title, a submission posted at `/forms/{slug}` from a reader's own
cookie jar, the row landing as `new`, the redirect painting the confirmation,
the inbox at `/mark/forms/{slug}/submissions` listing it, then the definition
deleted with its answers and the page deleted with its file. The tree's storage
is diffed before and after, so the block leaves nothing behind.

## What the measurement changed

The ownership gate's first run on this round read **610 places** against a
ceiling of 385, and 182 of the 225-place rise were the two words `'text'` and
`'email'`. The fix was the enum above, not the number: the module then measured
19 places on a new surface (nine route attributes spelling their own paths, two
stored statuses, the nav href, a theme prefix and one `en` fixture in tests),
and the ceilings moved 385 → 404 places, 269 → 284 declarations and 214 → 225
names with the reasoning written at each constant and in
`docs/name-ownership.md`.

The first run of the smoke block failed three checks, and none of them was the
product. The public POST answered **403**: the page carrying the form was
fetched with no cookie jar, so the token printed in its HTML belonged to a
session whose cookie was never kept, and `CsrfMiddleware` correctly refused the
post that presented it. The gate was testing a token from a session nobody had.
It now renders the page *with* `public.jar`, which is what a reader's browser
does, and the same 22 assertions pass.

That is the useful part of the record: a smoke check that fetches a page and
then posts its token is only a check if both halves share a session, and the
failure mode is a 403 that looks exactly like a broken endpoint. The token was
right, the jar was missing.

## What the round did not close

- **No file upload field.** A form answers text; an upload needs its own
  storage, its own scanning and its own retention rule, and none of that is a
  field type.
- **No multi-value checkbox group.** The payload is `array<string, string>`,
  and one consent box is the whole shape it supports.
- **No export.** Answers are read in the panel and nowhere else; a CSV export
  would need a streaming response and a decision about which columns.
- **No translation pairing.** The same slug in two locales is two definitions,
  not a pair, so nothing links `contact` in `sk` to `contact` in `en`. The
  page's `translation` key has no counterpart here yet.
- **Removal keeps the data.** `module:remove Forms` takes the slice out and
  leaves `storage/data/forms.db` in place, deliberately: answers are data, not
  code, and a reinstall finds them where they were.
