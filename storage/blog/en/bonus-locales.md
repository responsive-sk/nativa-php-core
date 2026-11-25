---
title: "Member bonus ... languages: the visitor chooses, the browser doesn't decide"
date: "2026-09-17"
version: "1.0.0"
tags: ["locales", "i18n", "php", "architecture", "bilingual"]
excerpt: "The seventh bonus for registered readers. A bilingual app is a contract about order: who decides the language, the URL, a header, a cookie, the session or the setting? And why this kit deliberately ignores Accept-Language ... because the browser isn't the owner of the language. On real LocaleMiddleware code."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-jazyky-pre-deti"
published: true
---

> This is the seventh **member bonus**. Previous parts: [the members-only
> lock](/en/blog/bonus-members-only), [the immutable audit
> log](/en/blog/bonus-audit-log), [full-text search via
> FTS5](/en/blog/bonus-full-text-search), [comments with
> moderation](/en/blog/bonus-comments), [media as foreign
> files](/en/blog/bonus-media) and [debug as
> retrospection](/en/blog/bonus-debug). Today: the question every
> multi-language app must face, *who actually decides the language?*

You're a developer. The app has two languages, SK and EN. You write
routes, templates, content ... and one day someone asks the question
that sounds trivial:

"In which language should this page run?"

It's not trivial. It's a contract about order, and every app that
never wrote it down wrote it by accident.

## The ladder that decides

`LocaleMiddleware` (`src/Modules/Locales/Interfaces/HTTP/Middleware/LocaleMiddleware.php`)
carries the order right in its docblock, and each rung has a different
weight:

| priority | source | who's pushing |
|---|---|---|
| 0 | URL prefix (`/en/blog`) | the visitor, explicitly |
| 1 | `X-Locale` header | an API client |
| 2 | cookie | the visitor, once |
| 3 | session | the current session |
| 4 | `site.locale` setting | the app's owner |

The law of the ladder: **the higher the priority, the more explicit the
will**. A URL prefix is a visitor who named the language out loud, so
it beats a cookie from the past.

And every rung of the ladder is just a different kind of memory: this
one lives in the address, that one in a cookie, that one in a session.
The same lesson as the [state out of
context](/en/blog/state-out-of-context) article, state has a carrier,
and each carrier has a different lifetime.

## The browser doesn't decide

And now the line that is the whole bonus in one sentence.

The middleware **deliberately does not use** the `Accept-Language`
header. The browser sends it with every request, the language set in
the system.

Why ignore it? Because the browser isn't the owner of the language.

A Slovak bookseller living in Brussels has `Accept-Language: fr-BE` in
her browser ... and wants to read in Slovak. If the app followed the
header, the system installation would decide for her, not she herself.

The site default always beats browser preference, visitors choose a
locale explicitly. It's a decision with a price: the first visit shows
the default, not "your" language. But it's **her** decision, not
Chrome's or Firefox's.

Compare with the samples in this blog: a sentence without a condition
is `mixed`. "Show the page in the reader's language" sounds beautiful,
and hides the question of *what the reader's language is*. This repo
answers: what they chose. Nothing more.

## The prefix that gets stripped exactly once

The gentlest bug of multilingual apps is the double strip.

A request to `/en/blog` arrives, the middleware strips the prefix ...
and if anyone asks again, they see the already-clean path `/blog` and
declare "there was no prefix", the explicit choice is lost.

The code solves it with a comment worth reading:

```php
// LocaleMiddleware.php — explicitLocale() is resolved EXACTLY ONCE
// explicitLocale() is resolved ONCE: detectFromUrl() rewrites the
// request path (strips the /sk|/en prefix), so calling it twice
// would make the second call see the already-stripped path and
// report the locale as not explicit.
$explicit = $this->explicitLocale($request);
```

`detectFromUrl()` mutates the request. A function that changes the world
can't be called twice naively, the second call reads the world the
first one changed. The fix: compute the result once, store it in the
`locale_explicit` attribute.

The blog needs this flag for a subtle distinction: `/blog` is the
default language, `/en/blog` is an explicit choice, and the canonical
URL behaves accordingly.

## t(): a three-level fallback

Templates don't write words. They write keys:

```php
// src/Modules/Locales/Interfaces/HTTP/LocalesServices.php
public static function t(string $key, string $default = '', ?string $locale = null): string
{
    $activeLocale = self::locale()->current()->code();

    return self::translations()->get($key, $locale ?? $activeLocale, $default);
}
```

The fallback chain: translation in the active locale → translation in
the explicitly requested one → the `default` parameter → the key. A
template calling `t('nav.home', 'Home')` never renders `nav.home`: at
worst it renders the English default.

Keys live in `locales.db`, are managed in `/mark/locales` and imported
as JSON, a translation is data, not code.

## Translating the blog isn't translating the UI (and the mandatory honesty)

Two layers of bilingualism are two different jobs.

UI strings (`nav.home`) translate by keys, systematically, once. Blog
articles translate as **separate files** with per-language slugs
(`preco-rust-pre-deti` ↔ `why-rust`), linked by `translation:`: because
an article isn't a string, it's a text with its own voice. Machine
translation would kill the voice.

Be honest: the priority ladder has a less known cost, too. Cookie and
session are memory between requests, someone who changes the language
in a cookie falls back to the default at the first URL-prefixed link.
That's not a bug, it's the contract: the address wins. But it's a
surprise you need to know.

And the second: two languages aren't i18n. Real internationalization
(plurals, dates, RTL) is an order of magnitude more work, this kit
solves bilingualism, not the world.

## Practically

1. **Change the language via the address** ... `/en/blog`: and come
   back to `/blog`. Watch what happens to `<html lang>`.
2. **Add a key in `/mark/locales`** ... and use it in a template via
   `t()`: a translation is data, not a deploy.
3. **Look at `translation:` in the front matter** ... every article
   points at its mirror and the mirror points back, bilingualism as a
   two-way contract.

Language then isn't a setting ... it's a contract the reader signs with
an address.

## Sources

- [MDN, Accept-Language](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Accept-Language) ... the header this kit deliberately doesn't read
- [W3C, Language tags in HTML and XML](https://www.w3.org/International/articles/language-tags/) ... language tags and their structure
- [PHP, Locale](https://www.php.net/manual/en/class.locale.php) ... system-level localization beyond this kit
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
- [docs/blog-voice.md](/docs/blog-voice) ... the voice guide where the bonus series lives
