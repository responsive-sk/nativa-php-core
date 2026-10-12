<?php

declare(strict_types = 1);

namespace Modules\Locales\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Locales\Application\DTOs\TranslationDTO;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * Locales admin — translation CRUD at /mark/locales.
 *
 * Routes:
 * - GET  /mark/locales                list with locale switch, group filter, search
 * - GET  /mark/locales/create         create form
 * - POST /mark/locales/create         create submit
 * - GET  /mark/locales/{id}/edit      edit form
 * - POST /mark/locales/{id}/edit      edit submit
 * - POST /mark/locales/{id}/delete    delete
 * - POST /mark/locales/import         JSON import
 *
 * All state changes are CSRF-protected by CsrfMiddleware and validated
 * before any write (invalid locale/key re-render with an error).
 */
#[Route(path: '/mark/locales', method: 'GET', name: 'mark.locales')]
#[Route(path: '/mark/locales/create', method: 'GET', name: 'mark.locales.create', handlerMethod: 'createForm')]
#[Route(path: '/mark/locales/create', method: 'POST', name: 'mark.locales.create.submit', handlerMethod: 'createSubmit')]
#[Route(path: '/mark/locales/{id}/edit', method: 'GET', name: 'mark.locales.edit', handlerMethod: 'editForm')]
#[Route(path: '/mark/locales/{id}/edit', method: 'POST', name: 'mark.locales.edit.submit', handlerMethod: 'editSubmit')]
#[Route(path: '/mark/locales/{id}/delete', method: 'POST', name: 'mark.locales.delete', handlerMethod: 'delete')]
#[Route(path: '/mark/locales/import', method: 'POST', name: 'mark.locales.import', handlerMethod: 'import')]
final class LocalesAction
{
    /**
     * Audit emission guarded by class_exists, never imported: translations
     * are manageable without the Audit module; with it installed every
     * write here is recorded. A use-statement would close an
     * install-order cycle (Locales -> Audit -> Users -> Locales).
     */
    private static function audit(string $action, string $entity, string $detail, string $ip): void
    {
        if (!class_exists("Modules\\Audit\\Interfaces\\HTTP\\AuditServices")) {
            return;
        }

        \Modules\Audit\Interfaces\HTTP\AuditServices::log($action, $entity, $detail, $ip);
    }

    public function handle(Request $request): Response
    {
        $service = LocalesServices::translations();

        $activeLocale = $this->activeLocale($request);
        $group = $this->stringParam($request->query('group', ''));
        $search = $this->stringParam($request->query('q', ''));

        if ('' !== $search) {
            $translations = $service->search($search, $activeLocale);
        } elseif ('' !== $group) {
            $translations = $service->getByGroup($group, $activeLocale);
        } else {
            $translations = $service->getAll($activeLocale);
        }

        $imported = $this->importedCount($request);

        $content = $this->listHtml($activeLocale, $group, $search, $translations, $imported);

        return Response::html(AdminLayout::render(
            'Translations',
            $content,
            'locales',
            [['label' => 'New translation', 'href' => '/mark/locales/create']],
        ));
    }

    public function createForm(Request $request): Response
    {
        return Response::html($this->form(
            'New translation',
            '/mark/locales/create',
            'Create translation',
            [],
        ));
    }

    public function createSubmit(Request $request): Response
    {
        $key = $this->stringParam($request->body('key', ''));
        $localeCode = $this->stringParam($request->body('locale', ''));
        $value = $this->stringParam($request->body('value', ''));
        $group = $this->stringParam($request->body('group', 'general'));

        $errors = $this->validate($key, $localeCode, $value);

        if ([] === $errors) {
            LocalesServices::translations()->set($key, $localeCode, $value, $group);
            CacheInvalidator::flush();
            self::audit('locales.create', 'translation:' . $key, 'Created translation ' . $key, $request->getClientIp());

            return Response::redirect('/mark/locales?locale=' . urlencode($localeCode));
        }

        return Response::html($this->form(
            'New translation',
            '/mark/locales/create',
            'Create translation',
            $errors,
            ['key' => $key, 'locale' => $localeCode, 'value' => $value, 'group' => $group],
        ));
    }

