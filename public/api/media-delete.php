<?php
/* POST /api/media-delete.php   {id}  ->  {ok: true}
 *
 * Removes the row and every file behind it.
 *
 * NO UNDO, AND DELIBERATELY NO SOFT DELETE. The files are the point of this
 * app — a photo of a crack exists to be put beside a later photo of the same
 * crack — so a deleted-but-retained row would either keep the disk space it
 * was deleted to reclaim, or keep a row pointing at files that are gone.
 * Neither is better than being asked first, which the gallery does.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/media.php';

require_login_api();
require_same_origin();
require_method('POST');

$id = (int) (json_body()['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 400);
}

if (!media_delete($id)) {
    json_error('not_found', 404);
}

json_out(array('ok' => true));
