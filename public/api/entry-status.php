<?php
/* POST /api/entry-status.php   {id, status, update_id?}  ->  the entry
 *
 * status is watching | active | resolved | dismissed.
 *
 * `update_id` names the update on the entry's own timeline that FIXED it —
 * the service visit, usually — and is ALWAYS OPTIONAL:
 * fix something yourself and the entry resolves with nothing attached
 * (CLAUDE.md). It is ignored on any status other than 'resolved', because
 * "this is what resolved it" has no meaning on an entry that is not resolved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/log.php';

require_login_api();
require_same_origin();
require_method('POST');

$body     = json_body();
$id       = (int) ($body['id'] ?? 0);
$status   = (string) ($body['status'] ?? '');
$updateId = isset($body['update_id']) ? (int) $body['update_id'] : null;

if ($id <= 0) {
    json_error('bad_id', 400);
}
if (!in_array($status, entry_statuses(), true)) {
    json_error('bad_status', 400);
}

if (!entry_set_status($id, $status, $updateId)) {
    json_error('not_found', 404);
}

json_out(entry_get($id));
