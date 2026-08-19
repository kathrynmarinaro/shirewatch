<?php
/* POST /api/issue-update.php   {issue_id, note?, severity?, noted_on?}
 * → {id, issue: {...}}
 *
 * Adds one entry to the timeline — the check-in. The returned `id` is what
 * api/upload.php attaches photos to, with owner_type 'issue_update'.
 *
 * ---------------------------------------------------------------------------
 * THIS NEVER EDITS A PREVIOUS ENTRY.
 * ---------------------------------------------------------------------------
 *
 * There is no issue-update-edit.php and there should not be one. The premise
 * of the app is that nothing in a timeline is overwritten, and an edit path
 * would eventually be used to "correct" a measurement — but the correction IS
 * the observation, and losing the first reading loses the progression that the
 * second reading is only meaningful against.
 *
 * A mistaken entry is deleted whole, by api/issue-update-delete.php.
 *
 * The issue comes back in the response because adding an entry rewrites the
 * issue's severity, last_checked_on and next_check_on — so the screen would
 * otherwise be showing three stale values the moment it succeeded.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/issues.php';

require_login_api();
require_same_origin();
require_method('POST');

$body    = json_body();
$issueId = (int) ($body['issue_id'] ?? 0);

if ($issueId <= 0) {
    json_error('bad_id', 400);
}

try {
    $updateId = issue_add_update($issueId, $body);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 404);
}

json_out(array('id' => $updateId, 'issue' => issue_get($issueId)));
