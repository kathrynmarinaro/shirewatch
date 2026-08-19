<?php
/* POST /api/issue-status.php   {id, status, record_id?}  ->  the issue
 *
 * status is watching | active | resolved | dismissed.
 *
 * `record_id` links the service record that FIXED it, and is ALWAYS OPTIONAL:
 * fix something yourself and the issue resolves with nothing attached
 * (CLAUDE.md). It is ignored on any status other than 'resolved', because
 * "this is what resolved it" has no meaning on an issue that is not resolved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/issues.php';

require_login_api();
require_same_origin();
require_method('POST');

$body     = json_body();
$id       = (int) ($body['id'] ?? 0);
$status   = (string) ($body['status'] ?? '');
$recordId = isset($body['record_id']) ? (int) $body['record_id'] : null;

if ($id <= 0) {
    json_error('bad_id', 400);
}
if (!in_array($status, issue_statuses(), true)) {
    json_error('bad_status', 400);
}

if (!issue_set_status($id, $status, $recordId)) {
    json_error('not_found', 404);
}

json_out(issue_get($id));
