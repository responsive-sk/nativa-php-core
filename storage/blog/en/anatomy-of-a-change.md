---
title: "OpenSpec for kids ... part 2: anatomy of a change ... five files, five questions"
date: "2026-08-09"
version: "1.0.0"
tags: ["openspec", "php", "workflow"]
excerpt: "We break down the archived change 2026-08-09-blog-author-linking file by file. Each file answers one question ... and together they tell the story of a single change."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "anatomia-change-pre-deti"
published: true
---

> This is part 2 of the **OpenSpec for kids** series. In [part 1](/en/blog/what-is-openspec)
> we explained what OpenSpec is and why changes aren't written directly into
> the spec. Today we open a real archived change and look at what's in each
> file and why. In [part 3](/en/blog/lifecycle-of-a-change) we'll then walk
> the whole path from idea to archive.

## We open the archive drawer

In `openspec/changes/archive/` there are eight archived changes. Let's pick
the freshest one ... `2026-08-09-blog-author-linking`. It was a small change:
linking the article author with a real users account. Exactly the kind of
change that looks like "three lines of code" ... and yet it has five files:

```text
openspec/changes/archive/2026-08-09-blog-author-linking/
├── .openspec.yaml        # 1. WHAT is it?
├── proposal.md           # 2. WHY are we doing it?
├── design.md             # 3. HOW exactly?
├── tasks.md              # 4. WHAT EXACTLY needs doing?
└── specs/starter-kit/    # 5. HOW DO WE VERIFY it works?
    └── spec.md
```

Each file answers one question. None of them is redundant, and each one can
be read on its own, without the rest.

## 1. `.openspec.yaml` ... WHAT is it?

A meta file. At first glance it tells you what kind of change this is, when
it was created and where it belongs:

```yaml
schema: spec-driven
created: 2026-08-09
goal: >-
  Link blog article authors to real user accounts. Articles stay plain .md
  files ... a new optional author_email key is resolved at render time
  against users.db ...
```

`goal` is **one paragraph that must survive even a year from now**. When
someone sees an unknown change in the archive in six months, they read the
goal and immediately know what it was about. There's nothing more in this
file ... and there shouldn't be.

## 2. `proposal.md` ... WHY are we doing it?

This is where every change starts. First the original request (a quote from
the conversation), then why we're solving it at all and above all ... what we
deliberately DON'T do:

```text
# Proposal: Blog article authors linked to real user accounts

## Original Request   → quote: "How would we link the article author with a
                         real users account?"
## Why                → the author was free text: renaming a user didn't
                         propagate, deletion left dead attribution
## Approach           → optional author_email in front matter + resolver
## Non-goals          → no author pages, no admin picker,
                         files stay the source of truth
## Security           → prepared statement, email is never rendered
## Plan settings      → testing: yes, logging: no, docs: yes
```

**Non-goals are the most important section.** They say what we deliberately
didn't do ... and why. Without them every change would slowly bloat: "since
we're already doing the author, let's add an author page… and an avatar…
and comments". Non-goals keep a change small and finished.

## 3. `design.md` ... HOW exactly?

This is where the technical details are decided. For this repo the most
important decision was: **we link the author via email, not via user id**.
And why?

```text
## Why email, not user id

- make db-reset and dist build regenerate EVERY user id as new random hex
  → an id in a committed file would never match a fresh database
- emails are seeded consistently (admin@example.test) and are unique
- an email can be naturally typed by hand into a .md file
```

This is exactly the kind of knowledge you don't see in code ... but it
survives in design.md. The design also contains the decision chain
(fallback chain), a table of edge cases and one architectural decision:

```text
Where does the code live? The blog lives in Modules\Core ... the shared
kernel. Importing the Users module from there would reverse the dependency
direction. → the resolver uses the shared kernel (Database) with its own
prepared statement, no Users import.
```

A design isn't an essay ... it's a **record of decisions and their reasons**.

## 4. `tasks.md` ... WHAT EXACTLY?

A checklist with precise file paths. Every task is verifiable and you know
when it's done:

```text
## Implementation
- [x] src/Modules/Core/Application/Services/BlogAuthorResolver.php (new)
- [x] BlogIndexAction + BlogArticleAction ... wire in the resolver
- [x] storage/blog/hello-world.md ... document the convention

## Tests
- [x] BlogAuthorResolverTest (6 tests: active/unknown/inactive user…)
- [x] BlogIndexTest ... fixture article with author_email

## Quality gates
- [x] vendor/bin/phpunit ... 172 tests OK
- [x] composer phpstan, cs-fix-dry, rector-dry ... clean

## Commit plan
- Commit 1: implementation · Commit 2: tests · Commit 3: docs and article
```

Everything ticked = the change is done. `tasks.md` is also the **evidence
for verification** ... nobody has to guess whether something was forgotten.

## 5. `specs/starter-kit/spec.md` ... HOW DO WE VERIFY it works?

The heart of OpenSpec. A new requirement for the canonical spec, written as
verifiable scenarios ... not as "it needs to be done":

```text
### Requirement: Blog article authors linked to user accounts

#### Scenario: Author resolves to the linked user's name

- GIVEN an article with author_email: eva@example.test and an active user
       named Eva Author
- WHEN /blog or /blog/{slug} is rendered
- THEN the name Eva Author is displayed
```

Four scenarios cover four worlds: the user exists, the user doesn't
exist/is inactive, the email is never displayed, the database is missing.
Each scenario can be **run as a test** ... and it really is run:
`BlogAuthorResolverTest` tests exactly the same cases.

## What it all means

Five files, five questions. But notice the order: **first why, then how,
then verification, and only at the end what.** The spec and the tests come
into existence before the first line of code is written. That's why those
"three lines of code" look like this:

```php
// BlogAuthorResolver ... the single method that closes the whole story
public function resolve(string $email, string $fallback): string
{
    try {
        $stmt = $this->manager->connection('users')->prepare(
            'SELECT name FROM users WHERE email = :email COLLATE NOCASE
             AND is_active = 1 LIMIT 1',
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
    } catch (\PDOException) {
        return $fallback;   // users.db missing? The blog must always render
    }

    // hit → name from the database, miss → fallback to author
    return is_array($row) && is_string($row['name'] ?? null) && '' !== $row['name']
        ? $row['name']
        : $fallback;
}
```

Small code, big story. And that story is fully written down in five files ...
so that anyone can read it a year from now. In [part 3](/en/blog/lifecycle-of-a-change)
we'll show how that story unfolds over time: from the idea, through
implementation and quality gates, to the move into the archive.

## Sources

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definition
- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
