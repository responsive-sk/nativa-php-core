# Intro: Nativa PHP by responsive.sk

Nativa PHP is a modular-monolith CMS with one rule holding it together: **the
kernel never imports a module; a module may import the kernel.** Everything on
responsive.sk — pages, blog, admin, themes — is either the kernel or a module
sitting on top of it. This guide says which is which and where to read next.

## The kernel

The kernel is the smallest tree that still answers HTTP: routing, the
request/response pair, the middleware pipeline, per-module SQLite, markdown
rendering, error pages, and the SEO text surfaces. It ships with zero Composer
runtime dependencies — `php -S 127.0.0.1:8000 -t public` boots it with no
`vendor/` — and a kernel-only checkout answers `/`, `/sitemap.xml` and
`/robots.txt` with **200**. Anything it cannot answer with nothing installed
(like `/docs` and `/mark`, both modules) answers 404 there, by design.

## A module

A module is a slice of the application that can be installed and, provably,
removed again. The catalogue in `modules/` is the source; `bin/cms module:add
<Name>` copies the slice in, `bin/cms module:remove <Name>` deletes it and
reports (never deletes) the data. `module:add` refuses when a dependency is
missing, `module:remove` refuses while anything still imports the module — so
"optional" is a checked property, not a hope.

## A theme

A theme travels as a module and owns only presentation: markup vocabulary, one
critical stylesheet, and small scripts with no build step. Removing the module
removes the option from the Design select; a stale choice degrades to the
kernel theme instead of a 500.

## Where next

[core-philosophy](core-philosophy.md) states the boundary rule and the port
pattern behind it; the module guides each follow the same shape — what it
serves, what it owns, what it refuses. Start with [pages](pages.md) if you
publish content, with the [module contract](modules.md) if you build. The
measured log lives in [kernel-status](kernel-status.md); [tree history](tree-history.md)
has the shape that log left, landing by landing.
