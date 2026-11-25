---
title: "Member bonus ... comments: strangers write, you publish"
date: "2026-09-17"
version: "1.0.0"
tags: ["comments", "moderation", "php", "security", "architecture"]
excerpt: "The fourth bonus for registered readers. The comment form is the one place where this app trusts strangers ... and it trusts them with zero. Why every comment starts as pending, why the rate limit runs before validation, why a guest's email is never displayed, and what moderation looks like in /mark/comments ... all on real code from this repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-komentare-pre-deti"
published: true
---

> This is the fourth **member bonus**. Previous parts: [the members-only
> lock](/en/blog/bonus-members-only), [the immutable audit
> log](/en/blog/bonus-audit-log) and [full-text search via
> FTS5](/en/blog/bonus-full-text-search). Today comes the form that is the
> only write path open to strangers ... comments. How do you write
> something a stranger wrote into your own database without regretting it?
> That's what this part is about ... on real code.

You're a developer. Your app reads data from its own database,
authenticates its own users, renders its own templates.

All of those relationships share one thing: you hold the control.

Then you place a form under an article that anyone in the world can fill
in. Name, email, text ... submit. One click and something foreign flies
into your database.

The comment form is the only place in the entire app where a stranger
gets the means to write. From that angle it's the riskiest spot of the
whole project ... and precisely for that reason, in this repo, it starts
with zero trust.

## A guest's name is a claim, not a fact

A comment can come from a signed-in reader or a guest. The difference
isn't the form ... it's where the name comes from:

| | Guest | Signed-in reader |
|---|---|---|
| Name | `guest_name` ... the submitter's claim | account name ... a fact from the database |
| Email | `guest_email` ... kept for moderation, never displayed | from the account, equally private |
| Identity in the record | none | `user_id` |
| Status after submit | pending | pending |

The `Comment` entity carries this in its constructor, and one of its
methods matters more than it looks:

```php
// src/Modules/Comments/Domain/Entities/Comment.php
/**
 * Display name — the name captured at submit time (the signed-in user's
 * account name or the guest's display name). Never the email.
 */
public function displayName(): string
{
    $name = $this->guestName;

    return null !== $name && '' !== $name ? $name : $this->id;
}
```

"Never the email." The guest's email exists for exactly one purpose ...
so the admin can see it while moderating. It never reaches the page.

What gets displayed is the name entered at submit time, or an anonymous
identifier when no name was given. A guest claiming to be the King of
Belgium can sign the page as the King of Belgium ... the name shows up,
the email never does.

A claim stays a claim. Only a moderator can turn it into a fact.

## Pending is the default

No spam detection, no scoring, no "looks civil enough". The entity knows
nothing between submission and publication:

```php
// src/Modules/Comments/Domain/Entities/Comment.php
public const string STATUS_PENDING = 'pending';

public const string STATUS_APPROVED = 'approved';

public const string STATUS_REJECTED = 'rejected';
```

`Comment::create()` sets the initial status to `pending` ... no
exceptions, for guests and signed-in users alike. Only `approved`
comments render on the public page.

Notice what this design does to responsibility. Publishing is not the
absence of spam ... publishing is a decision somebody made.

The phrase "let's auto-approve, spam will be rare anyway" is exactly the
opposite: a decision not to make the decision.

## The rate limit runs before validation

There's one detail in `CommentsService` that's easy to write backwards:

```php
// src/Modules/Comments/Application/Services/CommentsService.php
private const int RATE_LIMIT = 10;

private const int RATE_WINDOW = 60;

// Rate limit first — invalid payloads must not bypass throttling.
if (!$this->isAllowed($slug, $ip)) {
    return ['ok' => false, 'errors' => ['Too many comments — try again in a minute.']];
}

$errors = $this->validate($slug, $userId, $guestName, $guestEmail, $body);
```

The limit is 10 comments per 60 seconds, keyed on IP + slug. But the
order is what matters: **the limit runs before validation**.

If it were the other way around, an attacker could send garbage
payloads ... an invalid email, an empty body ... and validation would
reject them before the limit was ever touched. Spam would never pay the
toll.

This way every attempt pays. Including the invalid one ... especially
the invalid one.

And one exception that is explicitly written in the code: loopback
addresses (`127.0.0.1`, `::1`, `localhost`) are exempt from the limit so
local development isn't throttled. Production gets no such exemption.

## PRG ... old input is never lost

The docblock of `CommentSubmitAction` spells out the flow: PRG
... Post/Redirect/Get. On success, a redirect back to the article:

```php
// src/Modules/Comments/Interfaces/HTTP/Actions/CommentSubmitAction.php
if ($result['ok']) {
    return Response::redirect('/blog/' . $slug . '#comments');
}
```

On validation failure the article renders again ... with the form errors
inline and the old input passed through request attributes. A reader
never retypes a comment just because they mistyped their email.

And CSRF? The action doesn't deal with it ... `CsrfMiddleware` enforces
the token globally. The action stays clean: slug, inputs, service,
redirect.

## Moderation ... /mark/comments

The pending queue lives in the admin section `/mark/comments`:

- **approve** ... the comment appears under the article,
- **reject** ... it disappears from the public page but stays in the
  database,
- **delete** ... it's gone for good.

Every admin decision lands in the immutable audit log ... who, what,
when ... exactly as described in [the second
bonus](/en/blog/bonus-audit-log). Moderation is not an exception to the
repository's rules; it's another one of their clients.

And as everywhere in this repo: belief is replaced by tests.
`CommentsTest` runs on hermetic scratch DBs (`DB_COMMENTS`) and guards
the whole flow ... a guest submits a pending comment and gets a
redirect, unapproved content never renders for guests, an admin approves
and the comment appears.

## When this model doesn't hold

Be honest: pending-by-default is not a model for every website.

It's a contract that trades immediacy for safety. A real-time community
... a chat, a forum with active threads ... couldn't live with it. In
this model there are no replies to comments (the entity has no
`parent_id`), no notifications and no spam filter ... the moderator is
the filter.

A second honesty: an IP-keyed limit means everyone behind one address
(a school, an office) shares one quota. For a members' blog with a few
dozen comments a day that's the right trade. For a portal with millions
... not.

## What to take away

1. **The comment form is the only write path for strangers** ... so it
   starts with zero trust, not optimism.
2. **A guest's name is a claim** ... the name displays, the email never
   does; the email is the moderator's tool.
3. **Pending is the default** ... publishing is a decision, not the
   absence of spam.
4. **The rate limit runs before validation** ... otherwise invalid
   payloads bypass the limit for free.
5. **PRG + old input** ... no double submissions, no lost text on error.

→ [The members-only lock](/en/blog/bonus-members-only): one flag in the front matter
→ [Immutable audit log](/en/blog/bonus-audit-log): every admin decision is recorded
→ [Full-text search via FTS5](/en/blog/bonus-full-text-search): an index in memory, no infrastructure

## Sources

- [OWASP, Automated Threats to Web Applications](https://owasp.org/www-project-automated-threats-to-web-applications/) ... the threats a rate limit stands against
- [MDN, Post/Redirect/Get](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Location) ... the pattern behind the form flow
- [PHP Manual](https://www.php.net/manual/) ... official PHP documentation
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
