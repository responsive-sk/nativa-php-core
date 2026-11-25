---
title: "Performance for kids ... part 5: members-only bonus articles as a business pattern"
date: "2026-08-13"
version: "1.0.0"
tags: ["performance", "business", "members-only", "php"]
excerpt: "The final part of the series changes the subject: we built a fast website ... now what? Members-only bonus articles as a business pattern: why gate part of the content behind registration, what it costs (one flag, no paywall) and when it\'s actually a bad idea."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "performance-pre-deti-diel-5-members-only-biznis"
published: true
---

> This is part 5 of the **Performance for kids** series. The first four
> parts were about speed: LCP on the index ([part 1](/en/blog/lcp-srcset-basics)),
> on the article page ([part 2](/en/blog/article-cover-lcp)),
> reading Lighthouse critically ([part 3](/en/blog/reading-lighthouse-report))
> and text-based LCP ([part 4](/en/blog/text-lcp)).
> Today we change the subject. We already have a fast website ... and what
> do we do with the traffic it brings? We look at **members-only bonus
> articles as a business pattern**: the first "earning" piece of this
> blog, which costs exactly one flag in the front matter.

## Speed is a prerequisite, not a goal

The first four parts of this series pretend that performance is the
goal. But speed is not the goal ... it is a **hygiene factor**. Nobody
praises a website for being fast; people only notice when it is not.
A fast website is like a clean kitchen in a restaurant: nobody writes
a review about it, but nobody would come to a dirty one.

Speed brings you traffic (or at least doesn't drive it away). And then
comes the question speed cannot answer: **what do you do with those
people?** A visitor who reads an article and leaves is a statistic. A
visitor who comes back is a reader. And a visitor who registers is a
**relationship**.

A business pattern is exactly this: a mechanism that turns anonymous
traffic into something of value.

## Freemium without a paywall: registration as the gate

Members-only bonus articles are not a paywall. A paywall sounds like
"pay or don't read" ... members-only sounds like "register and get a
bonus". The difference is in psychology and in technology:

| | Paywall | Newsletter | **Members-only** |
|---|---|---|---|
| Gate | money | email | **account** |
| Value for the reader | content | content by email | **content + member status** |
| Value for you | money | email | **account + email + conversation** |
| Infrastructure cost | payment system | email provider | **0 (registration already exists)** |

The last point is key: this blog already has registration, login and
roles. Members-only adds **no new infrastructure** ... it just uses what
is already there. The first members-only article cost less than one
chapter of this series.

## What it looks like in practice

In the [bonus article](/en/blog/bonus-members-only) ... which is,
naturally, itself members-only ... you will find the technical details.
Here is the outside view, how the pattern looks for a reader:

1. On `/blog` they see a card with a small **MEMBERS ONLY** badge ...
   the bonus is visible, but not annoying.
2. They click the article and instead of the body they see a **teaser**
   ... a separately written invitation, not a truncated text ... and two
   buttons: *Sign in* and *Create account*.
3. They sign in and the server **returns them exactly to the article**
   they wanted to read (`return_to`). It unlocks as a whole.

Notice what didn't happen: no payment prompt, no "trial ended", no
paywall pop-up. The reader got a reward for signing in ... and the blog
got an account it can reach out to again.

## Why it works even for a small blog

Business patterns usually sound like plans for big companies:
"monetize your audience through a multi-level freemium funnel". But
members-only has one property that makes it suitable for a small
project too: **it scales from zero.**

- **Before registration:** the blog stays public, as before. Nothing
  breaks, SEO remains.
- **After the first registration:** the first bonus article is a
  reward for the first member. It works exactly the same as for
  thousands.
- **After every registration:** when an article is added, a reason to
  come back is added too.

No threshold, no "enough readers". The pattern works from day one ...
which is exactly what you want from a pattern for a small project.

## What it costs (technically)

The whole feature can be summarized in four points:

1. **One flag in the front matter** ... `members_only: true` in YAML.
2. **Teaser page** ... when a guest opens a gated article, they get the
   excerpt + a prompt. The body is never rendered (no CSS/JS tricks
   that could be bypassed).
3. **Lazy auth** ... the article page only asks about the login when it
   hits a gated article; plain articles render nothing extra.
4. **Tests** ... eight tests guard that the gate never leaks: through
   the feed, through search, through a malformed `return_to`.

And the best part: **most of the work was already done.** Registration,
login, sessions, module isolation ... all of that existed before. The
pattern only added the smallest thing: a flag, a gate and tests.

## When it does not work

No pattern is universal. Members-only makes sense when you have
**content people really want** and **a reason to collect accounts**.
It is nonsense in three cases:

1. **When you don't have enough content.** Locking the only good
   article is a punishment for the visitor, not a reward. A gate
   works when the bonus is above the standard, not instead of it.
2. **When content is your main SEO channel.** Gated articles are not
   indexed for readers ... if every tenth article is gated and you get
   nothing for it, you are losing.
3. **When you have nothing to do with the account.** An account
   without follow-up (comments, newsletter, another bonus) is a dead
   account. Registration must lead somewhere.

## What to take away

1. **Speed is a prerequisite, not a goal** ... performance brings
   people in, but doesn't keep them.
2. **Members-only is not a paywall** ... it is a reward for
   registration, and registration is a relationship.
3. **The pattern scales from zero** ... it works for the first member
   and for thousands, without new infrastructure.
4. **The cheapest pattern is the one that uses what you have** ...
   registration already existed, the flag is free.
5. **The gate must be tested** ... the feed and search are the easiest
   way to accidentally bypass it.

The Performance for kids series closes with this part: we built a fast
website (parts 1–4) and gave it a reason to come back (part 5). And if
you want to see the gate from the inside ... sign in and read the
[bonus article](/en/blog/bonus-members-only). It is the first
article that complements this series with the experience we talked
about here.

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