    public function editForm(Request $request): Response
    {
        $id = $request->routeParam('id');
        $translation = null === $id ? null : LocalesServices::translations()->findById($id);

        if (null === $translation) {
            return Response::redirect('/mark/locales');
        }

        return Response::html($this->form(
            'Edit translation',
            '/mark/locales/' . $translation->id() . '/edit',
            'Save changes',
            [],
            [
                'key'    => $translation->key(),
                'locale' => $translation->locale()->code(),
                'value'  => $translation->value(),
                'group'  => $translation->group(),
            ],
            true,
        ));
    }

    public function editSubmit(Request $request): Response
    {
        $id = $request->routeParam('id');
        $service = LocalesServices::translations();
        $translation = null === $id ? null : $service->findById($id);

        if (null === $translation) {
            return Response::redirect('/mark/locales');
        }

        $value = $this->stringParam($request->body('value', ''));
        $group = $this->stringParam($request->body('group', 'general'));

        if ('' === $value) {
            return Response::html($this->form(
                'Edit translation',
                '/mark/locales/' . $translation->id() . '/edit',
                'Save changes',
                ['Value is required.'],
                [
                    'key'    => $translation->key(),
                    'locale' => $translation->locale()->code(),
                    'value'  => $value,
                    'group'  => $group,
                ],
                true,
            ));
        }

        $translation->updateValue($value);
        $translation->updateGroup($group);
        $service->save($translation);
        CacheInvalidator::flush();
        self::audit('locales.update', 'translation:' . $translation->key(), 'Updated translation ' . $translation->key(), $request->getClientIp());

        return Response::redirect('/mark/locales?locale=' . urlencode($translation->locale()->code()));
    }

    public function delete(Request $request): Response
    {
        $id = $request->routeParam('id');
        $service = LocalesServices::translations();
        $translation = null === $id ? null : $service->findById($id);

        if (null !== $translation) {
            $locale = $translation->locale()->code();
            $service->delete($translation->key(), $locale);
            CacheInvalidator::flush();
            self::audit('locales.delete', 'translation:' . $translation->key(), 'Deleted translation ' . $translation->key(), $request->getClientIp());

            return Response::redirect('/mark/locales?locale=' . urlencode($locale));
        }

        return Response::redirect('/mark/locales');
    }

    /**
     * Import translations from an uploaded JSON file (flat or grouped format).
     */
    public function import(Request $request): Response
    {
        $file = $_FILES['translation_file'] ?? null;

        if (!is_array($file) || UPLOAD_ERR_OK !== ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return Response::html(AdminLayout::render(
                'Translations',
                $this->messageBox('Import failed — no valid file uploaded.'),
                'locales',
            ));
        }

        $tmpPath = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $contents = '' !== $tmpPath && is_file($tmpPath) ? file_get_contents($tmpPath) : false;

        if (false === $contents) {
            return Response::html(AdminLayout::render(
                'Translations',
                $this->messageBox('Import failed — could not read the uploaded file.'),
                'locales',
            ));
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return Response::html(AdminLayout::render(
                'Translations',
                $this->messageBox('Import failed — the file is not valid JSON.'),
                'locales',
            ));
        }

        [$locale, $entries] = $this->parseImport($data);

        if (!Locale::fromString($locale)->isAvailable()) {
            return Response::html(AdminLayout::render(
                'Translations',
                $this->messageBox('Import failed — unknown locale "' . AdminLayout::e($locale) . '".'),
                'locales',
            ));
        }

        $imported = $this->importEntries($locale, $entries);
        CacheInvalidator::flush();
        self::audit('locales.import', 'locale:' . $locale, 'Imported ' . $imported . ' translations', $request->getClientIp());

        return Response::redirect('/mark/locales?locale=' . urlencode($locale) . '&imported=' . $imported);
    }

