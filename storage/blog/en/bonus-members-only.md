---
title: "Member bonus ... how members-only articles work in this blog"
date: "2026-08-13"
version: "1.0.0"
tags: ["members-only", "php", "auth", "architecture", "security"]
excerpt: "A bonus article for registered readers only. We show how this blog locks part of its content ... a single word in the front matter, a teaser with a sign-in prompt, and why the gate can't be bypassed through the feed or search."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-members-only-pre-deti"
published: true
---

> This article is a **member bonus**. While you're not signed in, you only
> see this teaser. After signing in the full content unlocks ... and signing
> in takes ten seconds. Why does the blog do this, how does it all work
> under the hood, and why can't the lock be bypassed? That's exactly what
> this article is about. Everything is based on the real code from this
> repo.

## Why members-only content at all

This is a know-how blog ... every article explains how this repo is built,
using real examples from the code. Until now all content was public. So
why lock anything?

1. **A reward for registered readers** ... bonus articles are a thank-you
   for signing in. It's not a paywall, it's a badge.
2. **Higher-quality conversation** ... on members-only articles only
   people you actually know (you have their email) can discuss.
3. **It costs nothing** ... no payment system, no new server.
   Registration already exists, so the lock is just one flag in a file.

That last point matters: **members-only is not new functionality. It's
one boolean value in the front matter.** Let's look at how.

## One word in the front matter

Every article in `storage/blog/` is a plain Markdown file with YAML
front matter. Making an article a bonus means adding one line:

```yaml
---
title: "Member bonus ... how members-only articles work in this blog"
date: 2026-08-13
tags: [members-only, php, auth]
excerpt: "A bonus article for registered readers only..."
members_only: true
---
```

The front matter parser in `MarkdownRenderer` recognizes `true` and
`false` and stores them as booleans (not as the strings `"true"`). The
article card then carries `members_only: bool`, and depending on it the
card renders a small **MEMBERS ONLY** badge on `/blog`.

```php
$badge = $article['members_only']
    ? '<span class="st-blog-index__badge" title="Members only ... sign in to read">Members only</span>'
    : '';
```

Notice: the card is **public**. Title, excerpt, author, badge ... everyone
sees all of it. Only the article itself is locked. That's deliberate:
a bonus must be visible, otherwise nobody would sign in for it.

## What the lock looks like for a guest

When an unauthenticated visitor opens a bonus article, instead of the
body they get a **teaser page** ... `BlogArticleAction` decides it in
three lines:

```php
if ($meta['members_only'] ?? false && !$this->isMember()) {
    return $this->membersOnlyPage($article, $meta);
}
```

The teaser page contains:

- **Title and excerpt** ... the teaser is exactly the excerpt from the
  front matter. It's not a truncated article, it's a separately written
  invitation.
- **A prompt panel** ... a "Member-only article" heading, some text and
  two buttons: *Sign in* and *Create account*.
- **No leaks** ... no TOC, no comment form, no article body. Not a chance.

The key word: **the article body is never rendered at all.** The server
has nothing to "forget to lock" ... for a guest those lines don't even
exist. It doesn't send a CSS class that hides the content (that could be
bypassed) or HTML that JS would unlock. For a guest, a bonus article is
a short page with an invitation. Full stop.

## How does Core know you're signed in

Here comes the architectural puzzle. The blog lives in `Modules\Core`,
which is the shared kernel ... and auth lives in `Modules\Users`. The
module isolation rule says: Core must not import another module's
Infrastructure and Interfaces, but it **can** use the Application layer.

And that's exactly what Core already does: `AuthMiddleware` (in Core!)
asks `Users` for `AuthService` through a lazy factory. The article
action uses the same pattern:

```php
private function isMember(): bool
{
    $session = $_SESSION['auth']['user_id'] ?? null;

    if (null === $session) {
        return false;
    }

    $auth = new AuthService(
        new PDO('sqlite:' . AppPaths::instance()->data('users.db')),
        new UserRepository(...),
        new PasswordHasher(),
        new RateLimiter(...),
    );

    return null !== $auth->currentUser();
}
```

An important detail: **`AuthService` is only created when needed.**
While a visitor reads a regular article, the lock never even runs ... no
database, no extra session logic. Lazy until the gate.

## Why the lock can't be bypassed

When you lock content, the first question is: where could it leak? We
went through every path the article travels:

| Path | What it sends | Is it a leak? |
|---|---|---|
| `/blog/{slug}` (guest) | teaser + prompt | no |
| `/blog` (cards) | title + excerpt + badge | no |
| RSS/Atom feed | title + excerpt | no |
| Search `?q=` | card with excerpt | no |

The sneakiest would be the **feed**. If the feed sent the full body,
subscribers would get the bonus article without signing in ... the lock
would be theater. That's why the feed sends **only title and excerpt**
for all articles (that's its contract anyway: a feed is a digest, not a
library). And search returns cards ... again just title and excerpt.

One path remains that can't be locked: **whoever has access to the file
on the server.** But that's true for everything, including the database.
The lock protects web readers, not the server owner.

## What happens when you sign in

The *Sign in* button doesn't lead to a plain `/login`, but to
`/login?return_to=/blog/bonus-members-only-pre-deti`. After signing in,
the server **returns you exactly to the article** you wanted to read ...
and it unlocks. No history hunting, no "back".

And that `return_to` is not a security hole. The value goes through
validation that only allows local paths starting with `/`:

```php
private static function returnTo(?string $value): string
{
    if (null === $value || 1 !== preg_match('@^/[a-z0-9/._?&=#%+~-]*$@i', $value)
        || str_starts_with($value, '//')
    ) {
        return '/';
    }

    return $value;
}
```

The regex won't let `https://` through, and the double slash `//` is
banned separately (in case someone tries to erase `http:`) ... so
`return_to` can never send the user to a foreign domain. The classic
open-redirect hole doesn't occur here.

The same mechanism works after registration ...
`/register?return_to=...`. And when `return_to` is empty or suspicious,
it falls back to the homepage. Safe default behavior.

## A rule without a test isn't followed

As everywhere in this repo: what can be tested is not left to feel.
`BlogMembersTest` has eight tests that guard the lock:

- a guest sees the teaser and prompt, but **not** the article body,
- a guest sees no TOC and no comments on a gated article,
- a signed-in reader sees the full content and no gate,
- `return_to` is respected after signing in,
- a `return_to` with a foreign domain is **rejected**,
- the feed sends title and excerpt, not the body.

The last one is the most valuable: the feed is the easiest way to
accidentally bypass the lock. As long as a test says "the feed must not
contain the body of a bonus article", you can sleep easy.

## What to take away

1. **Members-only is one flag** ... `members_only: true` in the front
   matter. Nothing more is needed to make an article "a bonus".
2. **The teaser is the excerpt** ... the lock is a separately written
   invitation, not a truncated body.
3. **The body is never rendered for a guest** ... no CSS/JS hiding tricks
   that could be bypassed. For a guest the content doesn't exist.
4. **The feed is the weakest link** ... the feed sends only title and
   excerpt, and a test guards it.
5. **`return_to` is validated** ... after signing in it takes you back to
   the article, but never to a foreign domain.

And if you got here without signing in ... now you know what's waiting
for you. Sign in and read the rest. 

## Sources

- [W3C — ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance) — ACID definition
- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html) — SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
