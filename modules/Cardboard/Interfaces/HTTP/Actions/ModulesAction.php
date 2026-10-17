<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Application\Services\ModuleTreeState;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Tooling\ModuleCatalog;
use Modules\Tooling\ModuleInstaller;

/**
 * ModulesAction — the module list, and the reinstall that closes drift.
 *
 * Exists because `src/Modules/<Name>` is gitignored operator state. The
 * catalogue (`modules/`) is committed; the slice (`src/Modules/`) is a copy
 * `module:add` makes. Nothing in git compares them, and `module:show` asks
 * `isInstalled()` — whether a slice exists, not whether it is the right one — so
 * a tree can run code that was deleted three commits ago while every gate is
 * green. Measured 2026-10-17: Cardboard's installed slice still held the
 * permission table that had been deleted from the catalogue.
 *
 * ## Why reinstall is a POST here and not a link
 *
 * `ModuleInstaller::add()` refuses to overwrite an installed slice
 * (`cms: Module 'X' is already installed — refusing to overwrite the installed
 * slice`), so the only way to close drift is to remove and re-add, which
 * discards operator edits made in `src/Modules/`. That is destructive and
 * irreversible, so it is a POST behind the admin's CSRF token rather than a link
 * a stray click can follow — and the button only appears for a module that is
 * actually drifted, so the ordinary page has nothing to confirm.
 *
 * The action re-reads the tree after the reinstall rather than trusting that the
 * copy landed: the card and this page both read `ModuleTreeState`, and a green
 * row here is the same green the card counts.
 */
// The attributes sit on the CLASS, not on `handle()`.
//
// That is not a style choice: `Router::discover()` reads
// `$reflection->getAttributes(Route::class)` — the class's own attributes, one
// level up — and the method's attributes are never looked at. Written on
// `handle()` they compile, they import cleanly, `phpstan` and `cs-fixer` pass,
// and the route simply does not exist: `/mark/settings/modules` answered 404 with
// eight CI jobs green. Every other action in this directory puts them on the
// class, which is the tell.
#[Route(path: '/mark/settings/modules', method: 'GET', name: 'mark.settings.modules')]
#[Route(
    path: '/mark/settings/modules/{name}/reinstall',
    method: 'POST',
    name: 'mark.settings.modules.reinstall',
)]
final class ModulesAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $catalogued = ModuleCatalog::fromAppPaths()->catalogued();

        if ('POST' === $request->getMethod()) {
            return $this->reinstall($request, $catalogued);
        }

        return Response::html($this->page(AdminLayout::csrfField()));
    }

    /**
     * @param list<string> $catalogued
     */
    private function reinstall(Request $request, array $catalogued): Response
    {
        $name = (string) $request->routeParam('name', '');

        // Unknown name: refuse rather than echo it into a command.
        if (!in_array($name, $catalogued, true) || !ModuleCatalog::isValidName($name)) {
            return Response::redirect($this->url('mark.settings.modules'));
        }

        $errors = [];

        try {
            $catalog = ModuleCatalog::fromAppPaths();

            if ($catalog->isInstalled($name)) {
                // The refusal the installer issues is the point, not an obstacle:
                // a slice exists, and overwriting it would drop operator edits.
                ModuleInstaller::fromAppPaths()->remove($name, true);
            }

            ModuleInstaller::fromAppPaths()->add($name);
        } catch (\Throwable $e) {
            $errors[] = $name . ': ' . $e->getMessage();
        }

        return Response::redirect($this->url('mark.settings.modules') . ([] === $errors ? '' : '?error=' . rawurlencode(implode(' / ', $errors))));
    }

    private function page(string $csrf): string
    {
        $state = new ModuleTreeState();
        $counts = $state->counts();

        $rows = '';
        $fixable = [];

        foreach ($state->all() as $module) {
            $rows .= $this->row($module, $csrf);

            if ('drifted' === $module['state']) {
                $fixable[] = $module['name'];
            }
        }

        $content = ViewRenderer::render(
            'Modules/Cardboard/Interfaces/HTTP/templates/modules-list.phtml',
            [
                'intro' => AdminLayout::e(sprintf(
                    '%d catalogued · %d match the installed slice · %d drifted · %d not installed.'
                    . ' The slice is copied from the catalogue by module:add and is not tracked by git,'
                    . ' so this page is the only place the two are compared.',
                    $counts['total'],
                    $counts['identical'],
                    $counts['drifted'],
                    $counts['missing'],
                )),
                'rows' => $rows,
                'fixableHtml' => AdminLayout::e([] === $fixable ? '' : 'Reinstall replaces the slice with the catalogue and drops any edit made inside it: ' . implode(', ', $fixable)),
            ],
        );

        return AdminLayout::render('Modules', $content, 'settings', router: $this->router());
    }

    /**
     * @param array{name: string, installed: bool, state: 'identical'|'drifted'|'not-installed', drifted: list<string>} $module
     */
    private function row(array $module, string $csrf): string
    {
        [$badge, $label] = match ($module['state']) {
            'identical' => ['success', 'in sync'],
            'drifted' => ['warning', 'drift'],
            default => ['gray', 'not installed'],
        };

        $drifted = [] === $module['drifted']
            ? '<span class="m-muted">—</span>'
            : '<code>' . AdminLayout::e(implode('</code>, <code>', $module['drifted'])) . '</code>';

        $action = 'drifted' === $module['state']
            ? '<form method="post" action="' . AdminLayout::e($this->url('mark.settings.modules.reinstall', ['name' => $module['name']])) . '" style="display:inline">'
                . $csrf
                . '<button type="submit" class="m-btn m-btn-sm">Reinstall</button></form>'
            : '';

        return '<tr><td>' . AdminLayout::e($module['name']) . '</td>'
            . '<td><span class="m-badge m-badge--' . $badge . '">' . $label . '</span></td>'
            . '<td>' . $drifted . '</td>'
            . '<td>' . $action . '</td></tr>';
    }
}