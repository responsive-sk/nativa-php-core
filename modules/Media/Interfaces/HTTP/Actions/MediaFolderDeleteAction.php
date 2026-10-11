<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\UnitOfWork;
use Modules\Media\Interfaces\HTTP\MediaFolderResponses;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Delete a media folder — POST /mark/media/folders/delete.
 *
 * Deleting a folder is two different operations wearing one name, and the
 * request has to say which: without `cascade` a folder that holds files is
 * refused, naming how many; with it, those files go through
 * `MediaService::batchDelete()` — the same provider-aware path the grid's own
 * bulk delete uses, so a Cloudinary row is removed at its provider and not
 * merely forgotten here.
 *
 * Both halves run inside one `UnitOfWork` on the media connection, so a file
 * that fails to delete does not leave the registry claiming the folder is
 * gone.
 */
#[Route(path: '/mark/media/folders/delete', method: 'POST', name: 'media.folder-delete')]
final class MediaFolderDeleteAction
{
    public function handle(Request $request): Response
    {
        $rawPath = $request->body('path');
        $path = is_string($rawPath) ? trim($rawPath) : '';
        // The checkbox posts '1' when ticked and nothing at all when not. A
        // strict comparison to that one value is the whole toggle — no cast,
        // so an array or an unexpected scalar simply reads as "not asked for".
        $cascadeRaw = $request->body('cascade');
        $cascade = is_string($cascadeRaw) && '1' === $cascadeRaw;

        $pdo = DatabaseConnectionManager::instance()->connection('media');
        $outcome = (new UnitOfWork($pdo))->transaction(
            fn (): array => $this->deleteWithCascade($path, $cascade),
        );

        if (!$outcome['ok']) {
            return MediaFolderResponses::refusal($request, $outcome['error'], $path);
        }

        CacheInvalidator::flush();
        AuditServices::log(
            'media.folder-delete',
            'media',
            sprintf(
                'Deleted folder: %s (%d file(s) deleted, %d folder row(s)%s)',
                $path,
                $outcome['deleted'],
                $outcome['folders'],
                0 < $outcome['skipped'] ? sprintf(', skipped %d imported', $outcome['skipped']) : '',
            ),
            $request->getClientIp(),
        );

        return MediaFolderResponses::success(
            $request,
            sprintf('Folder %s deleted (%d file(s)).', $path, $outcome['deleted']),
        );
    }

    /**
     * The registry row(s), then the files — in that order inside the
     * transaction, so a refusal has written nothing by the time it is returned.
     *
     * @return array{ok: bool, error: string, deleted: int, folders: int, skipped: int}
     */
    private function deleteWithCascade(string $path, bool $cascade): array
    {
        $outcome = MediaServices::folders()->delete($path, $cascade);

        if (!$outcome['ok']) {
            return ['ok' => false, 'error' => $outcome['error'], 'deleted' => 0, 'folders' => 0, 'skipped' => 0];
        }

        $deleted = [] !== $outcome['ids'] ? MediaServices::media()->batchDelete($outcome['ids']) : [];

        return [
            'ok'      => true,
            'error'   => '',
            'deleted' => count($deleted),
            'folders' => $outcome['folders'],
            'skipped' => $outcome['skipped'],
        ];
    }
}
