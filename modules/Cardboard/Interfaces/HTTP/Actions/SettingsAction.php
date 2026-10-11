<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\CardboardKit\Application\Services\IconRegistry;
use Modules\CardboardKit\Interfaces\HTTP\AdminLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Settings\Application\DTOs\MenuItemDTO;

/**
 * Settings forms — task 9.4.
 *
 * Routes:
 * - GET  /mark/settings             settings overview (tabs: site, design, navigation)
 * - POST /mark/settings/site        save site settings
 * - POST /mark/settings/tokens      save design tokens
 * - POST /mark/settings/nav         save navigation item
 * - POST /mark/settings/nav/reorder     reorder navigation items (drag + Save order)
 * - POST /mark/settings/nav/{id}/delete  delete navigation item
 * - POST /mark/settings/flush-cache      flush page cache
 */
#[Route(path: '/mark/settings', method: 'GET', name: 'mark.settings')]
#[Route(path: '/mark/settings/site', method: 'POST', name: 'mark.settings.site', handlerMethod: 'saveSite')]
#[Route(path: '/mark/settings/tokens', method: 'POST', name: 'mark.settings.tokens', handlerMethod: 'saveTokens')]
#[Route(path: '/mark/settings/nav', method: 'POST', name: 'mark.settings.nav', handlerMethod: 'saveNav')]
#[Route(path: '/mark/settings/nav/{id}/delete', method: 'POST', name: 'mark.settings.nav.delete', handlerMethod: 'deleteNav')]
#[Route(path: '/mark/settings/nav/reorder', method: 'POST', name: 'mark.settings.nav.reorder', handlerMethod: 'reorderNav')]
#[Route(path: '/mark/settings/flush-cache', method: 'POST', name: 'mark.settings.flush-cache', handlerMethod: 'flushCache')]
#[Route(path: '/mark/settings/admin-nav', method: 'POST', name: 'mark.settings.admin-nav', handlerMethod: 'saveAdminNav')]
#[Route(path: '/mark/settings/admin-nav/{id}/toggle', method: 'POST', name: 'mark.settings.admin-nav.toggle', handlerMethod: 'toggleAdminNav')]
#[Route(path: '/mark/settings/admin-nav/{id}/sort', method: 'POST', name: 'mark.settings.admin-nav.sort', handlerMethod: 'sortAdminNav')]
#[Route(path: '/mark/settings/admin-nav/{id}/delete', method: 'POST', name: 'mark.settings.admin-nav.delete', handlerMethod: 'deleteAdminNav')]
#[Route(path: '/mark/settings/admin-nav/reorder', method: 'POST', name: 'mark.settings.admin-nav.reorder', handlerMethod: 'reorderAdminNav')]
#[Route(path: '/mark/settings/admin-nav/{id}/icon', method: 'POST', name: 'mark.settings.admin-nav.icon', handlerMethod: 'updateAdminNavIcon')]
final class SettingsAction
{
    public function handle(Request $request): Response
    {
        return Response::html($this->settingsPage());
    }

    /**
     * Render the settings page (site settings, design tokens, navigation).
     *
     * @param list<string> $errors
     */
    private function settingsPage(array $errors = []): string
    {
        $settings = CardboardServices::settings();
        $all = $settings->all();
        $csrf = AdminLayout::csrfField();
        $errorHtml = AdminLayout::messages($errors, 'err');

        $siteName = AdminLayout::e(is_string($all['site_name'] ?? null) ? $all['site_name'] : '');
        $siteDescription = AdminLayout::e(is_string($all['site_description'] ?? null) ? $all['site_description'] : '');
        $adminEmail = AdminLayout::e(is_string($all['admin_email'] ?? null) ? $all['admin_email'] : '');
        $timezone = AdminLayout::e(is_string($all['timezone'] ?? null) ? $all['timezone'] : 'UTC');
        $locale = AdminLayout::e(is_string($all['locale'] ?? null) ? $all['locale'] : 'en_US');
        $regRaw = $all['registration_enabled'] ?? '1';
        $registrationEnabled = is_string($regRaw) ? '1' === $regRaw : true;
        $regChecked = $registrationEnabled ? ' checked' : '';

        $layoutSelect = $this->layoutSelect($all);
        $themeSelect = $this->themeSelect($all);
        $tokenTable = $this->designTokensTable($settings->designTokens());
        $navTable = $this->headerNavTable($csrf);

        $adminNavItems = CardboardServices::adminNav()->allItems();
        $iconOptions = IconRegistry::optionsHtml();
        $parentOptions = $this->adminParentOptions($adminNavItems);
        $adminNavTable = $this->adminNavTable($adminNavItems, $csrf);

        $content = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/settings-form.phtml', ['adminEmail' => $adminEmail, 'adminNavTable' => $adminNavTable, 'csrf' => $csrf, 'errorHtml' => $errorHtml, 'iconOptions' => $iconOptions, 'layoutSelect' => $layoutSelect, 'locale' => $locale, 'navTable' => $navTable, 'parentOptions' => $parentOptions, 'regChecked' => $regChecked, 'siteDescription' => $siteDescription, 'siteName' => $siteName, 'themeSelect' => $themeSelect, 'timezone' => $timezone, 'tokenTable' => $tokenTable]);

        return AdminLayout::render(
            'Settings',
            $content,
            'settings',
        );
    }