    /**
     * @param list<string>          $errors
     * @param array<string, string> $values
     */
    private function form(
        string $title,
        string $action,
        string $submitLabel,
        array $errors,
        array $values = [],
        bool $editing = false,
    ): string {
        $errorHtml = AdminLayout::messages($errors, 'err');
        $csrf = AdminLayout::csrfField();

        $key = AdminLayout::e($values['key'] ?? '');
        $value = AdminLayout::e($values['value'] ?? '');
        $group = AdminLayout::e($values['group'] ?? 'general');
        $localeLabel = AdminLayout::e(Locale::fromString($values['locale'] ?? 'sk_SK')->name());

        // Key and locale are immutable on edit — shown as read-only info so
        // an admin can never change them silently (they are not submitted).
        $keyField = $editing
            ? '<p class="m-mono cba-field-hint">' . $key . '</p>'
            : '<input type="text" id="key" name="key" value="' . $key . '" required>';
        $localeField = $editing
            ? '<p class="m-mono cba-field-hint">' . $localeLabel . '</p>'
            : '<select id="locale" name="locale">' . $this->localeOptions($values['locale'] ?? '') . '</select>';

        $content = ViewRenderer::render('Modules/Locales/Interfaces/HTTP/templates/locales-list.phtml', ['action' => $action, 'csrf' => $csrf, 'errorHtml' => $errorHtml, 'group' => $group, 'keyField' => $keyField, 'localeField' => $localeField, 'submitLabel' => $submitLabel, 'value' => $value]);

        return AdminLayout::render($title, $content, 'locales');
    }

    /**
     * @param list<TranslationDTO> $translations
     */
    private function listHtml(
        string $activeLocale,
        string $group,
        string $search,
        array $translations,
        int $imported,
    ): string {
        $rows = '';
        $csrf = AdminLayout::csrfField();

        foreach ($translations as $translation) {
            $id = AdminLayout::e($translation->id);
            $key = AdminLayout::e($translation->key);
            $value = AdminLayout::e($translation->value);
            $rowGroup = AdminLayout::e($translation->group);
            $iconEdit = AdminLayout::iconEdit();
            $iconDelete = AdminLayout::iconDelete();
            $rows .= ViewRenderer::render('Modules/Locales/Interfaces/HTTP/templates/locales-edit.phtml', ['csrf' => $csrf, 'iconDelete' => $iconDelete, 'iconEdit' => $iconEdit, 'id' => $id, 'key' => $key, 'rowGroup' => $rowGroup, 'value' => $value]);
        }

        $table = '' === $rows
            ? '<p class="m-muted">No translations match.</p>'
            : '<table class="m-table"><thead><tr><th>Key</th><th>Value</th><th>Group</th><th></th></tr></thead><tbody>'
                . $rows . '</tbody></table>';

        return $this->filterBar($activeLocale, $group, $search, $imported)
            . $table
            . $this->importForm();
    }

    /**
     * Locale switcher + group filter + search bar.
     */
    private function filterBar(string $activeLocale, string $group, string $search, int $imported): string
    {
        $localeTabs = '';

        foreach (Locale::available() as $code => $locale) {
            $active = $code === $activeLocale ? ' layout-sidebar__link--active' : '';
            $label = AdminLayout::e($locale->name());
            // Inactive tabs inherit the page bg (transparent nav) — use muted
            // ink (≥ 4.5:1 in both themes) instead of the nav-white `inherit`.
            $style = '' === $active ? ' m-pill--muted' : '';
            $localeTabs .= "<a class=\"m-pill{$active}{$style}\" href=\"/mark/locales?locale={$code}\">{$label}</a>";
        }

        $groupValue = AdminLayout::e($group);
        $searchValue = AdminLayout::e($search);
        $importNotice = $imported > 0
            ? '<div class="m-alert m-alert-success">Import completed: ' . $imported . ' translations.</div>'
            : '';

        return $importNotice
            . '<div class="m-filter-row">'
            . '<div class="m-filter-bar">' . $localeTabs . '</div>'
            . '<form method="GET" action="/mark/locales" class="m-filter-form">'
            . '<input type="hidden" name="locale" value="' . AdminLayout::e($activeLocale) . '">'
            . '<input type="text" name="group" class="m-input" placeholder="group (nav, home…)" value="' . $groupValue . '">'
            . '<input type="text" name="q" class="m-input" placeholder="Search…" value="' . $searchValue . '">'
            . '<button class="m-btn m-btn-sm" type="submit">Filter</button>'
            . '</form></div>';
    }

