---
title: "Themes for kids ... part 2: a package for each theme"
series: "Themes for kids"
series-part: "2"
date: "2026-10-02"
version: "1.0.0"
published: true
tags: ["frontend", "pnpm", "themes", "vite"]
excerpt: "Part 1 turned a theme into a module in PHP. The frontend still lay in one folder ... today every theme has its own package, its own dist and its own manifest. What it cost to duplicate, and what the test says."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "balik-pre-kazdu-temu"
---

> [Part 1](/en/blog/themes-as-modules) drew a line between the kernel and a
> theme in PHP. This part moves that same line to the frontend ... and admits
> it promised something else.

You are a PHP developer. You finish part 1 and close the notebook on the
thought "a theme is a module, done".

Then you open `Frontend/packages/starter/` and everything is in there.

`erik/`, `magazine/`, `minimal/`, `moonshine/`, `mark.css`, `mark-switch.css`,
`moonshine.css` and `styles.css`, which belongs to starter.

Thirty-one files in a folder named after one theme out of five.

## The contract lived on one side only

Part 1 had two halves: the `ThemeModule` contract on the PHP side and the
boundary guarded by `ThemeOwnershipTest`.

That boundary covered `src/Modules`.

The frontend had no boundary. It sat on the other side of the same wall and
nobody was pushing.

So it could unravel quietly without rolling back.

| | PHP side before the refactor | Frontend side before the refactor |
|---|---|---|
| Boundary | `ThemeModule` + `ThemeOwnershipTest` | none |
| Unit of work | a folder `src/Themes/<theme>/` | a folder `Frontend/packages/starter/` |
| Neighbouring files | every import is a contract | `styles.css` and `erik/blog.css` in one folder |

The asymmetry was not a decision. It was a mark left behind after the stadium
learned to swim.

## What changed

`Frontend/packages/` is now the pnpm workspace root.

Under it sits `build/` with a config factory, and five packages:

```
Frontend/packages/
├── build/
│   ├── strip-at-layer.ts
│   └── vite-theme-factory.ts
├── package.json          # @nativa/frontend
├── pnpm-workspace.yaml
├── starter/              # @nativa/theme-starter
├── erik/                 # @nativa/theme-erik
├── moonshine/            # @nativa/theme-moonshine
├── magazine/             # @nativa/theme-magazine
└── minimal/              # @nativa/theme-minimal
```

Every package has its own `package.json`, its own `tsconfig.json`, one
`vite.config.ts` and its own `src/`.

Every one of them builds into its own directory:

```
public/dist/starter/
public/dist/erik/
public/dist/moonshine/
public/dist/magazine/
public/dist/minimal/
```

Why all five? `magazine` and `minimal` are wired into the build too.

There are two ways to do that, and both make sense:

| | Three wired themes only | All five |
|---|---|---|
| `public/dist/` | 3 directories | 5 directories |
| `BuildCssAuditTest` | 3 bundles | 5 bundles |
| WCAG contrast for `magazine`/`minimal` | untested | tested |
| Price | reference themes rot silently | 52 kB + 56 kB more in dist |

A reference theme nobody builds is a theme that goes grey.

Current sizes: starter 44 kB, erik 36 kB, magazine 56 kB, minimal 52 kB,
moonshine 100 kB.

Really 152 kB more. In exchange you get a contrast audit for two themes that
nobody would ever have woken up otherwise.

## The factory that holds the baseline

`build/vite-theme-factory.ts` is 105 lines and does one job: to keep five
packages from drifting on the rules they share.

```ts
// Frontend/packages/build/vite-theme-factory.ts — a port pair per theme
const THEME_PORTS: Record<string, { dev: number; preview: number }> = {
  starter: { dev: 5177, preview: 4177 },
  erik: { dev: 5178, preview: 4178 },
  moonshine: { dev: 5179, preview: 4179 },
  magazine: { dev: 5180, preview: 4180 },
  minimal: { dev: 5181, preview: 4181 },
};

// Frontend/packages/starter/vite.config.ts — six lines
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'starter',
  inputs: { main: 'src/main.ts' },
});
```

Every package looks the same. The `target: 'es2015'` baseline, `@layer`
stripping through the plugin, `manifest.json` for PHP and the
`public/dist/<theme>/` directory all come from one place.

The ports are part of the rule, not a scattering.

Two `vite dev` runs no longer collide, because 5177 and 5178 are not the same
number.

### One flag has a condition

`codeSplitting: false` is not an optional switch. It turns off extraction of
shared chunks.

The reason is plain and hard: the page carries `<script defer>`, not
`<script type="module">`.

A classic deferred script cannot execute an `import` statement on Chrome 70.

But with several inputs the flag is rejected by rolldown outright. That is not
a bug, it is a deliberate refusal:

```ts
// Frontend/packages/build/vite-theme-factory.ts
const singleEntry = Object.keys(inputs).length === 1;

output: {
  ...(singleEntry ? { codeSplitting: false } : {}),
},
```

`starter`, `erik`, `magazine` and `minimal` have one input each ... the flag
applies.

`moonshine` has three (`moonshine`, `mark`, `mark-switch`) ... the flag does
not apply, and `bin/verify-build.php` verifies instead that no emitted bundle
contains `import` or `export`.