    /**
     * The admin-layout `<select>`.
     *
     * @param array<string, mixed> $all
     */
    private function layoutSelect(array $all): string
    {
        $current = is_string($all['admin_layout'] ?? null) ? $all['admin_layout'] : 'sidebar';
        $sidebar = 'sidebar' === $current ? ' selected' : '';
        $compact = 'compact' === $current ? ' selected' : '';

        return '<select id="admin_layout" name="admin_layout">'
            . '<option value="sidebar"' . $sidebar . '>Sidebar (default)</option>'
            . '<option value="compact"' . $compact . '>Compact (top menu)</option>'
            . '</select>';
    }

    /**
     * The public-theme `<select>` (ADR-0002).
     *
     * Options render from the resolver whitelist, so the form and the validation
     * in `saveSite()` cannot drift apart — a theme the admin can pick but the
     * save path rejects would look like a save that silently did nothing.
     *
     * @param array<string, mixed> $all
     */
    private function themeSelect(array $all): string
    {
        $raw = $all['theme'] ?? ThemeResolver::PUBLIC_THEME;
        $current = is_string($raw) ? $raw : ThemeResolver::PUBLIC_THEME;
        $options = '';

        foreach (ThemeResolver::PUBLIC_WHITELIST as $whitelisted) {
            $selected = $whitelisted === $current ? ' selected' : '';
            $options .= '<option value="' . $whitelisted . '"' . $selected . '>' . ucfirst($whitelisted) . '</option>';
        }

        return '<select id="theme" name="theme">' . $options . '</select>';
    }

    /**
     * The design-token table, or the note that says there are none.
     *
     * @param array<string, mixed> $tokens
     */
    private function designTokensTable(array $tokens): string
    {
        $rows = '';

        foreach ($tokens as $key => $value) {
            $tokenKey = AdminLayout::e((string) $key);
            $json = json_encode($value);
            $tokenValue = AdminLayout::e(is_string($value) ? $value : (false === $json ? '' : $json));
            $rows .= "<tr><td><code>{$tokenKey}</code></td><td>{$tokenValue}</td></tr>";
        }

        if ('' === $rows) {
            return '<p class="m-muted">No design tokens yet.</p>';
        }

        return "<table class=\"m-table\"><thead><tr><th>Token</th><th>Value</th></tr></thead><tbody>{$rows}</tbody></table>";
    }

    /**
     * Parent options for the admin-nav add form — top-level items only, because
     * a group cannot nest inside a group.
     *
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}> $items
     */
    private function adminParentOptions(array $items): string
    {
        $options = '<option value="">None (top-level)</option>';

        foreach ($items as $item) {
            if (null === ($item['parent_id'] ?? null)) {
                $options .= '<option value="' . AdminLayout::e($item['id']) . '">' . AdminLayout::e($item['label']) . '</option>';
            }
        }

        return $options;
    }

