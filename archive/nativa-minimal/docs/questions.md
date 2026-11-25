# Questions — reader comprehension gaps, with their own attempt

`Modules\Questions` is the kit's bounded context for the question an author
rarely hears asked: where a reader got stuck, **and what they tried**. It has
its own `questions.db`, one block on every article and every page, and one
inbox in `/mark`.

Why the second half matters: "I don't understand this part" tells an author
that something is unclear. "I think the middleware runs before routing, but
then where does the CSRF token come from?" names the gap precisely and shows
the author's own model of the system. The first is a support ticket; the
second is a revision signal.

## The subject is a pair

Every question is addressed by `(subject_type, subject_slug, locale)` — never
by a bare slug. `/blog/foo` and `/foo` are two subjects that happen to share a
name, and the second is the trap: a question attached to the wrong one is a
question about text the reader never read.

`SubjectResolver` is the single owner of that resolution, and it resolves
against **published** content only. An unknown slug, a draft, and a page that
does not carry that slug all resolve to "no subject", and the submit answers
404 **without a `Location` header** — a refusal that also redirected would
leave the machine on a 302 to the unchanged subject, which on the wire reads
as a success.

The index on `(subject_type, subject_slug, locale, status)` is deliberately
**not unique**: five readers asking about the same article is the ordinary
case, not a constraint violation. `QuestionsTest` asserts the non-uniqueness
from `PRAGMA index_list`, because a "helpful" unique constraint landing later
would be a silent data-loss bug.

## The block

Rendered by `QuestionBlockRenderer` after the article or page body, below the
comments, for **every** wired theme — no theme owns the slot, and a block only
one theme renders is a block four readers never see. The two actions that own
the surfaces append it (`BlogArticleAction`, `PageViewAction`); the kernel does
not thread it through `PageContext`, because the first draft of this added a
slot there and no theme ever read it.

Markup is module-owned and every class is composed from the active theme's
prefix, so no theme file names a question class. Each theme ships its own
stylesheet, generated from the canonical
`Frontend/packages/starter/src/questions.css` by
`bin/generate/generate-questions-css.mjs`. The stamp runs on **class boundaries**: a
bare substring replace turns `list-style` into `liba-style` and `:last-child`
into `:laer-child` — dead rules that still look correct in the file, and every
one of them a `@supports` margin fallback with no other gate watching it.

## Submitting

`POST /questions/{subjectType}/{subjectSlug}`, in this order:

1. **CSRF token**;
2. **honeypot** — a filled `question_website` field is discarded silently. It
   runs *before* the subject lookup on purpose: a bot that learns "that slug
   exists" from a 404 has turned a form into a slug oracle;
3. **per-IP rate limit** — five questions per five minutes, checked before any
   write;
4. **subject resolution** against published content;
5. **validation** — the question *and* the attempted answer are both required.

A successful submit is a 302 back onto the subject with `question-sent=1`, and
the confirmation is painted with `role="status"`. That marker is
session-scoped HTML, so it sits in `PageCacheService::BYPASS_QUERY_PARAMS`
next to `form-sent` / `form-invalid`; the cache is shared, and caching it would
hand one visitor's confirmation — or one visitor's rejected input — to the
next.

A rejected submit re-renders the **subject** with the errors and the surviving
input, not a bare error page: a reader who loses their question because a field
was empty will not write it again.

`status` is never read from the request. `Question::create()` decides `pending`,
so there is no request a reader can send that approves their own question.

## The sidebar badge

`/mark/questions` carries the number of **pending** questions on every
administrative page, through the kernel's `NavBadgeRegistry` — so the author
learns a reader is stuck without opening the one screen whose entire job is to
be surprising. This kit sends no e-mail and calls no webhook, so the number
lives where the author already is.

Three things about it are worth stating plainly, because each of them is a
decision rather than an implementation detail:

- **It is the depth of the work queue, not an unread marker.** Moderating a
  question decrements it; merely looking does not. A per-user "seen" flag is a
  second table, a "mark as seen" action and a question about whether two
  moderators share one read state — none of it is needed to answer "is anything
  waiting", and all of it is a different feature.
- **It inherits the item's permission.** The count is requested only for
  navigation items the filter already kept, so a role without `content.manage`
  never sees the item and never learns the number.
- **It renders nothing at zero.** Not an empty pill — no element. A well-moderated
  inbox is the normal state, and noise on every admin page is how a badge
  stops being read.

The count is one indexed `COUNT(*)`, not a hydration: measured at 500 rows
(250 pending) it costs **0.017 ms** against **10.2 ms** for hydrating the same
rows through `findPending()`.

### What the badge looks like, measured

`NavBadgeTest` can only see that the number reaches the HTML. A chip whose
background rule never arrived still emits the right digit — and white on the
dark sidebar measures 17:1, so every contrast assertion passes while the badge
is not on screen. `bin/checks/nav-badge-check.mjs` drives a real browser and measures
what the author actually sees (32 assertions):

