<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Media\Application\Services\MediaService;
use Modules\Media\Infrastructure\Storage\Providers\CloudinaryApi;
use Modules\Media\Interfaces\HTTP\MediaIcons;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Cloudinary library — GET /mark/media/cloudinary.
 *
 * Browses the remote folder tree so files uploaded outside this
 * installation become reachable, and offers the selection for import into
 * media.db (the import itself is POST media.cloudinary.import).
 *
 * Three states, and none of them is a 500: not configured (no outbound
 * request at all), remote failure (an explained message), success (folder
 * navigation + grid). A failed remote listing must never blank the admin.
 */
#[Route(path: '/mark/media/cloudinary', method: 'GET', name: 'media.cloudinary')]
final readonly class MediaCloudinaryAction
{
    use ResolvesRouteUrls;

    private const int PER_PAGE = 60;

    /**
     * @param CloudinaryApi|null $api Test seam: CI has no credentials, so a
     *                                substituted client is the only way to
     *                                exercise these states offline
     */
    public function __construct(
        private ?CloudinaryApi $api = null,
        ?RouterInterface $router = null,
    ) {
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        $api = $this->api ?? MediaServices::cloudinaryApi();

        if (null === $api) {
            return $this->page($this->state(
                'Cloudinary is not configured — set CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET to browse the remote library.',
            ));
        }

        $requested = MediaService::normalizeFolder(is_string($request->query('folder')) ? $request->query('folder') : '');
        $cursor = is_string($request->query('c')) ? $request->query('c') : '';
        $landing = MediaService::normalizeFolder($api->folder());

        // The whole account is a DELIBERATE request (`?root=1`), never what
        // an absent or malformed folder falls back to — landing on it by
        // accident would show every file in the account under one heading.
        if (self::wantsRoot($request)) {
            return $this->page($this->rootState($api, $request));
        }

        $target = '' !== $requested ? $requested : $landing;

        try {
            $vars = $this->browse($api, $target, $cursor);
            $vars['noticeHtml'] = $this->notice($request);
        } catch (\RuntimeException $e) {
            $vars = $this->fallbackOrState($api, $requested, $landing, $e);
        }

        return $this->page($vars);
    }

    private static function wantsRoot(Request $request): bool
    {
        $root = $request->query('root');

        return is_string($root) && in_array($root, ['1', 'true'], true);
    }

    /**
     * The account root: every top-level folder, and no file grid.
     *
     * Only folders. A root listing that also searched would answer with the
     * whole account (`expression=folder:` matches subfolders too), which is
     * both slow to page through and useless as a "where is everything?"
     * question — the folders ARE the answer, and each one opens its files.
     *
     * @return array<string, string>
     */
    private function rootState(CloudinaryApi $api, Request $request): array
    {
        try {
            $rootFolders = $api->folders('');
        } catch (\RuntimeException $e) {
            return $this->state('The Cloudinary library could not be loaded. ' . $e->getMessage());
        }

        return [
            'noticeHtml'    => $this->notice($request),
            'crumbsHtml'    => $this->crumbs('', '', true),
            'foldersHtml'   => $this->folderTiles($rootFolders, '', true),
            'cardsHtml'     => '',
            'summaryHtml'   => '<span class="m-muted">' . count($rootFolders) . ' folders in the library — open one to see its files.</span>',
            'csrf'          => '',
            'currentFolder' => '',
            'nextHref'      => '',
        ];
    }

    /**
     * A folder that does not open lands on the configured one; anything
     * else is the explained failure state. Either way the admin gets a
     * page, not a 500.
     *
     * @return array<string, string>
     */
    private function fallbackOrState(CloudinaryApi $api, string $requested, string $landing, \RuntimeException $error): array
    {
        if ('' !== $requested && $requested !== $landing) {
            try {
                $vars = $this->browse($api, $landing, '');
                $vars['noticeHtml'] = AdminLayout::messages([
                    'That folder could not be opened — showing ' . ('' !== $landing ? $landing : 'the library root') . '.',
                ], 'err');

                return $vars;
            } catch (\RuntimeException) {
                // Fall through: the landing folder failed too.
            }
        }

        return $this->state('The Cloudinary library could not be loaded. ' . $error->getMessage());
    }

    /**
     * @return array<string, string>
     */
    private function browse(CloudinaryApi $api, string $folder, string $cursor): array
    {
        $listing = $api->search($folder, self::PER_PAGE, $cursor);
        $nextQuery = array_filter(['folder' => $folder, 'c' => $listing['next_cursor']]);
        $nextHref = '' !== $listing['next_cursor']
            ? $this->url('media.cloudinary') . '?' . http_build_query($nextQuery)
            : '';

        return [
            'noticeHtml'    => '',
            'crumbsHtml'    => $this->crumbs($folder, MediaService::normalizeFolder($api->folder()), false),
            'foldersHtml'   => $this->folderTiles($api->folders($folder), $folder, false),
            'cardsHtml'     => $this->cardsHtml($api, $listing['resources']),
            'summaryHtml'   => '<span class="m-muted">' . AdminLayout::e((string) $listing['total_count']) . ' resources in this folder</span>',
            'csrf'          => AdminLayout::csrfField(),
            'currentFolder' => AdminLayout::e($folder),
            'nextHref'      => AdminLayout::e($nextHref),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function state(string $message): array
    {
        return [
            'noticeHtml'    => AdminLayout::messages([$message], 'err'),
            'crumbsHtml'    => '',
            'foldersHtml'   => '',
            'cardsHtml'     => '',
            'summaryHtml'   => '',
            'csrf'          => '',
            'currentFolder' => '',
            'nextHref'      => '',
        ];
    }

    /**
     * The PRG markers the import redirects back with — a redirect nobody
     * renders is a silent no-op.
     */
    private function notice(Request $request): string
    {
        $imported = $request->query('imported');
        $skipped = $request->query('skipped');

        if (is_string($imported) && is_numeric($imported)) {
            $skippedCount = is_string($skipped) && is_numeric($skipped) ? (int) $skipped : 0;

            return AdminLayout::messages([
                sprintf('Imported %d, skipped %d.', (int) $imported, $skippedCount),
            ], 'ok');
        }

        $error = $request->query('error');
        if ('selection' === $error) {
            return AdminLayout::messages(['Select at least one file to import.'], 'err');
        }
        if ('too-many' === $error) {
            return AdminLayout::messages(['An import takes at most 50 files at a time.'], 'err');
        }
        if ('unreachable' === $error) {
            return AdminLayout::messages(['The Cloudinary library could not be reached — nothing was imported.'], 'err');
        }

        return '';
    }

    /**
     * The crumb chain: Library (the account root) / landing / … / current.
     *
     * The root crumb is what makes the folders reachable: the landing folder
     * used to BE the root link, so a landing folder with no subfolders left
     * every sibling of it one URL away from reachable.
     */
    private function crumbs(string $folder, string $landing, bool $atRoot): string
    {
        $html = '<a class="m-pill' . ($atRoot ? ' m-pill-active' : '') . '" href="' . AdminLayout::e($this->url('media.cloudinary')) . '?root=1">Library</a>';

        if ($atRoot) {
            return $html;
        }

        return $html . $this->landingCrumb($folder, $landing) . $this->belowLandingCrumbs($folder, $landing);
    }

    private function landingCrumb(string $folder, string $landing): string
    {
        if ('' === $landing) {
            return '';
        }

        $href = AdminLayout::e($this->url('media.cloudinary') . '?' . http_build_query(['folder' => $landing]));
        $separator = ' <span class="m-muted">/</span> ';

        return $separator . '<a class="m-pill' . ($folder === $landing ? ' m-pill-active' : '') . '" href="' . $href . '">' . AdminLayout::e($landing) . '</a>';
    }

    /**
     * Only the segments BELOW the landing folder get their own crumb — the
     * landing folder is already the previous crumb.
     */
    private function belowLandingCrumbs(string $folder, string $landing): string
    {
        $landingParts = '' === $landing ? [] : explode('/', $landing);
        $segments = '' === $folder ? [] : explode('/', $folder);
        if ([] !== $landingParts && array_slice($segments, 0, count($landingParts)) === $landingParts) {
            $segments = array_slice($segments, count($landingParts));
        }

        $html = '';
        $prefix = $landing;
        foreach ($segments as $segment) {
            $prefix = '' === $prefix ? $segment : $prefix . '/' . $segment;
            $label = AdminLayout::e($segment);
            $separator = ' <span class="m-muted">/</span> ';

            if ($prefix === $folder) {
                $html .= $separator . '<span class="m-pill m-pill-active">' . $label . '</span>';
                continue;
            }

            $href = AdminLayout::e($this->url('media.cloudinary') . '?' . http_build_query(['folder' => $prefix]));
            $html .= $separator . '<a class="m-pill" href="' . $href . '">' . $label . '</a>';
        }

        return $html;
    }

    /**
     * Folder tiles — the same `.m-folder-card` grid the local library
     * renders, so "a folder" looks like a folder on both screens.
     *
     * @param list<array{name: string, path: string}> $folders
     */
    private function folderTiles(array $folders, string $currentFolder, bool $atRoot): string
    {
        if ([] === $folders) {
            return '';
        }

        $tiles = '';
        foreach ($folders as $child) {
            $href = AdminLayout::e($this->url('media.cloudinary') . '?' . http_build_query(['folder' => $child['path']]));
            $isCurrent = !$atRoot && $child['path'] === $currentFolder;
            $tiles .= '<a class="m-folder-card' . ($isCurrent ? ' m-folder-card--active' : '') . '" href="' . $href . '">'
                . '<span class="m-folder-card__icon">' . MediaIcons::folder() . '</span>'
                . '<span class="m-folder-card__name">' . AdminLayout::e($child['name']) . '</span>'
                . '</a>';
        }

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-folders.phtml', [
            'tiles'       => $tiles,
            'folderCount' => (string) count($folders),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $resources
     */
    private function cardsHtml(CloudinaryApi $api, array $resources): string
    {
        $publicIds = [];
        foreach ($resources as $resource) {
            if (is_string($resource['public_id'] ?? null) && '' !== $resource['public_id']) {
                $publicIds[] = $resource['public_id'];
            }
        }
        $trackedPaths = array_fill_keys(MediaServices::media()->trackedPaths($publicIds), true);

        return $this->renderCards($api, $resources, $trackedPaths);
    }

    /**
     * @param list<array<string, mixed>> $resources
     * @param array<string, bool>        $trackedPaths
     */
    private function renderCards(CloudinaryApi $api, array $resources, array $trackedPaths): string
    {
        $html = '';

        foreach ($resources as $resource) {
            $publicId = is_string($resource['public_id'] ?? null) ? $resource['public_id'] : '';
            if ('' === $publicId) {
                continue;
            }

            $html .= ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-cloudinary-card.phtml', [
                'publicId' => AdminLayout::e($publicId),
                'thumbUrl' => AdminLayout::e($api->url($publicId)),
                'label'    => AdminLayout::e($this->label($resource, $publicId)),
                'isImage'  => 'image' === (is_string($resource['resource_type'] ?? null) ? $resource['resource_type'] : ''),
                'tracked'  => isset($trackedPaths[$publicId]),
            ]);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function label(array $resource, string $publicId): string
    {
        $format = is_string($resource['format'] ?? null) ? $resource['format'] : '';
        $filename = is_string($resource['filename'] ?? null) && '' !== $resource['filename']
            ? $resource['filename']
            : basename($publicId);

        return '' !== $format ? $filename . '.' . $format : basename($publicId);
    }

    /**
     * @param array<string, string> $vars
     */
    private function page(array $vars): Response
    {
        $content = ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-cloudinary.phtml', $vars + ['url' => $this->urlBuilder()]);

        return Response::html(AdminLayout::render(
            'Cloudinary library',
            $content,
            'media',
            [['label' => 'Back to media', 'href' => $this->url('media.index')]],
        router: $this->router()));
    }
}