    /**
     * The admin-navigation table: one row per item, plus the drag toolbar and
     * the "Save order" button the shared widget binds to.
     *
     * @param list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}> $items
     */
    private function adminNavTable(array $items, string $csrf): string
    {
        $rows = '';

        foreach ($items as $item) {
            $itemId = AdminLayout::e($item['id']);
            $label = AdminLayout::e($item['label']);
            $href = AdminLayout::e($item['href']);
            $isActive = $item['is_active'];
            $statusClass = $isActive ? 'm-nav-row__status m-nav-row__status--active' : 'm-nav-row__status m-nav-row__status--hidden';
            $statusText = $isActive ? 'Active' : 'Hidden';
            $iconDelete = AdminLayout::iconDelete();
            $currentIcon = $item['icon'];
            $iconSelect = '<select name="icon" data-icon-select class="m-icon-select">'
                . IconRegistry::optionsHtml($currentIcon) . '</select>';
            // Indent children visually.
            $isChild = null !== ($item['parent_id'] ?? null);
            $indent = $isChild ? ' class="m-nav-row--child"' : '';
            $prefix = $isChild ? '<span class="m-nav-row__branch">└</span> ' : '';
            $rows .= "<tr data-nav-id=\"{$itemId}\" draggable=\"true\"{$indent}>
                <td class=\"m-drag-handle\" title=\"Drag to reorder\">⋮⋮</td>
                <td><span class=\"{$statusClass}\">{$prefix}{$label}</span></td>
                <td><code>{$href}</code></td>
                <td>                        <form method=\"POST\" action=\"/mark/settings/admin-nav/{$itemId}/icon\" class=\"m-inline-form m-inline-form--icon\">
                        {$csrf}
                        {$iconSelect}
                        <button class=\"m-btn m-btn-sm\" title=\"Save icon\">✓</button>
                    </form>
                </td>
                <td>
                    <form method=\"POST\" action=\"/mark/settings/admin-nav/{$itemId}/toggle\" class=\"m-inline-form\">
                        {$csrf}
                        <button class=\"m-btn m-btn-sm\" title=\"Toggle visibility\">{$statusText}</button>
                    </form>
                </td>
                <td>
                    <form method=\"POST\" action=\"/mark/settings/admin-nav/{$itemId}/delete\" class=\"m-inline-form\">
                        {$csrf}
                        <button class=\"m-btn m-btn-error m-btn-sm m-btn-icon\"
                                data-confirm=\"Delete this nav item?\" title=\"Delete\">{$iconDelete}</button>
                    </form>
                </td>
            </tr>";
        }

        if ('' === $rows) {
            return '<p class="m-muted">No admin nav items — using defaults.</p>';
        }

        return ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/settings-admin-table.phtml', ['adminNavRows' => $rows]);
    }