| Measured | Value |
|----------|-------|
| Digit vs its own chip, both colour modes, every admin page | **5.70:1** (AA needs 4.5) |
| Chip vs its row, inactive row | 3.54:1 dark, 2.79:1 light |
| Chip vs its row, on `/mark/questions` itself (the row is the active colour) | **1.00:1** |
| Chip geometry at 1280 / 480 | 18×18, inside the sidebar's painted box |
| Badge at 480px with the drawer closed | off-screen at `x = -36`, while `checkVisibility()` answers `true` |

Two of those numbers are deliberately **not** gates, and the reason is worth
stating because a missing badge and an unreadable badge are different defects:

- The chip's own edge is 1.00:1 on the page the badge points at, because the
  active row and the chip are both `--m-primary`. The information is the DIGIT,
  which clears AA on the row it sits on; the pill is decoration around it. The
  edge is printed on every run so the 1:1 is on the output rather than hidden
  in it.
- The chip cannot clear 3:1 against the light sidebar *and* carry white text at
  4.5:1 — the pair has no room for both, and a gate the shipped palette cannot
  pass is a gate that reports the palette instead of the feature.

The check reads the expected depth off the inbox the link points at (a
hydrated `all()`, not the badge's `COUNT(*)`), so the two code paths have to
agree before anything is measured. An empty queue is filled with one real
question posted through the public form; when the per-IP limit is spent the
check says so by name and asserts the empty-queue rule instead of passing.

## Moderating

`/mark/questions` groups questions by subject with a per-status count per
group, so an author sees "three readers stalled here" rather than one
undifferentiated stream. `?status=` filters; an unrecognised value is ignored
rather than refused.

Article groups include a **Review article** link to the existing
`/mark/articles/{slug}/edit?locale={locale}` screen. The slug and locale come
from the full article subject key; page groups deliberately have no such link.
The editor shows that article's questions and attempted answers as escaped,
read-only context outside the Markdown body and metadata fields. It loads only
the exact `(article, slug, locale)` pair, so a page with the same slug and a
translated article cannot bleed into the context. Opening or reviewing the
context changes neither the article file nor question status. A deliberate
save still uses the normal article ownership guard, audit record, cache
invalidation, front-matter passthrough, and publication-state behavior.

Approve, reject and delete are `POST /mark/questions/{id}/{action}`, each
audited as `questions.approve` / `questions.reject` / `questions.delete`, each
flushing the cached page of the subject. The inbox is classified in
`AdminRoutePolicy` as `content.manage`, alongside the other content surfaces —
so a session without it gets a 403 naming the permission, and an admin path
nobody classified still fails closed.

An **approved** question renders back on its subject; the block lists the
approved rows for that subject in that locale and nothing else. The editorial
context does not change moderation or publish reader text.

## What this does not do yet

- **No notification.** The reader is not told their question was approved, and
  the author is not e-mailed when one arrives. The inbox is the delivery.
- **No automatic revision.** Reader questions are editorial evidence, never
  pasted into the Markdown or front matter. Whether and how to change the text
  remains the author's judgement, expressed only by an explicit normal save.
- **No AI, auto-publish, or notifications.** The workflow neither generates a
  rewrite nor changes publication state based on a question; it sends no
  e-mail or webhook.
- **No threading, replies, votes or anonymous-everything.** One reader asks
  once. A public Q&A with discussion is a different product.
- **No per-question page.** Approved questions render inline with their
  subject; there is no `/questions/{id}`.
- **No slug-rename migration.** The subject is the URL slug, so renaming a
  page (the localized-slug rename keeps its old URL as a 301) orphans its
  previously approved questions under the old slug: the renamed page's block
  renders empty — not an error — and the inbox keeps the old rows under their
  original subject. Re-keying them is a migration this slice deliberately does
  not attempt; the same holds for a renamed blog slug.

## Where it is tested

| Surface | Where |
|---------|-------|
| behaviour (both surfaces, refusals, grouping, filters, exact-locale editor link and read-only context) | `tests/Integration/QuestionsTest.php` |
| the sidebar badge: placement, queue depth, permission gate | `tests/Integration/NavBadgeTest.php` |
| every admin screen is linked, and the badge registry's contract | `tests/Architecture/AdminMenuCoverageTest.php`, `NavBadgeRegistryTest.php` |
| per-theme stylesheet parity + palette locals | `tests/Architecture/FrontendQuestionsParityTest.php` |
| the block in a real browser, incl. a submit round trip | `bin/checks/questions-check.mjs` |
| the badge PAINTED: contrast, geometry, both colour modes | `bin/checks/nav-badge-check.mjs` |
| the semantic chain requirement → symbol → test | `docs/lekalo/trace/questions.trace.json` |
| the contract | `openspec/specs/questions/spec.md` |