    /**
     * JSON import upload form.
     */
    private function importForm(): string
    {
        $csrf = AdminLayout::csrfField();

        return '<div class="m-card">'
            . '<h2 class="m-heading">Import JSON</h2>'
            . '<form class="m-form" method="POST" action="/mark/locales/import" enctype="multipart/form-data">'
            . $csrf
            . '<input type="file" name="translation_file" accept="application/json,.json" required>'
            . '<p class="m-muted m-hint">Flat {"nav.home": "Home"} or grouped {"locale": "en_US", "translations": {...}}</p>'
            . '<div><button class="m-btn m-btn-primary" type="submit">Import</button></div>'
            . '</form></div>';
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{0: string, 1: array<string, mixed>} [locale, entries]
     */
    private function parseImport(array $data): array
    {
        if (isset($data['locale']) && is_string($data['locale']) && isset($data['translations']) && is_array($data['translations'])) {
            /** @var array<string, mixed> $entries */
            $entries = $data['translations'];

            return [$data['locale'], $entries];
        }

        /** @var array<string, mixed> $flat */
        $flat = $data;

        return ['en_US', $flat];
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function importEntries(string $locale, array $entries): int
    {
        $service = LocalesServices::translations();
        $imported = 0;

        foreach ($entries as $key => $value) {
            if ('' === $key) {
                continue;
            }

            $valueStr = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
            $group = str_contains($key, '.') ? explode('.', $key, 2)[0] : 'general';

            try {
                $service->set($key, $locale, $valueStr, $group);
                $imported++;
            } catch (\InvalidArgumentException) {
                // Skip invalid keys/locales silently — counted via redirect total.
            }
        }

        return $imported;
    }

    /**
     * @return list<string>
     */
    private function validate(string $key, string $localeCode, string $value): array
    {
        $errors = [];

        if ('' === $key) {
            $errors[] = 'Translation key is required.';
        } elseif (1 !== preg_match('/^[a-zA-Z0-9._-]+$/', $key)) {
            $errors[] = 'Key must match [a-zA-Z0-9._-]+ (e.g. nav.home).';
        }

        if ('' === $localeCode) {
            $errors[] = 'Locale is required.';
        } elseif (!LocalesServices::locale()->isAvailable($localeCode)) {
            $errors[] = 'The selected locale is not available.';
        }

        if ('' === $value) {
            $errors[] = 'Value is required.';
        }

        return $errors;
    }

    private function stringParam(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private function activeLocale(Request $request): string
    {
        $candidate = $this->stringParam($request->query('locale', ''));

        return LocalesServices::locale()->isAvailable($candidate) ? $candidate : Locale::default()->code();
    }

    private function importedCount(Request $request): int
    {
        $search = $this->stringParam($request->query('q', ''));
        $imported = $request->query('imported');

        return '' === $search && is_numeric($imported) ? (int) $imported : 0;
    }

    private function messageBox(string $message): string
    {
        return '<div class="cba-msg cba-msg--err">' . AdminLayout::e($message) . '</div>'
            . '<p><a class="m-btn m-btn-ghost" href="/mark/locales">Back to translations</a></p>';
    }

    private function localeOptions(string $selected): string
    {
        $options = '';

        foreach (Locale::available() as $code => $locale) {
            $isSelected = $code === $selected ? ' selected' : '';
            $options .= "<option value=\"{$code}\"{$isSelected}>" . AdminLayout::e($locale->name()) . '</option>';
        }

        return $options;
    }
}