    /**
     * The public (header) navigation table.
     *
     * It is the SAME drag widget the admin table uses, because the two answer
     * the same question — "what order do these rows go in?" — and a second
     * hand-written drag implementation would be a second place for the drop
     * index to be off by one. What each table owns is its own reorder endpoint:
     * the table carries `data-reorder-url`, and the shared script posts to the
     * URL the table declares, so a public-nav drag can never reach the admin
     * route.
     */
    private function headerNavTable(string $csrf): string
    {
        $rows = '';
        foreach (CardboardServices::menu()->findByLocation('header') as $item) {
            $itemId = AdminLayout::e((string) $item['id']);
            $label = AdminLayout::e((string) $item['label']);
            $href = AdminLayout::e((string) $item['href']);
            $iconDelete = AdminLayout::iconDelete();
            $rows .= "<tr data-nav-id=\"{$itemId}\" draggable=\"true\">"
                . '<td class="m-drag-handle" title="Drag to reorder">⋮⋮</td>'
                . "<td>{$label}</td>"
                . "<td><code>{$href}</code></td>"
                . '<td>'
                . "<form method=\"POST\" action=\"/mark/settings/nav/{$itemId}/delete\" class=\"m-inline-form\">"
                . $csrf
                . '<button class="m-btn m-btn-error m-btn-sm m-btn-icon"'
                . ' data-confirm="Delete this nav item?" title="Delete">' . $iconDelete . '</button>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }

        if ('' === $rows) {
            return '<p class="m-muted">No navigation items yet.</p>';
        }

        return '<div class="m-table-toolbar">'
            . '<p class="m-muted">Drag rows to reorder. Click "Save order" when done.</p>'
            . '<button type="button" class="m-btn m-btn-primary m-btn-sm" id="header-nav-save-order"'
            . ' data-nav-save-order>Save order</button>'
            . '</div>'
            . '<div class="m-table-scroll"><table class="m-table" id="header-nav-table" data-nav-table'
            . ' data-reorder-url="/mark/settings/nav/reorder">'
            . '<thead><tr><th class="m-table__cell--drag"></th><th>Label</th><th>Href</th><th></th></tr></thead>'
            . "<tbody>{$rows}</tbody></table></div>";
    }

    public function saveSite(Request $request): Response
    {
        $settings = CardboardServices::settings();
        $values = [
            'site_name'             => 'general',
            'site_description'      => 'general',
            'admin_email'           => 'general',
            'timezone'              => 'general',
            'locale'                => 'general',
            'registration_enabled'  => 'general',
            'admin_layout'          => 'design',
            'theme'                 => 'design',
        ];

        foreach ($values as $key => $group) {
            $value = $request->body($key);
            if ('admin_layout' === $key) {
                $layout = is_string($value) ? $value : 'sidebar';
                $settings->set($key, in_array($layout, ['sidebar', 'compact'], true) ? $layout : 'sidebar', $group, false);
            } elseif ('theme' === $key) {
                // ADR-0002: whitelist-validated public theme; an off-whitelist
                // value falls back to the default instead of being stored.
                $theme = is_string($value) ? $value : ThemeResolver::PUBLIC_THEME;
                $settings->set(
                    $key,
                    in_array($theme, ThemeResolver::PUBLIC_WHITELIST, true) ? $theme : ThemeResolver::PUBLIC_THEME,
                    $group,
                    false,
                );
            } elseif ('registration_enabled' === $key) {
                $settings->set($key, in_array($value, ['on', '1', 'true', true], true), $group, true);
            } elseif ('admin_email' === $key) {
                $email = is_string($value) ? $value : '';
                $settings->set(
                    $key,
                    '' === $email || false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
                    $group,
                    true,
                );
            } else {
                $settings->set($key, is_string($value) ? $value : '', $group, true);
            }
        }

        CacheInvalidator::flush();
        AuditServices::log('settings.save', 'settings', 'Saved site settings', $request->getClientIp());

        return Response::redirect('/mark/settings');
    }

    public function saveTokens(Request $request): Response
    {
        $key = is_string($request->body('token_key')) ? trim($request->body('token_key')) : '';
        $value = is_string($request->body('token_value')) ? trim($request->body('token_value')) : '';

        if ('' !== $key && '' !== $value) {
            CardboardServices::settings()->setDesignToken($key, $value);
            CacheInvalidator::flush();
            AuditServices::log('settings.tokens', 'settings', 'Saved design token ' . $key, $request->getClientIp());
        }

        return Response::redirect('/mark/settings');
    }

    public function saveNav(Request $request): Response
    {
        $label = is_string($request->body('label')) ? trim($request->body('label')) : '';
        $href = is_string($request->body('href')) ? trim($request->body('href')) : '';
        $sortRaw = $request->body('sort_order');
        $sort = is_numeric($sortRaw) ? (int) $sortRaw : 0;

        // F-8: validate href with the same rule the public renderer uses — an
        // invalid value must never reach menu_items (it would crash the public
        // homepage at render time).
        if ('' !== $label && '' !== $href && !MenuItemDTO::isValidHref($href)) {
            return Response::html($this->settingsPage([
                'Navigation href must be a valid URL or path.',
            ]));
        }

        if ('' !== $label && '' !== $href) {
            CardboardServices::menu()->create($label, $href, 'header', $sort);
            CacheInvalidator::flush();
            AuditServices::log('settings.nav', 'settings', 'Added nav item ' . $label, $request->getClientIp());
        }

        return Response::redirect('/mark/settings');
    }

    public function deleteNav(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            CardboardServices::menu()->delete($id);
            CacheInvalidator::flush();
            AuditServices::log('settings.nav.delete', 'settings', 'Deleted nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect('/mark/settings');
    }

    public function flushCache(Request $request): Response
    {
        $deleted = CacheInvalidator::flush();
        AuditServices::log('settings.flush-cache', 'settings', 'Flushed page cache (' . $deleted . ' files)', $request->getClientIp());

        return Response::redirect('/mark/settings');
    }

    public function saveAdminNav(Request $request): Response
    {
        $label = is_string($request->body('label')) ? trim($request->body('label')) : '';
        $href = is_string($request->body('href')) ? trim($request->body('href')) : '';
        $sortRaw = $request->body('sort_order');
        $sort = is_numeric($sortRaw) ? (int) $sortRaw : 0;
        $icon = is_string($request->body('icon')) ? $request->body('icon') : '';
        $parentId = is_string($request->body('parent_item_id')) ? trim($request->body('parent_item_id')) : '';

        if ('' !== $icon && !IconRegistry::isValid($icon)) {
            $icon = '';
        }

        if ('' !== $label && '' !== $href && !MenuItemDTO::isValidHref($href)) {
            return Response::html($this->settingsPage([
                'Admin nav href must be a valid URL or path.',
            ]));
        }

        if ('' !== $label && '' !== $href) {
            CardboardServices::menu()->create($label, $href, 'admin', $sort, '' !== $parentId ? $parentId : null, false, null, $icon);
            CacheInvalidator::flush();
            AuditServices::log('settings.admin-nav', 'settings', 'Added admin nav item ' . $label, $request->getClientIp());
        }

        return Response::redirect('/mark/settings');
    }

    public function toggleAdminNav(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            CardboardServices::menu()->toggleActive($id);
            CacheInvalidator::flush();
            AuditServices::log('settings.admin-nav.toggle', 'settings', 'Toggled admin nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect('/mark/settings');
    }

    public function sortAdminNav(Request $request): Response
    {
        $id = $request->routeParam('id');
        $sortRaw = $request->body('sort_order');
        $sort = is_numeric($sortRaw) ? (int) $sortRaw : 0;

        if (null !== $id) {
            CardboardServices::menu()->updateSortOrder($id, $sort);
            CacheInvalidator::flush();
            AuditServices::log('settings.admin-nav.sort', 'settings', 'Reordered admin nav item ' . $id . ' to ' . $sort, $request->getClientIp());
        }

        return Response::redirect('/mark/settings');
    }

    public function deleteAdminNav(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            CardboardServices::menu()->delete($id);
            CacheInvalidator::flush();
            AuditServices::log('settings.admin-nav.delete', 'settings', 'Deleted admin nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect('/mark/settings');
    }

    public function reorderAdminNav(Request $request): Response
    {
        $pairs = $this->reorderPairs($request);

        if ([] !== $pairs) {
            CardboardServices::menu()->batchUpdateSortOrder($pairs);
            CacheInvalidator::flush();
            AuditServices::log('settings.admin-nav.reorder', 'settings', 'Reordered admin nav (' . count($pairs) . ' items)', $request->getClientIp());
        }

        return Response::html('ok');
    }

    /**
     * Reorder the PUBLIC navigation — the header menu the site renders.
     *
     * Same wire shape as the admin table (one JSON array of {id, sort_order}),
     * because both tables are the same widget; what differs is which rows were
     * rendered, and the table names its own endpoint so neither can post into
     * the other's order.
     */
    public function reorderNav(Request $request): Response
    {
        $pairs = $this->reorderPairs($request);

        if ([] !== $pairs) {
            CardboardServices::menu()->batchUpdateSortOrder($pairs);
            CacheInvalidator::flush();
            AuditServices::log('settings.nav.reorder', 'settings', 'Reordered header nav (' . count($pairs) . ' items)', $request->getClientIp());
        }

        return Response::html('ok');
    }

    /**
     * The id => sort_order pairs a reorder POST carried.
     *
     * Shared by both nav tables: they post the same JSON and differ only in
     * which rows were on the page, so parsing it twice would be two places for
     * the shape to drift. Anything that is not a list of {id, sort_order} pairs
     * yields an empty map, and an empty map writes nothing — a malformed body
     * must not renumber the menu.
     *
     * @return array<string, int>
     */
    private function reorderPairs(Request $request): array
    {
        $json = is_string($request->body('items_json')) ? $request->body('items_json') : '';
        $items = '' !== $json ? json_decode($json, true) : null;

        if (!is_array($items)) {
            return [];
        }

        $pairs = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = is_string($item['id'] ?? null) ? $item['id'] : '';
            if ('' !== $id) {
                $pairs[$id] = is_numeric($item['sort_order'] ?? null) ? (int) $item['sort_order'] : 0;
            }
        }

        return $pairs;
    }

    public function updateAdminNavIcon(Request $request): Response
    {
        $id = $request->routeParam('id');
        $icon = is_string($request->body('icon')) ? $request->body('icon') : '';

        if (null !== $id && IconRegistry::isValid($icon)) {
            CardboardServices::menu()->updateIcon($id, $icon);
            CacheInvalidator::flush();
            AuditServices::log('settings.admin-nav.icon', 'settings', 'Updated icon for admin nav item ' . $id, $request->getClientIp());
        }

        return Response::redirect('/mark/settings');
    }
}
