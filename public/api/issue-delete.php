<?php
/* POST /api/issue-delete.php   {id}  ->  {ok: true}
 *
 * Deletes the issue, its whole timeline, its tag links and every file behind
 * its photos.
 *
 * NO UNDO. Dismissing is what you want for "it turned out to be nothing" —
 * that keeps the record and the photos, which is usually the point. Deleting
 * is for the issue you logged twice by accident, and the screen says so before
 * it asks.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/issues.php';

require_login_api();
require_same_origin();
require_method('POST');

$id = (int) (json_body()['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 400);
}

if (!issue_delete($id)) {
    json_error('not_found', 404);
}

json_out(array('ok' => true));
