# Pages > static content in two locales

The `Pages` module serves the site's evergreen content: an about page, a
contact page, a landing page. It is the smallest useful module and the first
guide written this way, so it doubles as the template the later module guides
follow: **what it serves, what it owns, what it refuses**.

## What it serves

| route | what it answers |
|---|---|
| `GET /{slug}` | a published page, rendered inside the active theme |
| `GET /mark/pages` | the admin list: search, sort, locale toggle, pagination |
| `GET /mark/pages/create`, `/edit`, `/delete` | the CRUD form and its actions |
| `POST /mark/pages/publish` | the publish toggle |

`/{slug}` is a **catch-all for root-level paths**, registered after every other
route so `/`, `/blog`, `/mark` and `/docs` win first — the router matches
literal patterns before parameterized ones, and `/docs` (the index) beats the
`/{slug}` catch-all for exactly that reason.

A page that exists but is not published answers **404**, never a redirect: a
301 that lands on a 404 is a broken promise. A page that was *renamed* answers
**301** to its new URL, query string preserved, and only when the target is
published.

## What it owns

Pages are markdown files with YAML front matter, stored per locale:

```
storage/pages/<slug>.md          # the configured default locale
storage/pages/<lang>/<slug>.md   # every other locale
```

The directory rule comes from `ContentLocaleMapper`, the same rule the blog
uses — the two content families can never disagree about which locale is
default. Front matter carries the page's own fields:

| key | meaning |
|---|---|
| `title` | the page title; falls back to the slug's `ucfirst` |
| `published` | `true` is the only value that serves |
| `seo_title`, `seo_description` | overrides; empty means the title and the site default |
| `translation` | the *other* locale's slug for this page |
| `aliases` | previous slugs, as a flow list (`["old-slug"]`) |
| `form` | a form slug embedded at the end of the body |

`translation` is resolved, not merely declared: the paired page must actually
exist, or the key is "no pair" — the same rule the blog holds. `aliases` is
written strictly as a flow list and read tolerantly (a comma scalar from a
hand-edited file is accepted).

The module also owns the page cache contract behind `CacheInvalidator`: a
rename flushes it, because without the flush the next request serves the
previous slug's cached HTML and the 301 appears not to work for a cache
lifetime.

## What it refuses

- **A slug outside `[a-z0-9][a-z0-9_-]*`** — the same grammar the filesystem
  scan enforces. A slug is a filename and a URL at once.
- **A collision with an alias.** A live file and somebody's alias both own a
  URL; a new page may not shadow a redirect, and a rename may not shadow a
  page.
- **Rendering an unpublished page's reader-questions block.** The draft gate
  answers 404 first, so a reader can never see a form on text they cannot read.

## Two things this module does not decide

**Its locale family.** `ContentServices::mapper()` owns which locale is the
default and how each one maps to a directory and a URL prefix. Pages asks; it
never hardcodes a code — a defaulted locale argument is how a hardcoded Slovak
literal once leaked in and made every unknown code resolve to Slovak.

**Whether the site has questions.** The page-end question block is guarded by
`class_exists`, never imported: a page is complete without its questions box,
so `Pages` installs and renders without `Questions`. A `use Modules\Questions\…`
here would close an install-order cycle (`Pages → Questions → Pages`).

## Why `/docs` is not a page

`/{slug}` is a catch-all, and three surfaces could claim the same root path. The
arrangement is deliberate and holds without a list anyone maintains:

- **The router matches literal patterns before parameterized ones**, so `/docs`
  (the guide index) wins over `/{slug}` — a page whose slug happens to be `docs`
  can never shadow the documentation surface. The same rule protects `/`, `/blog`
  and the admin prefix.
- **`LocaleMiddleware` strips `/sk` and `/en` before routing**, so `/sk/docs` and
  `/en/docs` reach the same guide; the prefix belongs to the locale family, not
  to the page's own slug.
- **A guide and a page are two corpora with two publication policies.** A page is
  evergreen content an editor publishes (`published: true`, SEO meta, locale
  pairs, alias redirects, indexed). A guide is English engineering prose served
  from `docs/` through an allow-list, `noindex` and with no canonical. Neither can
  publish the other's content by accident, which is the point: a `published`
  toggle in `/mark` must not be able to put an internal document in front of a
  crawler.

A dated article is a third corpus — `storage/blog/` — where it is indexed, has an
author and is linked by the feeds. `docs/clanky/` holds the drafts that never
became one, and is never served.

## Where it sits in the tree

`Pages` is an ordinary module: `bin/cms module:add Pages` copies the slice in,
`bin/cms module:remove Pages` takes it out and leaves the pages on disk
untouched — the content is data, not code, and removal deletes no data. The
admin list it adds disappears with the slice; the public `/{slug}` route 404s
on a fresh server after removal.
