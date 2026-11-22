---
title: "References"
published: true
translation: "referencie"
seo_title: "References"
seo_description: "What you can open and check on this site: the page itself, the Nativa PHP core, six themes and the documentation — no logos you cannot verify."
---

This site is a demonstration, so its references look different too: **instead of logos there are links you open and check yourself.** Everything below is something that actually runs in this tree — not a presentation about it.

## This site

You can walk the page you are reading: the [sitemap](/sitemap.xml) lists every public address, [robots.txt](/robots.txt) says what a crawler may see, and [`/health`](/health) reports each probe. It runs on the same kernel this site is about: PHP with no Composer dependencies, content in markdown files and the admin at `/mark`.

## Nativa PHP Core

The kernel is a [public repository](https://github.com/responsive-sk/nativa-php-core). Every claim in the [articles](/en/blog) has a place in the code where it can be checked, and removing a module is not a promise: `php bin/cms module:remove Blog` really removes it, and the repository's tests check that the tree still works afterwards.

## Six themes

Boson, Erik, Griffe, Jednotka, Mono and Nativa. Each is a module in the [nativa-themes](https://github.com/evan70/nativa-themes) repository, and each can be opened: [the gallery](https://evan70.github.io/nativa-themes/) shows the same page in six skins — home, blog, article, tags, categories, author and search, in English and Slovak. In this tree `make assets` builds them into `public/dist/nativa`; a theme is a setting in `/mark` → Settings, not a code change, so the difference is visible without rewriting anything.

## Documentation and articles

- [`/docs`](/docs) — the kernel, the modules and the themes, for people who read the tree or work in it.
- [`/en/blog`](/en/blog) — articles with links to the exact files and commits, not summaries without a source.

## Why there are no logos

A reference you cannot open is just a picture. This page shows what can be checked — for a specific project, write to [ahoj@responsive.sk](mailto:ahoj@responsive.sk).
