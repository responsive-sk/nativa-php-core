---
title: "Hello World ... Minimal Starter Kit"
date: "2026-08-04"
version: "1.0.0"
tags: ["php"]
excerpt: "The first post in the new minimal starter kit. What the project\'s goal is and how it\'s built."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Starter Kit"
views: "0"
translation: "hello-world"
published: true
---

No framework. No Composer in production. No ORM. Just PHP 8.5, SQLite, and attribute-based routing.

This is the problem with most starter kits: too many dependencies, too much magic.

This is the first post in the new minimal PHP 8.5 starter kit. The project
is built from scratch as a **zero-dependency runtime** ... no Composer package
in production, per-module SQLite databases and attribute-based routing.

## Articles are markdown files

Blog articles live in `storage/blog/` as `.md` files with YAML front matter:

```markdown
---
title: "Article title"
date: 2026-08-04
tags: [php, architecture]
excerpt: "Short description"
author: Nativa Dev
category: Architecture
author_email: admin@example.test   # optional ... links to a users account
---
```

The article body is plain markdown. Articles are indexed dynamically from
the directory ... no manual registration.

The optional `author_email` links the author with a real users account: if
the email matches an active user (case-insensitive), both the index and the
article show their `name` from the users database (renaming in
`/mark/users` propagates automatically) ... with an avatar and a link to
`/authors/{id}`. If it doesn't match or the account is inactive, `author`
is used instead. The email is never shown in HTML.

The avatar is set in `/mark/users` (image upload); without an avatar a
placeholder with the initial is rendered. Avatars are self-hosted in
`storage/uploads/` and served via `/storage/uploads/{path}` (whitelisted
types only, no traversal).

The starter kit remains a foundation — not a framework. It gives you the minimum viable structure: routing, middleware, auth, admin panel. You build the rest.

---

Minimal means you understand every line. No magic, no hidden behavior, no framework opinions.

---

A starter kit should be read, not just used.

---

→ [Getting Started](/docs/getting-started) — install, migrate, seed, build
→ [Adding Modules](/docs/adding-modules) — how to port feature modules

## Sources

- [PHP.net — PHP: Hypertext Preprocessor](https://www.php.net/) — official PHP website
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP Framework Interop Group standards
- [SQLite.org — About](https://www.sqlite.org/about.html) — SQLite embedded database
- [Vite — Why Vite](https://vitejs.dev/guide/why.html) — why Vite instead of Webpack
- [Nativa Minimal — Getting Started](/docs/getting-started) — official getting started guide
