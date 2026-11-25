---
title: "Clean code for kids ... part 3: one thing (SRP) ... a method and a class do only one thing"
date: "2026-08-11"
version: "1.0.0"
tags: ["clean-code", "php", "architecture", "refactoring"]
excerpt: "One method, one class ... one thing. Part 3 explains why 'this does A, B and C' is a smoke signal, how handle() split into helpers and why BlogArticleAction, BlogAuthorResolver and AuthorAvatar are three classes with three jobs."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "jedna-vec-srp-pre-deti"
published: true
---

> This is part 3 of the **Clean code for kids** series. In [part 1](/en/blog/clean-code-part-1)
> we said why code is read more than it's written, and in [part 2](/en/blog/names-are-documentation),
> why names are documentation for free. Today we look at the principle
> that sounds the simplest and gets violated the most often: **one
> method, one class ... one thing.** If you read the [types series](/en/blog/chunky-rule),
> you'll recognize a continuation: there we said why 60 lines, today we
> say what those 60 lines actually measure ... and that it applies to whole
> classes too.

## "This does A, B and C" ... smoke signal number one

The fastest test of whether code is okay goes like this: **can you say
in one sentence what a method or class does?** If that sentence contains
"and" or "then" ... you have a problem.

We call this a **smoke signal** ... a warning sign that something is going
on in the code. (The original English term is Smoke Test; the point
isn't that the code stinks, but that it warns you in time.)

- "That method validates the slug, reads the file, parses YAML and
  renders HTML…"
- "That class manages users, sessions and mail…"

Every "and" means **one extra thing**. And a method that does three
things has three reasons it can break ... and three reasons someone must
read it. Code is written once, read ten times (part 1), so every extra
thing you pay nine times over when reading.

## SRP on a method: `handle()` tells, helpers do

The Single Responsibility Principle ("one responsibility") applies at
two levels. The first is the method. The project rule enforces it with
the chunky rule ... a method must fit on one screen (that was the [final
part of the types series](/en/blog/chunky-rule), I won't repeat
it). What matters is **what that screen measures**: when a method
doesn't fit, it usually means it's not doing one thing, but several.

Look at `handle()` in `BlogArticleAction` ... the method that serves the
article page `/blog/{slug}`. It's all in [part 1](/en/blog/clean-code-part-1),
here just notice its heart:

```php
public function handle(Request $request): Response
{
    // … slug validation, file reading, front matter …

    $resolver = new BlogAuthorResolver(DatabaseConnectionManager::instance());
    $avatar = new AuthorAvatar(new LocalStorageProvider());

    $metaLine = $this->metaLine($meta, $this->authorHtml($meta, $resolver, $avatar));
    $tagChips = $this->tagChips($meta);
    $coverImage = $this->coverImage($slug, $meta);

    // … assembling the page from parts and rendering …
}
```

`handle()` doesn't **make** the meta line, tags or cover image. It only
**asks** for them. Each thing has its own method that answers one
question:

| Call | Question it answers | Where the answer lives |
|---|---|---|
| `metaLine()` | What's the date, category and author (already rendered)? | filter of empty values + join |
| `tagChips()` | Which tags and how to render them? | normalization + HTML chips |
| `coverImage()` | Which cover image and with what attribute? | decision in `resolveCover()` + `<img>` render |
| `authorHtml()` | What does the author look like (link + avatar)? | just composes: `BlogAuthorResolver` (data) + `AuthorAvatar` (markup) |

The story is "what happens", the details are "how" ... and the "how" is
always around the corner, in a method whose name says what you'll find.
That's exactly what we learned in part 2: a name as documentation.

## SRP on a class: three classes, three jobs

The second level is the class. The same rule applies, just on a bigger
scale: **every class has one reason to change.** If you change a class
because of an avatar design **and** because of a SQL query on the user,
it has two reasons ... and that's two classes.

In the project this isn't theory but a trio of classes that collaborate
on the article page:

| Class | Does only | Changes when |
|---|---|---|
| `BlogArticleAction` | assembles the page from parts | page structure / flow |
| `BlogAuthorResolver` | resolves email → author profile (SQL) | `users` schema / query |
| `AuthorAvatar` | produces avatar markup (HTML) | avatar design / placeholder |

`BlogAuthorResolver` knows nothing about HTML ... its only job is a
database query:

```php
final readonly class BlogAuthorResolver
{
    /**
     * Resolve an email to the linked active user's public profile.
     *
     * @return array{id: string, name: string, avatar: ?string}|null
     */
    public function profile(string $email): ?array
    {
        $row = $this->lookup(
            'SELECT id, name, avatar FROM users WHERE email = :email COLLATE NOCASE AND is_active = 1 LIMIT 1',
            [':email' => $email],
        );

        // … null = fallback to plain author text …
    }
}
```

`AuthorAvatar`, in turn, knows nothing about the database ... its job is
purely markup:

```php
final readonly class AuthorAvatar
{
    /**
     * Avatar <img> ... the stored file when it exists, else the placeholder.
     */
    public function img(?string $avatar, string $name, int $size = 20): string
    {
        $src = null !== $avatar && $this->storage->exists($avatar)
            ? $this->storage->url($avatar)
            : $this->placeholderDataUri($name);

        return '<img class="st-author-avatar" src="' . FrontendLayout::e($src) . '" alt=""'
            . ' width="' . $size . '" height="' . $size . '" loading="lazy">';
    }
}
```

Why is this a good split? **Because the two things change for different
reasons.**

- When you change the avatar design (placeholder, size, class), you
  touch `AuthorAvatar`. `BlogAuthorResolver` and the SQL don't move.
- When you change the `users` schema (add a column), you touch
  `BlogAuthorResolver`. The avatar markup doesn't move.
- When you change the page structure, you touch `BlogArticleAction`.
  Both services don't move.

Every change has **exactly one place** where it's made. That's SRP in
practice.

## The smoke signal it came from

What would the code look like without this split? (This is an
illustration, not exact history ... in reality both classes were born
precisely from this split.) A method that wanted to be `authorHtml()`
would look roughly like this (abbreviated):

```php
private function authorHtml(array $meta, BlogAuthorResolver $resolver, AuthorAvatar $avatar): string
{
    // 1. load the fallback name from front matter
    // 2. load the email from front matter
    // 3. ask the database for the profile
    // 4. no email ... return the escaped name
    // 5. no profile ... return the fallback
    // 6. build the link HTML
    // 7. build the avatar HTML (file? placeholder?)
    // 8. put it together
}
```

Eight steps, two topics (database data + HTML). If you wanted to change
the placeholder in a year, you'd have to read all eight steps, even
though only step 7 concerns you. And if the SQL broke, you'd hunt for
the bug among HTML strings. The split into `BlogAuthorResolver` (data)
and `AuthorAvatar` (HTML) is exactly what fixed this ... each of the two
methods now has one job:

```php
// BlogArticleAction ... only knows that both things exist
$resolver = new BlogAuthorResolver(DatabaseConnectionManager::instance());
$avatar = new AuthorAvatar(new LocalStorageProvider());
$metaLine = $this->metaLine($meta, $this->authorHtml($meta, $resolver, $avatar));
```

## When is SRP overdone?

And now the important warning, because every rule can be abused. **SRP
doesn't mean "as many small methods as possible".** This is not SRP:

```php
private function getTitle(array $meta): string
{
    return (string) $meta['title'];
}
```

This is over-complication ... a method that just returns a value doesn't
answer a new question, it just moves a line. The difference between good
and bad splitting is **the reason to change**:

- Good split: each method/class changes for a different reason.
- Bad split: you chop one thing into ten pieces and every change
  touches you in all ten.

SRP also doesn't mean "every responsibility must be a class". Sometimes
a thing is so small that a constant or a simple method represents it ...
about when an abstraction (interface, class) is premature and when it
makes sense is exactly [part 4](/en/blog/dry-vs-abstraction).

## Summary of part 3

1. **The one-sentence test** ... when you say a method/class "does A, B
   and C", that's three methods/classes.
2. **SRP on a method** ... `handle()` tells the story, `metaLine()`,
   `tagChips()`, `coverImage()` do the work (the chunky rule enforces
   it).
3. **SRP on a class** ... `BlogAuthorResolver` handles only data,
   `AuthorAvatar` only markup, `BlogArticleAction` only composition.
   Every change has one place.
4. **The reason to change is the measure** ... not the line count. Split
   where things change for different reasons, not to have many small
   methods.
5. **Link to the next part** ... when you feel the temptation "I'd
   extract this into a shared class", come to [part 4](/en/blog/dry-vs-abstraction):
   DRY yes, premature abstraction no.

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse tool
