<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Interfaces\HTTP\MediaFolderResponses;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Rename a media folder — POST /mark/media/folders/rename.
 *
 * Moves the folder AND its subtree, in the registry and on every local asset,
 * inside one transaction. The report names the imported rows it did not touch:
 * a Cloudinary row's `folder` is provenance written from the API at import
 * time, so rewriting it would make this database disagree with the cloud while
 * the panel claimed the file moved.
 *
 * The audit detail names BOTH paths and the counts, because "renamed a folder"
 * is not a record anyone can act on later.
 */
#[Route(path: '/mark/media/folders/rename', method: 'POST', name: 'media.folder-rename')]
final class MediaFolderRenameAction
{
    public function handle(Request $request): Response
    {
        $fromRaw = $request->body('from');
        $toRaw = $request->body('to');
        $from = is_string($fromRaw) ? trim($fromRaw) : '';
        $to = is_string($toRaw) ? trim($toRaw) : '';

        $result = MediaServices::folders()->rename($from, $to);

        if (!$result['ok']) {
            return MediaFolderResponses::refusal($request, $result['error'], $from);
        }

        CacheInvalidator::flush();
        AuditServices::log(
            'media.folder-rename',
            'media',
            sprintf(
                'Renamed folder: %s -> %s (%d folder(s), %d file(s)%s)',
                $from,
                $to,
                $result['folders'],
                $result['assets'],
                0 < $result['skipped'] ? sprintf(', skipped %d imported', $result['skipped']) : '',
            ),
            $request->getClientIp(),
        );

        return MediaFolderResponses::success(
            $request,
            sprintf('Renamed to %s — %d folder(s), %d file(s).', $to, $result['folders'], $result['assets']),
            $to,
        );
    }
}
