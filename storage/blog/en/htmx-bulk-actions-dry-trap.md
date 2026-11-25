---
title: "The Bulk Delete That Rendered Twice"
date: "2026-08-30"
version: "1.0.0"
tags: ["htmx", "dry", "bulk-actions", "refactoring", "clean-code"]
excerpt: "We built bulk delete for four admin tables. Then we duplicated every table renderer. The fix was one shared class per entity, but getting there taught us something about HTMX and code ownership."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Clean Code"
translation: "hromadne-mazanie-dry-pasca"
published: true
---

Select all. Delete selected. Watch the table update without a page reload.

That was the promise. And it worked, on the first table. Then we copied the rendering code to the second table. And the third. And the fourth. By the time we had four identical table renderers, we had a DRY violation that would bite us the moment someone changed a column header.

## The Setup

Bulk actions in an admin panel follow a predictable pattern. Each table row gets a checkbox. A "select all" checkbox lives in the header. When items are selected, a floating bar appears with a "Delete selected" button. The form POSTs to a bulk endpoint, the server deletes the items, and HTMX swaps the updated table back into the page.

The checkbox markup is simple:

```php
// BulkActions.php — one checkbox cell
public static function checkboxCell(string $id): string
{
    $escaped = AdminLayout::e($id);
    return '<td class="m-bulk__cell">'
        . '<input type="checkbox" name="ids[]" value="' . $escaped . '"'
        . ' class="m-bulk__check">'
        . '</td>';
}
```

The select-all and count logic lives in JavaScript:

```javascript
// Select all → check every row checkbox
selectAll.addEventListener('change', function() {
    var checks = document.querySelectorAll('.m-bulk__check:not(.m-bulk__select-all)');
    for (var i = 0; i < checks.length; i++) {
        checks[i].checked = selectAll.checked;
    }
    updateBulkBar();
});
```

The bulk bar appears when `checked > 0`, disappears when `checked === 0`. The form submits via HTMX, and the server returns the updated table. Clean. Predictable. Reusable.

Then we added it to four tables.

## The Cascade

UsersAction got bulk checkboxes. So did RolesAction. And PagesIndexAction. And ArticlesIndexAction. Each action built its own table, thead, tbody, rows, bulk bar. Each action had its own `renderRow()` method with slightly different columns.

When BulkDeleteAction needed to re-render the table after deletion, it had two choices: call the original action's render method, or duplicate the rendering code. We chose duplication. Faster to ship. No cross-module imports. No refactoring risk.

For about ten minutes.

The problem appeared when we changed the Users table header. We added a "Status" column to the original action but forgot to update BulkDeleteAction's `renderUsersTable()`. The two tables looked different depending on how you arrived at the page.

That was the symptom. The disease was deeper.

BulkDeleteAction had 290 lines. Half of them were copy-pasted table rendering from four different action files. Every time someone changed a badge, added a column, or tweaked a CSS class, they had to remember to update the copy in BulkDeleteAction too.

They wouldn't. Nobody does.

## The Fix

The solution was one shared renderer per entity. Not a shared interface, a concrete class in each module that owns the table.

```
UsersTableRenderer   →  Users module  (used by UsersAction + BulkDeleteAction)
RolesTableRenderer   →  Users module  (used by RolesAction + BulkDeleteAction)
PagesTableRenderer   →  Pages module  (used by PagesIndexAction + BulkDeleteAction)
ArticlesTableRenderer → Blog module   (used by ArticlesIndexAction + BulkDeleteAction)
```

Each renderer has two methods:

```php
// table() — just the <table> HTML (for actions that add pagination/countInfo)
public static function table(array $pages, string $locale): string

// render() — complete bulk bar + wrapper + table (for BulkDeleteAction re-render)
public static function render(array $pages, string $locale): string
```

The original actions call `table()` and wrap it with their own search input, count info, and pagination. BulkDeleteAction calls `render()` and gets the complete package.

One source of truth. One place to change a column header. One place to add a badge.

BulkDeleteAction went from 290 lines to 154. The table rendering code went from four copies to one.

## What HTMX Expects

HTMX with `outerHTML` swap replaces the target element with the matching element from the response. If the response contains elements outside the target, they are discarded, but only if the server returns them.

The bulk form targets `#users-content`. The bulk bar lives outside that target. After the form submits, HTMX swaps only `#users-content` with the new table. The bulk bar stays in the DOM with stale state.

We handle this with an `htmx:afterSwap` listener that resets the count:

```javascript
document.body.addEventListener('htmx:afterSwap', function() {
    updateBulkBar();
});
```

After every swap, the count recalculates from the current checkboxes in the DOM. If the server returned a fresh table (zero checked), the count resets to zero and the bar hides.

The lesson: HTMX partial responses should contain only the target element. If you need to update surrounding elements, do it with JavaScript after the swap.

## The Transaction

The first version of bulk delete ran each deletion independently. Delete user 1. Delete user 2. Delete user 3. If user 3 failed (last admin, for example), users 1 and 2 were already gone.

For database-backed entities, we wrapped the loop in a UnitOfWork transaction:

```php
$pdo = DatabaseConnectionManager::instance()->connection('users');
$uow = new UnitOfWork($pdo);
$uow->transaction(function (PDO $pdo) use ($entity, $ids, &$deleted): void {
    foreach ($ids as $id) {
        if ($this->deleteItem($entity, (string) $id, '')) {
            $deleted++;
        }
    }
});
```

All deletes succeed, or all roll back. No partial state.

For filesystem entities (pages, articles), there is no database transaction. A failed delete mid-loop leaves some files deleted and others intact. This is an inherent limitation of filesystem operations, you cannot roll back a `unlink()`.

The decision was explicit: DB entities get atomicity. Filesystem entities get best-effort. The code documents which is which.

## What We Learned

The DRY trap in bulk actions is subtle because the duplication happens gradually. You add bulk support to one table. It works. You copy the pattern to the next table. It works. By the fourth table, the rendering code is everywhere, and changing a column means editing five files.

Shared renderers solve this, but they require thinking about ownership. The renderer lives in the module that owns the entity. The action calls the renderer. BulkDeleteAction calls the same renderer. One truth, many consumers.

The factory pattern matters too. `new BlogCrudService()` scattered across action files creates invisible coupling. `CardboardServices::blogCrud()` makes the dependency explicit and cacheable.

HTMX makes bulk actions feel effortless on the frontend. The complexity lives in the server response, returning exactly the right HTML, handling the transaction, and keeping the rendering code in one place.

A table renderer is not a framework. It is a function that returns a string. But when that string is the single source of truth for how a table looks, it earns its place.

→ [Click Page 3 ... and the Pager Doubled](/blog/htmx-pager-duplication): the first HTMX pagination bug
→ [MoonShine Features + HTMX](/blog/moonshine-htmx-features): toast notifications, live search, form submit
→ [DRY vs Abstraction](/blog/dry-vs-abstraction): one source of truth, no interfaces for the sake of it

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
