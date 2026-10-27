<?php

declare(strict_types = 1);

namespace Modules\Media\Application\Services;

use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use Modules\Media\Domain\Model\MediaAsset;
use Modules\Media\Infrastructure\Persistence\MediaFolderRepository;
use Modules\Media\Infrastructure\Persistence\MediaRepository;
use Modules\Media\Infrastructure\Storage\Providers\CloudinaryProvider;
use Modules\Media\Infrastructure\Storage\Providers\LocalStorageProviderAdapter;
use Modules\Media\Infrastructure\Storage\Providers\MediaProviderInterface;

/**
 * MediaService — application service coordinating DB metadata + filesystem.
 *
 * Uploads go through LocalStorageProvider (validation + storage) and the
 * metadata is recorded in media.db via MediaRepository. Deletes remove
 * both the DB record and the filesystem file.
 */
final readonly class MediaService
{
    public function __construct(
        private MediaRepository $repository,
        private MediaProviderInterface $storage,
        private MediaFolderRepository $folders,
    ) {
    }

    /**
     * List all assets, newest first.
     *
     * @return list<MediaAsset>
     */
    public function list(int $page = 1, int $perPage = 50): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        return $this->repository->all($perPage, $offset);
    }

    public function findById(string $id): ?MediaAsset
    {
        return $this->repository->findById($id);
    }

    /**
     * Count one hit on the file stored at the given uploads-relative path.
     *
     * A server hit, not a human view: the serving route emits
     * `Cache-Control: immutable`, so repeat views from the same browser never
     * reach the server and are never counted. Useful for ranking, not as a
     * view total — the admin grid says so.
     */
    public function recordView(string $relativePath): void
    {
        $this->repository->incrementViewByPath($relativePath);
    }

    /**
     * Upload a file: validate + store on disk, record metadata in DB.
     *
     * @param array<string, mixed> $file $_FILES entry
     *
     * @throws \RuntimeException on validation or storage failure
     */
    public function upload(array $file, string $uploadedBy = '', string $provider = '', string $folder = ''): MediaAsset
    {
        // Cloudinary only accepts images — reject non-image uploads.
        $mimeType = is_string($file['type'] ?? null) ? $file['type'] : '';
        if ('cloudinary' === $provider && !$this->isImageMimeType($mimeType)) {
            throw new \RuntimeException('Cloudinary only accepts images (jpg, png, gif, webp).');
        }

        $storage = self::resolveProvider($provider, $this->storage);
        $result = $storage->store($file);

        $originalName = is_string($file['name'] ?? null) ? $file['name'] : '';
        $asset = MediaAsset::create(
            filename: $result['path'],
            originalName: $originalName,
            path: $result['path'],
            size: $result['size'],
            mimeType: $result['mime_type'],
            uploadedBy: $uploadedBy,
            provider: $provider ?: $storage->getName(),
            folder: 'local' === $storage->getName() ? self::normalizeFolder($folder) : '',
        );

        $this->repository->insert($asset);

        return $asset;
    }

    /**
     * Delete an asset from DB + filesystem.
     */
    public function delete(string $id): bool
    {
        $asset = $this->repository->findById($id);

        if (null === $asset) {
            return false;
        }

        $this->deleteRemote($asset);

        $this->repository->deleteTagsForAsset($id);

        return $this->repository->delete($id);
    }

    /**
     * Delete multiple assets from DB + filesystem.
     *
     * @param list<string> $ids
     *
     * @return list<string> IDs of successfully deleted assets
     */
    public function batchDelete(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // Fetch all matching assets first (for filesystem cleanup).
        $assets = $this->repository->findByIds($ids);

        // Delete remote/filesystem copies — resolve provider per asset.
        foreach ($assets as $asset) {
            $this->deleteRemote($asset);
        }

        // Clean up tags and delete DB rows.
        $this->repository->deleteTagsForAssets($ids);
        $deleted = $this->repository->deleteBatch($ids);

        // Return IDs that were actually deleted.
        $deletedIds = array_map(static fn (MediaAsset $a) => $a->id(), array_slice($assets, 0, $deleted));

        return $deletedIds;
    }

    public function updateMetadata(string $id, string $altText, string $caption): bool
    {
        $asset = $this->repository->findById($id);
        if (null === $asset) {
            return false;
        }

        return $this->repository->updateMetadata($id, $altText, $caption);
    }

    public function url(string $id): string
    {
        $asset = $this->repository->findById($id);
        if (null === $asset) {
            return '';
        }

        $storage = self::resolveProvider($asset->provider(), $this->storage);

        return $storage->url($asset->path());
    }

    public function count(): int
    {
        return $this->repository->count();
    }

    // ─── Category / Tag / Sort methods ──────────────────────────────

    /**
     * List assets with combined filtering and sorting.
     *
     * Every filter is optional and they compose (see
     * MediaRepository::findByFilters()). `source` is the storage-source
     * pill: '' = everything, 'local' or 'cloudinary' = that resolved
     * provider. A legacy row (`provider = ''`) resolves through the
     * factory, so it belongs to whichever provider this environment
     * defaults to — never to a hardcoded name.
     *
     * @return list<MediaAsset>
     */
    public function listWithSorting(
        string $sort = 'newest',
        string $type = '',
        string $category = '',
        string $tag = '',
        int $page = 1,
        int $perPage = 50,
        string $source = '',
        string $folder = '',
    ): array {
        $offset = max(0, ($page - 1) * $perPage);

        return $this->repository->findByFilters(
            $sort,
            self::TYPE_MAP[$type] ?? '',
            $category,
            $tag,
            $this->providersFor($source),
            $folder,
            $perPage,
            $offset,
        );
    }

    /**
     * Count assets with filtering (for pagination) — the same dimensions,
     * in the same order, as listWithSorting().
     */
    public function countWithFilter(
        string $type = '',
        string $category = '',
        string $tag = '',
        string $source = '',
        string $folder = '',
    ): int {
        return $this->repository->countByFilters(
            self::TYPE_MAP[$type] ?? '',
            $category,
            $tag,
            $this->providersFor($source),
            $folder,
        );
    }

    /**
     * Import Cloudinary resources as tracked reference rows.
     *
     * Idempotent on `path`: an already-tracked public_id is counted as
     * skipped and never inserted twice, so re-running an import after a
     * partial one is safe. Every value comes from the API payload (the
     * caller re-resolved the ids through CloudinaryApi::byIds()), never
     * from the browser. No filesystem write, no remote mutation — the row
     * itself is the local reference.
     *
     * @param list<array<string, mixed>> $resources    As returned by CloudinaryApi::byIds()
     * @param list<string>               $requestedIds Ids the admin asked for; any the API did not
     *                                                 return counts as skipped, never imported blind
     *
     * @return array{imported: int, skipped: int}
     */
    public function importCloudinaryResources(array $resources, string $actor, array $requestedIds = []): array
    {
        $imported = 0;
        $skipped = 0;
        $returned = [];

        foreach ($resources as $resource) {
            $publicId = is_string($resource['public_id'] ?? null) ? $resource['public_id'] : '';
            if ('' === $publicId) {
                $skipped++;
                continue;
            }
            $returned[] = $publicId;

            if (null !== $this->repository->findByPath($publicId)) {
                $skipped++;
                continue;
            }

            $this->repository->insert(self::importedAsset($resource, $publicId, $actor));
            $imported++;
        }

        $missing = array_diff($requestedIds, $returned);

        return ['imported' => $imported, 'skipped' => $skipped + count($missing)];
    }

    /**
     * Build the row for one API resource. Keys are read defensively: an
     * unexpected payload yields a plain row, never a PHP warning inside an
     * admin action.
     *
     * @param array<string, mixed> $resource
     */
    private static function importedAsset(array $resource, string $publicId, string $actor): MediaAsset
    {
        $format = is_string($resource['format'] ?? null) ? $resource['format'] : '';
        $resourceType = is_string($resource['resource_type'] ?? null) ? $resource['resource_type'] : 'image';
        $folder = is_string($resource['folder'] ?? null) ? $resource['folder'] : dirname($publicId);
        $filename = is_string($resource['filename'] ?? null) && '' !== $resource['filename']
            ? $resource['filename']
            : basename($publicId);

        return MediaAsset::create(
            filename: $publicId,
            originalName: '' !== $format ? $filename . '.' . $format : basename($publicId),
            path: $publicId,
            size: is_numeric($resource['bytes'] ?? null) ? (int) $resource['bytes'] : 0,
            mimeType: 'image' === $resourceType && '' !== $format
                ? 'image/' . $format
                : 'application/octet-stream',
            uploadedBy: $actor,
            provider: 'cloudinary',
            folder: self::normalizeFolder($folder),
        );
    }

    /**
     * Move an asset to a folder. Normalised the way tags are: trimmed,
     * no leading or trailing slash, '' for the root.
     */
    public function updateFolder(string $id, string $folder): bool
    {
        $asset = $this->repository->findById($id);
        if (null === $asset) {
            return false;
        }

        return $this->repository->updateFolder($id, self::normalizeFolder($folder));
    }

    /**
     * @return list<string>
     */
    public function getFolders(string $source = ''): array
    {
        $folders = $this->repository->getAllFolders($this->providersFor($source));

        if (!self::registryApplies($source)) {
            return $folders;
        }

        // An empty folder is still a folder: a registered path that no file
        // names yet must still be offered, or "create a folder, then upload
        // into it" is impossible in that order.
        $folders = array_values(array_unique([...$folders, ...$this->folders->all()]));
        sort($folders);

        return $folders;
    }

    /**
     * Folder counts constrained by the active non-folder filters. The
     * current folder itself is omitted so sibling folders stay available.
     *
     * @return array<string, int> folder path => asset count
     */
    public function folderCounts(string $type = '', string $category = '', string $tag = '', string $source = ''): array
    {
        $counts = $this->repository->folderCounts(
            self::TYPE_MAP[$type] ?? '',
            $category,
            $tag,
            $this->providersFor($source),
        );

        // The overview is where an empty folder becomes visible at all, so the
        // registry is merged here rather than in the template: a tile whose
        // count nobody computed is a tile somebody will render as "0 assets".
        return self::registryApplies($source) ? $this->folders->countsWithRegistry($counts) : $counts;
    }

    /**
     * Whether the registry is in scope for a source filter.
     *
     * The registry tracks LOCAL folders only — a Cloudinary row's `folder` is
     * provenance written from the API, never registered. So under the
     * "Cloudinary" pill the registry contributes nothing, and offering local
     * empty folders there would list folders the view cannot possibly show.
     */
    private static function registryApplies(string $source): bool
    {
        return 'cloudinary' !== $source;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function trackedPaths(array $paths): array
    {
        return $this->repository->findTrackedPaths($paths);
    }

    /**
     * Resolve a source pill to concrete provider names for the IN clause.
     * '' (no filter) returns an empty list — "no constraint", not
     * "match nothing" — and the legacy empty provider is folded into
     * whichever side the factory default resolves to.
     *
     * @return list<string>
     */
    private function providersFor(string $source): array
    {
        $default = $this->storage->getName();

        return match ($source) {
            'local' => 'local' === $default ? ['local', ''] : ['local'],
            'cloudinary' => 'cloudinary' === $default ? ['cloudinary', ''] : ['cloudinary'],
            default => [],
        };
    }

    /**
     * Normalise a folder path: trimmed, no leading/trailing slash, no
     * empty or dot segments ('..' and '.' segments collapse a path —
     * refused outright, which reads as "root" rather than as a traversal).
     */
    public static function normalizeFolder(string $folder): string
    {
        $folder = trim($folder, " \t\n\r\0\x0B/");
        if ('' === $folder) {
            return '';
        }

        foreach (explode('/', $folder) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return '';
            }
        }

        return $folder;
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        return $this->repository->getAllCategories();
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->repository->getAllTags();
    }

    /**
     * @param list<string> $tags
     */
    public function updateCategoryAndTags(string $id, string $category, array $tags): bool
    {
        $asset = $this->repository->findById($id);
        if (null === $asset) {
            return false;
        }

        // Update category directly.
        $this->repository->updateCategory($id, $category);

        // Update tags.
        $this->repository->setTagsForAsset($id, $tags);

        return true;
    }

    /**
     * Remove the copy the provider owns, and never let that take the admin
     * action down: a `provider = 'cloudinary'` row in an environment without
     * credentials constructs a provider that throws, so the remote file
     * stays while the tracked row goes. With credentials present — the
     * production case — the remote delete runs normally.
     */
    private function deleteRemote(MediaAsset $asset): void
    {
        try {
            $storage = self::resolveProvider($asset->provider(), $this->storage);
            $storage->delete($asset->path());
        } catch (\RuntimeException) {
            // Deliberately swallowed: the row is deleted regardless, which
            // is the only part of this operation the caller can act on.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function isImageMimeType(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    /**
     * Resolve the storage provider for a given provider name.
     *
     * @param string|null            $provider Stored provider name ('local', 'cloudinary', '' for legacy)
     * @param MediaProviderInterface $default  Default provider from factory
     */
    public static function resolveProvider(?string $provider, MediaProviderInterface $default): MediaProviderInterface
    {
        return match ($provider) {
            'local' => new LocalStorageProviderAdapter(new LocalStorageProvider()),
            'cloudinary' => new CloudinaryProvider(),
            default => $default,
        };
    }

    /**
     * Filter type → mime type prefix mapping.
     *
     * @var array<string, string>
     */
    private const array TYPE_MAP = [
        'image'     => 'image/',
        'document'  => 'application/',
        'text'      => 'text/',
    ];
}
