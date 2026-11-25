---
title: "Clean code for kids ... part 1: what clean code is and why it's read more than it's written"
date: "2026-08-11"
version: "1.0.0"
tags: ["clean-code", "php", "architecture", "refactoring"]
excerpt: "A new series. Clean code isn't aesthetics ... it's maintainability. Part 1 explains why code is read ten times more often than it's written, shows the map of the whole series and the first real example from this repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "co-je-cisty-kod-pre-deti"
published: true
---

Have you ever looked at code you wrote a year ago and didn't recognize it? Not because it was bad. But because you weren't in it. And now imagine someone reading it for the first time. Without you. Without context. With only what you wrote.

## A recipe nobody reads

Imagine writing a recipe. When you write it, you know exactly what "a pinch of salt" means ... you just invented it. But six months later someone else (or you) comes along and tries to cook from it. "A pinch" won't be so clear to them anymore.

It's the same with code, only stricter. **Code is written once, but read a hundred times** ... during code review, when hunting a bug, when adding a new feature, when refactoring. Everyone who reads your code pays for your carelessness with their time.

And now the most important part: that reader **can't call you up** and ask what you meant. All they have is what you wrote.

## When the name speaks for itself

Try reading this line from this project:

```php
$tagChips = $this->tagChips($meta);
```

What does it do? **You know without reading the method body.** The name says it: "build tag chips from metadata." The story is clear from the call alone.

And now look at the whole `handle()` method in `BlogArticleAction` ... that's the heart of the article page `/blog/{slug}`:

```php
public function handle(Request $request): Response
{
    // 1. Load and validate the slug
    $slug = (string) ($request->routeParam('slug') ?? '');
    if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
        return $this->notFound($request->getPathInfo());
    }

    // 2. Load the article file
    $file = AppPaths::instance()->storage('blog' . DIRECTORY_SEPARATOR . $slug . '.md');
    if (!is_file($file)) {
        return $this->notFound($request->getPathInfo());
    }

    $source = file_get_contents($file);
    if (!is_string($source)) {
        return $this->notFound($request->getPathInfo());
    }

    // 3. Get the metadata (title, excerpt, …)
    $renderer = new MarkdownRenderer();
    $parsed = $renderer->extractFrontMatter($source);
    $meta = $parsed['meta'];

    $title = is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug);
    $excerpt = is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '';

    // 4. Assemble the page parts ... each one is named
    $resolver = new BlogAuthorResolver(DatabaseConnectionManager::instance());
    $avatar = new AuthorAvatar(new LocalStorageProvider());
    $metaLine = $this->metaLine($meta, $this->authorHtml($meta, $resolver, $avatar));
    $tagChips = $this->tagChips($meta);
    $coverImage = $this->coverImage($slug, $meta);

    // 5. Wrap in the layout
    $content = '<article class="st-article">'
        . '<header class="st-article__header">'
        . $coverImage
        . '<h1 class="st-article__title">' . FrontendLayout::e($title) . '</h1>'
        . '<p class="st-article__meta">' . $metaLine . '</p>'
        . '</header>'
        . $renderer->render($parsed['body'])
        . $tagChips
        . '</article>';

    return Response::html(FrontendLayout::render(
        $title . ' ... Nativa Starter',
        $content,
        description: $excerpt,
        currentPath: $request->getPathInfo(),
    ));
}
```

Notice the story. You don't need to know **how** tag chips are made to understand **what's** happening:

1. load and validate the slug → 2. load the file → 3. get the metadata → 4. assemble the parts → 5. wrap in the layout.

The details ("how?") live in named helpers: `metaLine()`, `tagChips()`, `coverImage()`, `authorHtml()`. Each of them answers one question. And `handle()` just **tells the story** ... no scrolling, no hunting, no holding context in your head.

## Why a test, not a discussion?

Here's where this starter kit differs from most projects. Most teams write "clean code guidelines" into a document and hope people follow them. This project wrote **tests that enforce the rules** ... break one and the build fails. It's not a "recommendation", it's a **red test**.

Imagine a code review without the chunky test:

- Reviewer: "That method is 120 lines, but…"
- Author: "But it's logical, everything's related!"
- Reviewer: "I think it could be split…"
- Author: "I'll leave it for the refactor, I have a deadline."

And that's the end. Everyone has an argument, nobody's right, the method stays. When the rule is **automatic**, there's nothing to discuss: the test fails, you split the method, the build passes. Done.

Look at everything `tests/Architecture/CorePatternTest.php` guards:

| Rule | What it forbids | How the test looks for it |
|---|---|---|
| No methods over 60 lines | "chunky rule" ... a method must fit on a screen | token scanner walks all of `src/` |
| No magic methods | `__call`, `__get`, `__set`, … | regex on function declarations |
| No reflection outside Core | `ReflectionClass` and friends in other modules | regex on `Reflection*` |
| No `eval()` | dynamic code execution | regex on `eval(` and `create_function(` |
| No speculative interfaces | an interface with a single implementation | token analyzer + baseline 0 |
| No service-locator calls | `Container::get()` / `->make()` / `->resolve()` outside Core | scans **only code**, not docblocks |

Notice one more thing in the table: "token scanner" and "scans only code, not docblocks". For the service-locator scan the test literally **strips comments and docblocks** from the code and then looks for the forbidden calls ... so you can't fool it by writing an excuse into a docblock, and it won't accidentally trip on an innocent mention in a comment. The rule applies, full stop.

## Clean code isn't aesthetics

Clean code isn't about making code look pretty. It's about **surviving your absence**. When you leave the project, when a new member joins, when you come back to your own code six months later.

Clean code is respect for the person who hasn't arrived yet. For the old colleague reading for the first time. For the child who's learning. For the device that doesn't have room for mistakes.

→ [Names are documentation](/en/blog/names-are-documentation) — how to spot a bad name before it starts giving you a headache
→ [One thing (SRP)](/en/blog/one-thing-srp) — a method and a class do only one thing
→ [DRY vs abstraction](/en/blog/dry-vs-abstraction) — one source of truth, no interfaces for the sake of it

## Sources

- [W3C — ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance) — ACID definition
- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html) — SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