Two mechanisms, one contract point: "no cross-bundle import".

## The manifest became the boundary

`AssetManager` now knows bundles as a list, not as a branch:

```php
// src/Modules/Core/Application/Services/AssetManager.php
private const array BUNDLES = ['starter', 'erik', 'moonshine', 'magazine', 'minimal'];
```

An unknown name falls back to `starter`, not to emptiness.

And because Vite 8 keys the manifest by the **resolved source path** rather
than the `rollupOptions.input` key, there was a small hook here.

The `mark` input now points at `src/mark/index.css`, because the admin design
system is itself split into sections. The key therefore changed from `mark.css`
to `src/mark/index.css`. So `manifestEntry()` tries all four spellings:

```php
// src/Modules/Core/Application/Services/AssetManager.php — four spellings
$candidates = [$key, 'src/' . $key . '.ts', 'src/' . $key . '.css', 'src/' . $key . '/index.css'];
```

A template calls `cssUrls('mark')` and does not have to know whether the
package spells it `mark.css` or gives it a directory.

That is not guessing. That is a consequence of the CSS refactor.

## 1871 lines is nine files

The second step of the refactor was about one number.

`styles.css` had 1871 lines. It was one file with nine separate
responsibilities: tokens, chrome, home, article, content, blog, cards,
comments.

After the split:

| File | Lines | What it carries |
|---|---|---|
| `base/index.css` | 16 | import order |
| `base/tokens.css` | 81 | colours, space, shadows |
| `base/chrome.css` | 454 | nav, header, footer, theme toggle |
| `base/home.css` | 214 | the home page |
| `base/article.css` | 303 | the article and its meta |
| `base/toc.css` | 161 | the edge table of contents and the FAB |
| `base/blog.css` | 349 | the article list |
| `base/cards.css` | 179 | the article card |
| `base/comments.css` | 123 | the comment form |

The same step reached `mark.css` (1013 lines into seven files) and
`moonshine.css` (1315 lines into five).

The 600-line ceiling in `ThemeFileSizeTest` now covers `Frontend/packages/*/src`
too. The exemption list is empty.

## Duplication has a price

Here is the point where a person would rather stop.

The same `base/` lives in four packages. Nine files, four packages, 36 copies.

| | `@nativa/shared` | A copy in every package |
|---|---|---|
| One source of truth | yes | no |
| Cross-package import | yes | **banned** |
| Mistake while editing | every theme at once | parity test fails |
| Evidence of equality | none | byte comparison |

`FrontendPackageParityTest` does not verify that files "look similar".

It verifies bytes.

```php
// tests/Architecture/FrontendPackageParityTest.php
self::assertSame(
    $expected,
    (string) file_get_contents($path),
    sprintf('%s has drifted from %s — the duplicated sources must stay byte-identical.', $copy, $reference),
);
```

There is one source. It gets copied. A machine holds the equality.

And `code-highlight.ts` is duplicated — in `starter` and `erik` — for the same
physical reason: a shared chunk would be an `import`.

The parity test has two faces. Besides byte equality it also hunts **cross-package
imports** and says out loud that they would restore exactly the coupling the
layout exists to remove.

When one package reaches into another's folder, one line of its CSS changes
another theme's build.

That is why it is an error message, not a remark.

## What we did not talk about

Let us be honest — four things deserve a note.

**We changed the plan.** Part 1 promised a fourth theme step by step. What
turned out is that the frontend is one spread smear, and before we could say
"new theme" it had no package of its own and nobody to guard it. A fourth
theme without wrapping would only have added a fourth file to the same heap.

**`magazine` and `minimal` are not wired into routing.** They build and they
are audited because a reference has to stay alive. But `/magazine` does not
exist and `ThemeKernel` does not know them. They are two branches outside the
scheme.

**`container-query.ts` and `toc-scrollspy.ts` live only in `starter`.** When
sharing TypeScript we duplicated `code-highlight.ts`, because its shared chunk
would have been an `import`. These two modules carry no such pressure ... yet.
The day they are needed elsewhere they get the same fate: a copy and a parity
test.

**A byte lock does not check meaning.** The parity test notices that
`magazine/src/base/chrome.css` and `starter/src/base/chrome.css` are the same.
It does not notice that this is right. The equality is held by the very
property that creates the problem: four packages share one source, so four of
them get the same mistake.

Why is that acceptable? Because the template is deliberate. A design system
has one home, not five.

## What comes next

In part 3 we open the biggest piece still standing:
`src/Themes/erik/assets/critical/blog.css` is 454 lines and it is the largest
critical file in the kit.

We compare it with the split that just went through on the page bundle.

On whether critical CSS belongs in the theme's `dist` bundle, or stays in PHP
as an inline block.

## Sources

- [pnpm workspaces](https://pnpm.io/workspaces) — the official workspace documentation
- [Vite — build options and multi-page builds](https://vite.dev/guide/build) — inputs and the manifest
- [MDN — script defer](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/script) — why a classic script cannot execute `import`
- [PHPUnit](https://phpunit.de/) — the testing framework documentation
- [Nativa Minimal Documentation](/docs/getting-started) — the kit's official guide