---
title: "Member bonus ... forms: strangers fill it in, you only read it"
date: "2026-10-03"
version: "1.0.0"
tags: ["forms", "csv", "security", "php", "architecture"]
excerpt: "The ninth bonus for registered readers. A form is not a comment with a different label: you author the schema, strangers author the answers, and you get an inbox plus a CSV. Why the bot trap stays empty, why a slug never leaks into another language, why quoting an Excel formula changes nothing, and what happens when a class name is assembled at runtime. All on real code from this repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-formulare-pre-deti"
published: true
---

> This is the ninth **member bonus**. Previous parts: [the members-only
> lock](/en/blog/bonus-members-only), [the immutable audit
> log](/en/blog/bonus-audit-log), [full-text search via
> FTS5](/en/blog/bonus-full-text-search), [comments with
> moderation](/en/blog/bonus-comments), [media as foreign
> files](/en/blog/bonus-media), [debug as a
> retroskop](/en/blog/bonus-debug), [locales as a
> contract](/en/blog/bonus-locales) and [the article as a product with a
> number](/en/blog/bonus-shipping). Today the place where a stranger does
> not write one sentence ... where they **fill in** a whole schema you
> assembled.

You're a developer. A stranger reads your article, and you hold the
control, because you wrote it.

You already built the comment ... and you
[remember](/en/blog/bonus-comments) how that turned out: one free-text
field, one throttle, one inbox to read.

Now you place something under the article that asks for values you know
how to read exactly. A name, an e-mail, a subject. Or a question with
several answers. Or a question where one of the answers is right.

You author the schema. A stranger authors the answers.

And the difference between that and a comment is bigger than it looks
at first ... not because it is different code, but because **delivery**
is different.

## A form is not a comment with another label

A comment has one field. You write a sentence, it is stored, you are
done.

A form has a **schema** you edit in `/mark/forms`, and editing it
deploys nothing. You add a field, change a type, mirror it into another
language ... and both the markup on the page and the rows in the
database change with it.

| Type | Renders | Stores |
|---|---|---|
| `contact` | typed fields (text, e-mail, textarea, select) | one row per field answer |
| `poll` | one question per row, radios or checkboxes | one row per **selected option** |
| `quiz` | like a poll, plus per-question correctness | answers plus `score` and `max_score` |

That middle row of the table is the whole design of a poll.

One row per selected option means the result is not a string you have
to parse ... it is a `GROUP BY` over the answers table. Five people
picked "blue" and you ask the database, not a parser.

A checkbox poll with two questions and four options can produce an
answer with several rows. That is not a normalization defect, that is
the entire point.

A quiz also scores. And per question, not per answer: a half-correct
answer does not count. All of it or nothing.

## A schema without a deploy

`/mark/forms` lists definitions filtered by locale and type. Create and
edit share **one action**, because the two routes are the same form
with a single extra question:

```php
// src/Modules/Forms/Interfaces/HTTP/Actions/FormEditorAction.php
#[Route(path: '/mark/forms/create', method: 'GET|POST', name: 'forms.create')]
#[Route(path: '/mark/forms/{id}/edit', method: 'GET|POST', name: 'forms.edit')]
final class FormEditorAction
```

Because it is one class, a form cannot be created with a field it can
no longer be edited with. That is a stupid bug, and in another codebase
it surfaces a year later, to whoever fills the form in first.

A definition has three states:

| State | Renders publicly? | Accepts answers? |
|---|---|---|
| `draft` | no | no |
| `published` | yes | yes |
| `closed` | no | no |

`draft` and `closed` are a **404 on both paths**, never a silent accept.

This is the most important sentence in the article, so let me say it
another way: a visitor must never believe a message went through when
it did not. A silent accept of a refusal is the prettiest bug you can
build with a form.

## How a form gets into an article

Two paths, one mechanism. In the body:

```markdown
Write to us if you have an idea.

{{form:contact}}
```

Or in the front matter, the whole form below the content:

```yaml
---
title: "A poll"
form: poll-topic
published: true
---
```

Both meet in one mechanism, the markdown block registry in Core
(`#[AsMarkdownBlock]`, `MarkdownBlockRegistry`).

And that is why it lives in Core rather than in the form: `PageViewAction`
and `BlogArticleAction` know only the **key**. They never import
`Modules\Forms`. A module appears inside content without Core, Pages or
Blog knowing that it exists.

The other half of the same contract: a shortcode inside fenced code is
**not** expanded. An article documenting the syntax has to keep showing
`{{form:...}}` literally.

```markdown
This is an example, not a live form:

{{form:contact}}
```

The block receives the **rendered HTML**, not the source. It splits the
document into segments first, and the ones inside `<pre>` or `<code>`
are copied verbatim. Everything else is expanded.

Tag pairing does not stop at the first `</pre>` here: a single `<pre>`
may carry several fenced blocks. Pair the tags by order alone and you
fire after the second fence, from where you would run a form inside
an example.

And the slug is not looked up as an arbitrary string, it passes
`#(?:<p>)?\{\{form:([a-z0-9][a-z0-9_-]*)\}\}#` before any lookup. A
wildcard character never reaches the pattern.

The price of this: a shortcode that resolves to nothing disappears
quietly. A typo, a concept, a draft. The visitor sees neither `{{…}}`
nor an error.

## One slug, one language

The `locale` column makes the Slovak and the English form genuinely
separate rows with separate slugs: `kontakt` and `contact`.

A slug is free in **each** language separately. Uniqueness is the pair
`(slug, locale)` ... the same word may name a form in every language.

And an English request never resolves the Slovak definition. Not even
with the same slug. Ask and you get a 404, not a quiet answer in the
wrong language.

