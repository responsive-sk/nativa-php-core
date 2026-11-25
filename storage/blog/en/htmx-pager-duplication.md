---
title: "Click Page 3 ... and the Pager Doubled"
date: "2026-08-29"
version: "1.0.0"
tags: ["htmx", "pagination", "bug", "php", "admin-panel"]
excerpt: "Every pagination click created a new pager. The old one stayed. Then a third appeared. The root cause was one HTML element sitting outside the swap target."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "htmx-pager-duplication"
published: true
---

Click page 3. The pager doubled. Click again. Tripled. Four pagers stacked on top of each other, each slightly different, some with Prev, some with Next, none with both.

The admin panel looked broken. But the code was correct. Or so it seemed.

## The Setup

HTMX pagination was straightforward. Each page link targeted `#pages-content` with `outerHTML` swap:

```html
<a href="?page=2"
   hx-get="/mark/pages?page=2"
   hx-target="#pages-content"
   hx-swap="outerHTML"
   hx-push-url="true">2</a>
```

Click a link. Server returns new table + new pagination. HTMX finds `#pages-content` in the response, replaces the old one. Browser URL updates. Clean.

The search input sat above the table. It also targeted `#pages-content`:

```html
<input type="search" name="q"
    hx-get="/mark/pages"
    hx-trigger="keyup changed delay:300ms, search"
    hx-target="#pages-content"
    hx-swap="outerHTML">
```

Search and pagination shared the same target. Both returned the same `$grid` blob, search input, count info, table, pagination, all in one HTML string.

## The Cascade

Here is what happened on a pagination click:

1. User clicks page 3
2. HTMX fires GET `/mark/pages?page=3`
3. Server returns the full `$grid`: search input + count info + `<div id="pages-content">table + pagination</div>`
4. HTMX finds `#pages-content`, replaces it via `outerHTML`
5. The old pager is gone. New pager appears. Clean so far.

But the response also contained the search input, with its own `hx-trigger="search"`. After the swap, the browser re-evaluated form state. The `search` event fired on the original input (which was still in the DOM, outside `#pages-content`). That triggered a second HTMX request. The second response included another pagination. `outerHTML` replaced `#pages-content` again, but the first replacement had already completed. Two pagers now existed in the DOM.

Each subsequent click repeated the cycle.

## The Fix

Separate what the full page needs from what HTMX needs.

`renderGrid()` returns the complete HTML for the first page load, search input, count info, table, pagination. `renderContent()` returns only the `<div id="pages-content">` element, count info, table, pagination. No search input.

```php
// Full page load
$searchInput . $this->renderContent($pages, $locale, ...);

// HTMX partial — only the target element
if (AdminLayout::isHtmx($request)) {
    return Response::html($this->renderContent($pages, $locale, ...));
}
```

The search input never appears in HTMX responses. No cascade. No duplication.

## What HTMX Expects

HTMX with `outerHTML` swap finds the target element in the response and replaces the original. Elements outside the target in the response are discarded.

But "discarded" means the browser never inserts them, it does not mean they never existed in the response string. If the response contains elements with their own HTMX triggers, and those elements happen to match DOM selectors, the browser can fire events on the original (non-swapped) elements.

The rule: an HTMX partial response should contain **only** the element being swapped. Nothing before it. Nothing after it. If the server returns `$searchInput . $targetDiv`, and `$searchInput` has `hx-trigger`, the original search input stays in the DOM and may re-fire.

`renderGrid()` remains the full-page renderer, search input, count info, table, pagination. `renderContent()` handles the HTMX path. When you build pagination with HTMX, call `renderContent()`. When you need the full page, call `renderGrid()`. Two methods, one truth.

---

A framework's magic is only as good as the HTML you feed it. HTMX does not protect you from returning too much in a partial response. That responsibility is yours.

---

→ [MoonShine Features + HTMX](/blog/moonshine-htmx-features): toast notifications, live search, form submit
→ [CSP and onclick](/blog/csp-onclick-silent-security-bug): the silent security hole that killed inline handlers

## Sources

- [htmx.org, Documentation](https://htmx.org/docs/): official HTMX documentation
- [htmx.org, hx-swap](https://htmx.org/attributes/hx-swap/): swap attribute and its options
- [htmx.org, hx-target](https://htmx.org/attributes/hx-target/): target selector specification
- [MDN, DOM Manipulation](https://developer.mozilla.org/en-US/docs/Web/API/Document_object_model): DOM API basics
- [HTMX Patterns, Pagination](https://htmx.org/examples/pagination/): official pagination pattern
