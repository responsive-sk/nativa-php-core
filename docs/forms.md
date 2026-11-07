# Forms > definitions an editor writes, answers a reader sends

The `Forms` module owns what a contact box actually is: the definition with its
field list, the public endpoint that accepts a submission against that list, and
the inbox that reads the answers back. It follows the module-guide template
`docs/pages.md` sets: **what it serves, what it owns, what it refuses**.

## What it serves

| route | what it answers |
|---|---|
| `POST /forms/{slug}` | a submission, validated against the stored schema |
| `GET /mark/forms` | the definitions for the requested locale |
| `GET\|POST /mark/forms/create` | the editor, and the create it posts |
| `GET\|POST /mark/forms/{slug}/edit` | the same editor for one definition |
| `POST /mark/forms/{slug}/publish` | the publish toggle |
| `POST /mark/forms/{slug}/delete` | the definition and every answer to it |
| `GET /mark/forms/{slug}/submissions` | the inbox, newest first |
| `POST /mark/forms/{slug}/submissions/{id}/handled` | marks one answer handled |
| `POST /mark/forms/{slug}/submissions/{id}/delete` | removes one answer |

The public endpoint is addressed by the form's own slug, not by the page that
embeds it: one definition can sit on a page and in an article at once, and
neither surface learns what a field is. `{{form:<slug>}}` expands through
`#[AsMarkdownBlock]`, and the page's front matter `form:` key is the editor's
shortcut for putting that placeholder at the end of the body.

## What it owns

Two SQLite tables in `storage/data/forms.db`, both created by
`FormsDatabaseMigrator`:

| table | holds |
|---|---|
| `forms` | one definition per `(slug, locale)`, its title, intro, success message, schema and publish flag |
| `form_submissions` | one answer, the definition it belongs to, the values, the address and the state |

The unique index is over `(slug, locale)`, so the same slug in `sk_SK` and in
`en_US` are two definitions and neither overwrites the other. A submission is
attached by `form_id`, so renaming a definition keeps its answers; deleting the
definition deletes them in one transaction, explicitly rather than by a foreign
key pragma a hand-built connection might not have.

The schema is an ordered field list, stored as JSON. A field declares four to
six things, and every limit is a constant on `FormSchema` rather than a number
spelled in a controller:

| key | rule |
|---|---|
| `key` | `[a-z0-9][a-z0-9_-]{0,39}`, lowercased on the way in, not one of `form_return`, `form_website`, `_token` |
| `label` | required, at most 120 characters |
| `type` | one of `text`, `email`, `textarea`, `select`, `radio`, `checkbox` |
| `required` | one consent box or one required answer, depending on the type |
| `max` | 1 to 5000, defaulting to 500 for a line and 2000 for a textarea |
| `options` | choices and radios only: at most 10, each at most 80 characters |
| `show_results` | choices and radios only: draw a bar over the newest 500 answers |

A form holds at most 20 fields. `checkbox` is one consent statement and not a
multi-value group, which is why it may not declare options: every other type
answers a single string, and the result bars average one value per submission.

## What it refuses

- **A key the schema does not declare.** The payload is built from the schema
  in, so an extra POST field never reaches storage, and a second escape can
  never double-encode a reader's text.
- **A submission before the definition is published.** A draft, a missing slug,
  a slug that exists only in another locale and an unparseable schema each
  render nothing and log one line: a reader sees a page without a box, never a
  box without fields.
- **A filled honeypot.** `form_website` is checked before the definition lookup,
  so a bot cannot time a 404 against a redirect to learn which slugs exist.
- **A sixth submission inside the window.** Five per address and per form per
  five minutes, refused before any write.
- **A redirect on a refusal.** A refusal carries no `Location` header, because
  `Response::send()` rewrites any 302 that has one and the message would be
  lost behind the unchanged page.

## Two things this module does not decide

**Its locale family.** `ContentServices::mapper()` owns which locale is default
and how a code maps to a directory and a URL prefix. A form stores the canonical
code it was given; the block and the endpoint ask the mapper what "default"
means rather than hardcoding a literal.

**Whether a page or an article embeds it.** The placeholder is markdown, and the
block expands it after the content is already HTML. The dependency runs the
other way for one action only: `FormSubmitAction` re-renders the embedding
subject through `PageViewAction` or `BlogArticleAction` on a validation failure,
so the reader's words survive and there is one renderer per surface. That is the
declared closure `Forms → Pages, Blog`; nothing in `Pages` or `Blog` imports
`Forms`.

## Where it sits in the tree

`Forms` is an ordinary module: `bin/cms module:add Forms` copies the slice in,
`bin/cms module:remove Forms` takes it out. Removal leaves the answers where
they are: `storage/data/forms.db` is data, not code, and the migration is
idempotent, so a reinstall finds the definitions and the inbox as they were. A
page whose front matter still names a form renders without one, and a
`{{form:…}}` placeholder renders nothing until the module comes back.