That is different from having one piece of content in two versions. A
form is not a translation, it is a **separate thing in every language**,
with its own field labels, its own options and its own answers.
Translations get translation front matter. This gets a column.

## Spam: three layers, cheapest first

None of them is a CAPTCHA and none of them promises to stop a
determined attacker.

**The honeypot** is a field no human sees. Not `display: none` but
native `hidden`, so neither a screen reader nor a keyboard meets it.
Filled in means the answer gets the **same quiet success** a real one
gets.

The bot learns exactly what you want it to learn: nothing.

And nothing is stored. That is not a sentence in documentation, it is
the claim of one unit test:

```php
// tests/Unit/FormsSubmissionServiceTest.php
testAFilledHoneypotGetsASilentSuccessAndStoresNothing
```

**The per-IP limit** is the same `RateLimiter` that holds comments
back. The second defence, on volume.

**CSRF** is enforced globally by the middleware; the form carries only
the field.

Every admin write is audited, and each submission flushes the page
cache so a later request cannot serve the results from before your
vote.

## Delivery: an inbox and a CSV

**The inbox and the CSV are the only channels. No e-mail, no webhook.**

That is a scope decision, which is why the export cannot be lossy.
`/mark/forms/{id}/submissions` is the reading surface: new and read
counters, columns shaped by the definition, pagination.

The export is a plain GET, because a spreadsheet wants to "download" it
and so does `curl`:

```bash
curl -OJ http://127.0.0.1:8000/mark/forms/1/submissions.csv
```

Three things that have to be spelled out:

**A UTF-8 BOM.** Excel on Windows reads a bare UTF-8 CSV as latin-1 and
turns every Slovak diacritic into mojibake. The BOM is three bytes at
the start of the file, and readable text is the answer.

**One row per answer, one column per question.** Storage ids are
resolved back to the label you typed in the editor, and a question
with several answers joins into one cell with `; `.

**Formula neutralising.** Every value came from a visitor. A value
starting with `=`, `+`, `-` or `@` is a formula to Excel.

And here is the trap a unit test hit, not common sense:

```php
// src/Modules/Forms/Interfaces/HTTP/FormSubmissionCsv.php
public function cell(string $value): string
{
    if (1 === preg_match('/^[=+\-@\t\r]/', $value)) {
        $value = "'" . $value;
    }
```

Quotes are not enough. Excel strips them on import and evaluates the
formula anyway. The apostrophe stays in the cell as text, it is the
only form a spreadsheet respects.

The first version of that method was written correctly by the CSV
standard and was wrong for Excel. Both systems are standards. The
problem was between them.

## Four themes, one block

The block renders under the **active theme's prefix**: `st-form`,
`er-form`, `ms-form`, `mag-form`. It is the same contract
[`CommentSection`](/en/blog/bonus-comments) has in erik.

And there is no new theme surface here. `ThemeModule` is untouched,
`FormBlockRenderer` takes the prefix as an argument, and the module
never imports `Themes\`.

The prefix is assembled **at runtime** from `ThemeModule::prefix()`,
which means no static check ever sees it. There is one source file:

```
Frontend/packages/starter/src/form.css   # edit only this one
        │  node bin/generate-form-css.mjs
        ├── Frontend/packages/erik/src/erik/form.css
        ├── Frontend/packages/moonshine/src/moonshine/form.css
        └── Frontend/packages/magazine/src/magazine/form.css
```

`FrontendFormParityTest` checks three things: each copy carries its own
prefix, the selector sets stay identical, and that prefix is still the
one `ThemeModule::prefix()` returns.

This is not theory. The `bin/forms-check.mjs` check froze the `st-`
prefix into its expected strings and reported ten failures on erik,
moonshine and magazine ... while **every page was correct**. The
mistake cost thirty seconds of reading and one edit to turn into
reading the prefix off the rendered block and cross-checking it
against the CSS the theme actually ships.

Two sources, one agreement or one failure.

## What a form is not

An honest section, because without it the article reads like an ad.

**It is not a delivery service.** No e-mail, no webhook. If the export
is lost, the export is lost.

**It is not a moderation queue.** Responses are delivered, not
judged. The lifecycle is `new → read → archived`, because a reading
surface means something different from an approval surface. If you
need approval, build another cycle ... this one would lie to you.

**It is not a translation.** One form, one language, its own schema.

**It is not file upload.** No upload, no antivirus, no sandbox. Just
fields.

**It is not a replacement for a page.** A form has its own fields, its
own labels and its own states. If you want two labels and one field
on a page, the page is shorter and editable without a schema.

## Practically

1. `php bin/cms forms:seed`: four demo forms and the pages `/anketa`
   and `/test`, where you see a poll and a quiz mirrored from markdown.
2. Drop `{{form:contact}}` into a page or `form: contact` into an
   article's front matter and open it in another theme. If it works
   only on starter, look at the prefix.
3. `node bin/forms-check.mjs http://127.0.0.1:8000`: and read the
   output as a statement. It is the one test that sees the whole chain
   at once: middleware → action → session → markdown block on the
   next render.

The point I would write down: **a form is not made of the fields you
render, but of the fields you can read back.** That difference never
shows up while you write the template. It shows up in the spreadsheet
export, where you suddenly notice the column you forgot.

## Sources

- [OWASP, CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection),
  why quotes are not enough and what a spreadsheet does about it
- [SQLite, SELECT](https://www.sqlite.org/lang_select.html): `GROUP BY`
  as the reason a poll is not one column
- [docs/forms.md](https://github.com/evan70/nativa-minimal/blob/master/docs/forms.md),
  the whole module on one page
- [The fourth bonus, comments](/en/blog/bonus-comments): where this
  cycle grew from