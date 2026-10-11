<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Blog\Application\Services\BlogCrudService;
use Modules\CardboardKit\Application\Services\AdminLocale;
use Modules\CardboardKit\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Users\Interfaces\HTTP\AuthServices;

/**
 * Articles create — GET/POST /mark/articles/create.
 *
 * GET: renders the article creation form with fields for title, slug,
 *      date, category, tags, excerpt, author, image (media picker),
 *      members_only, translation, and markdown body.
 * POST: validates input, generates slug, saves the .md file, and
 *       redirects to the articles index.
 */
#[Route(path: '/mark/articles/create', method: 'GET|POST', name: 'articles.create')]
final class ArticlesCreateAction
{
    public function handle(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleCreate($request, ArticleFormPayload::extract($request));
        }

        $locale = $this->resolveLocale($request);

        return Response::html($this->form([], $locale, [], $request));
    }

    /**
     * @param array<string, string> $data
     */
    private function handleCreate(Request $request, array $data): Response
    {
        $locale = $this->resolveLocale($request);
        $errors = $this->validate($data);

        if ([] !== $errors) {
            return Response::html($this->form($data, $locale, $errors, $request), 400);
        }

        $service = new BlogCrudService();
        $slug = '' !== $data['slug'] ? $data['slug'] : $service->generateSlug($data['title']);

        if ($service->exists($slug, $locale)) {
            return Response::html($this->form($data, $locale, ['Article with this slug already exists.']), 400);
        }

        $meta = array_merge(
            ArticleFormPayload::passthroughMeta($request),
            $this->buildMeta($this->stampOwnByline($data)),
        );
        $service->save($slug, $meta, $data['body'], $locale);

        $auth = AuditServices::auth();
        $userId = $auth->currentUserId();
        $actorName = null !== $userId ? ($auth->currentUser()?->name() ?? 'admin') : 'admin';

        CacheInvalidator::flush();
        AuditServices::log(
            'article.create',
            'article',
            'Created: ' . $data['title'] . ' (' . $slug . ')',
            $request->getClientIp(),
        );

        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', '/mark/articles?locale=' . $locale . '&toast=Article+created+successfully');

            return $response;
        }

        return Response::redirect('/mark/articles?locale=' . $locale . '&toast=Article+created+successfully');
    }

    /**
     * @param array<string, string> $data
     * @param list<string>          $errors
     */
    private function form(array $data, string $locale, array $errors = [], ?Request $request = null): string
    {
        $errorHtml = AdminLayout::messages($errors, 'err');
        $csrf = AdminLayout::csrfField();
        $e = static fn (string $key, string $default = ''): string => AdminLayout::e($data[$key] ?? $default);

        $localeParam = ContentServices::mapper()->languageCode($locale);
        $backHref = '/mark/articles?locale=' . $localeParam;

        $htmx = null !== $request && AdminLayout::isHtmx($request);
        $hxPost = $htmx ? ' hx-post="/mark/articles/create?locale=' . $localeParam . '" hx-indicator="#create-spinner"' : '';
        $spinner = '<span class="htmx-indicator" id="create-spinner">⏳</span> ';

        $content = '<form method="POST" action="/mark/articles/create?locale=' . $localeParam . '"' . $hxPost . '>'
            . $errorHtml
            . $this->formFields($csrf, $data, $locale)
            . '<div class="m-filter-bar">'
            . '<button class="m-btn m-btn-primary" type="submit">' . $spinner . 'Create article</button>'
            . '<a class="m-btn m-btn-ghost" href="' . $backHref . '">Cancel</a>'
            . '</div>'
            . '</form>'
            . ArticlesFormHelper::mediaPickerScript()
            . ArticlesFormHelper::formGuardScript();

        return AdminLayout::render('New article', $content, 'articles');
    }

    /**
     * @param array<string, string> $data
     */
    private function formFields(string $csrf, array $data, string $locale): string
    {
        $e = static fn (string $key, string $default = ''): string => AdminLayout::e($data[$key] ?? $default);

        $localeInput = '<input type="hidden" name="locale" value="' . $e('locale', $locale) . '">';
        $dateValue = $e('date', date('Y-m-d'));
        $titleValue = $e('title');
        $slugValue = $e('slug');
        $categoryValue = $e('category');
        $categoryField = ArticlesFormHelper::categoryInput($data['category'] ?? '', $locale);
        $tagsValue = $e('tags');
        $tagsField = ArticlesFormHelper::tagsInput($data['tags'] ?? '', $locale);
        $excerptValue = $e('excerpt');
        $authorField = ArticlesFormHelper::authorSelect($data['author_email'] ?? '', $locale);
        $imageValue = $e('image');
        $translationValue = $e('translation');
        $bodyValue = $e('body');
        $membersChecked = '1' === ($data['members_only'] ?? '') ? 'checked' : '';
        // New articles default to published — the checkbox starts checked.
        $publishedChecked = '1' === ($data['published'] ?? '1') ? 'checked' : '';

        return ViewRenderer::render('Modules/Blog/Interfaces/HTTP/templates/article-create.phtml', ['authorField' => $authorField, 'bodyValue' => $bodyValue, 'categoryField' => $categoryField, 'csrf' => $csrf, 'dateValue' => $dateValue, 'excerptValue' => $excerptValue, 'imageValue' => $imageValue, 'localeInput' => $localeInput, 'membersChecked' => $membersChecked, 'publishedChecked' => $publishedChecked, 'slugValue' => $slugValue, 'tagsField' => $tagsField, 'titleValue' => $titleValue, 'translationValue' => $translationValue]);
    }

    /**
     * @param array<string, string> $data
     *
     * @return list<string>
     */
    private function validate(array $data): array
    {
        $errors = [];

        if ('' === $data['title']) {
            $errors[] = 'Title is required.';
        }
        if ('' === $data['date']) {
            $errors[] = 'Date is required.';
        }
        if ('' === $data['body']) {
            $errors[] = 'Content is required.';
        }

        return $errors;
    }

    /**
     * @param array<string, string> $data
     *
     * @return array<string, mixed>
     */
    /**
     * A non-admin writes under their OWN byline.
     *
     * The form posts an `author_email` select so an administrator can attribute
     * a post to someone else. For anyone else the posted value is ignored and
     * the session user is stamped in — otherwise an author could file a post
     * under a colleague, and ownership would follow the lie.
     *
     * @param array<string, string> $data
     *
     * @return array<string, string>
     */
    private function stampOwnByline(array $data): array
    {
        $user = AuthServices::auth()->currentUser();

        if (null === $user || $user->hasRole('admin')) {
            return $data;
        }

        $data['author_email'] = $user->email();
        $data['author'] = $user->name();

        return $data;
    }

    /**
     * @param array<string, string> $data
     *
     * @return array<string, mixed>
     */
    private function buildMeta(array $data): array
    {
        $tags = '' !== $data['tags']
            ? array_map(trim(...), explode(',', $data['tags']))
            : [];

        $meta = [
            'title'        => $data['title'],
            'date'         => $data['date'],
            'tags'         => $tags,
            'excerpt'      => $data['excerpt'],
            'author'       => $data['author_email'],
            'author_email' => $data['author_email'],
            'category'     => $data['category'],
        ];

        if ('' !== $data['image']) {
            $meta['image'] = $data['image'];
        }
        if ('' !== $data['translation']) {
            $meta['translation'] = $data['translation'];
        }
        if ('1' === $data['members_only']) {
            $meta['members_only'] = true;
        }

        // Always persisted explicitly — an omitted flag would default the
        // article to published on the public frontend.
        $meta['published'] = '1' === ($data['published'] ?? '1');

        return $meta;
    }

    private function resolveLocale(Request $request): string
    {
        $localeRaw = is_string($request->query('locale')) ? $request->query('locale') : 'sk';

        return AdminLocale::canonical($localeRaw);
    }
}
