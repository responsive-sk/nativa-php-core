---
title: "Rust & Lekalo ... part 16: comments — the last chain still waiting for a manifest"
series: "Rust & Lekalo"
series-part: "16"
date: "2026-09-27"
version: "1.0.0"
published: true
tags: ["rust", "lekalo", "trace-manifest", "comments", "openspec"]
excerpt: "The Lekalo model holds seven modules. Six of them have a trace manifest — a chain tying the spec to the tests. Comments are the seventh. Their chain runs through a waiting room: a guest writes, an admin decides, the article shows it. Only then comes the manifest."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-comments-pre-deti"
---

> [Part 15](/en/blog/the-trace-manifest-a-chain-with-hashes-on-both-ends)
> built the trace manifest — a chain with hashes on both ends.
> Today 16 — comments. The module that has no chain yet, and despite
> that it is the module whose story you know best. Because you lived
> it. Have you ever commented on anything on the web?

The Lekalo model holds seven modules. `users`, `blog`, `audit`,
`pages`, `themes`, `design`, `comments`. And now a little count: six
of them already have a chain —
[users](/en/blog/lekalo-semantic-model),
[blog](/en/blog/lekalo-model-grows),
[audit](/en/blog/the-trace-manifest-a-chain-with-hashes-on-both-ends)
and
[pages](/en/blog/the-trace-manifest-a-chain-with-hashes-on-both-ends),
themes and design (the last two share one manifest). Six chains in
five documents where there is no sentence saying "tests guard
something", but data: node by node, relation by relation, hash by
hash.

One remains. `comments`. The only module under `lekalo/modules/`
whose manifest does not lie in `docs/lekalo/trace/`.

And here is the thing I find most fun about it: comments is precisely
the module whose model is the youngest and the poorest. It has three
files — commands, entities, policies. It has no events, no scenarios,
no endpoint bindings. What that means, we will see in a moment.
First, the story.

## The guest, the waiting room and the article

A comment on this blog lives a life in three acts. It is not
complicated at all — and that is exactly why it makes a good story
for a model.

Act one: **the guest writes**. A person without an account comes to
an article, fills in a name, an email and the text. The form flies to
`POST /blog/{slug}/comments`. Three guards stand behind it. The CSRF
token — the site wants proof the form was sent by our site. The
per-IP throttle — the same address cannot flood the waiting room with
a hundred texts in a minute. And a third thing the guards don't
decide: does the article the comment belongs to even exist?

Until all three pass, the comment **does not exist for the world
yet**. It is born in the database with the status `pending`. The
waiting room. The guest sees a confirmation — "thank you, your
comment awaits approval" — but the article does not show it. Ever.
Not for a second.

Act two: **the admin decides**. In `/mark/comments` stands the queue
of the waiting. Approve. Reject. Delete. Three buttons, three
endpoints, and none of them does anything but change one column:
`pending` → `approved` or `pending` → `rejected`. Delete is the
exception — the row disappears forever and its identifier never
answers again.

Act three: **the article shows**. Only comments with the status
`approved` are picked up and rendered by the article. Rejected ones
hide. Pending ones hide. The world sees only what passed through the
waiting room.

## And now the model

The story above is the runtime — hand-written PHP. The Lekalo model
writes it in a different language: declarations. And here we return
to the sentence I postponed: the comments model has three files, but
it is missing three more.

In [part 15](/en/blog/the-trace-manifest-a-chain-with-hashes-on-both-ends)
we said the chain runs through **symbols** — declarations in the
model. Comments has them: `comments.submit`, `comments.approve`,
`comments.reject`, `comments.delete`, and with them the effects —
insert a row, change the status, remove the row. The policies are the
three guards from act one: the throttle, the identity, the existing
article.

What doesn't the model know? It cannot say **what happens when a
command passes**. Audit can — `audit.entry_appended`. In comments it
would be `comments.comment_created`: the guest wrote, the row stands
in the waiting room. It cannot say **how the flow looks from start to
finish** — a `moderation_lifecycle` scenario tying all three acts
into one story. And it cannot say **where the chain begins on the
web** — a binding saying "`comments.submit` lives on
`POST /blog/{slug}/comments`".

Three holes. Exactly the ones audit and pages received in part 15 —
`events.yaml`, `scenarios.yaml`, `bindings.yaml`. File file file,
three times three definitions. And after filling them in, a small
miracle happens: the model suddenly knows as much about comments as
it knows about audit.

## Why this is the last chain

After those three files land, one step remains: the manifest. The
chain — requirement → symbol → file → scenario → test → gate —
written into `docs/lekalo/trace/comments.trace.json`.

Two requirements from the spec. The first says: a guest may comment,
the comment waits in the queue, the admin decides. The second says:
the admin has an interface for it. The symbols are the ones you read
above. The artifacts — the files carrying them: `CommentsService`,
the repository, the actions for submit and for moderation. The
scenario — the story of the three acts. The tests? `CommentsTest` and
`CommentsMetricTest`. The gate? `nativa.phpunit`, the same as for all
the other chains.

And when the manifest stands, something the series has been asking
for since [part 15](/en/blog/the-trace-manifest-a-chain-with-hashes-on-both-ends)
happens: **one hundred percent coverage**. Seven modules in the model,
seven chains in the docs. No module remains just a story — each one
has a chain saying what covers the requirement, not just that it is
covered.

## The honest section

First honesty: a poor model is not a bad model. Ten new definitions
do not make comments more important — they make it **describable**.
The difference matters. The model is not a polite data dump about the
code; it is a map you can find a path in.

Second honesty: the story of the three acts is a simplification. The
real code has more branches — what happens when the guest leaves the
email empty, when the text exceeds the limit, when an admin approves
a comment under an article that vanished in the meantime. The
scenario in the model carries the main flow, not every branch. That
is not a lie — that is selection. A map carrying everything would be
the terrain.

Third honesty: the manifest does not exist yet. This article is a
story about what the chain will be — not proof that it stands. Proof
arrives with the JSON in `docs/lekalo/trace/`, and only when the
guard test recomputes its hashes. Until then it is a promise. And
promises in this series always cost something — exactly like in
[part 15](/en/blog/the-trace-manifest-a-chain-with-hashes-on-both-ends),
where a promise from part 10.2 ripened five parts later.

## What comes next

The manifest. The chain with hashes for comments, the last of the
seven. When it lands, the last hole in the model's coverage closes —
and the series can tell the next story about something new, not
something missing.

The rule to take home: the model does not describe the code, the
model describes the **flow**. While you have only commands, you see
verbs. When you add events, scenarios and bindings, you see the
story — who triggered what, what happened, where it lives on the
web. And only when you see the story can you write it down as a
chain with hashes that a machine guards.

## Sources

- [Lekalo](https://github.com/ichinya/lekalo) — the project repo (`lekalo load --ir --json`, `trace validate`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — the IR digest recipe and model conventions
- [Part 15 — the trace manifest](/en/blog/the-trace-manifest-a-chain-with-hashes-on-both-ends) — a chain with hashes on both ends
- `tests/Integration/CommentsTest.php` — the moderation chain tests the manifest will prove
