<?php
/* POST /api/issue-save.php
 *
 *   {title, description?, noticed_on?, severity?, check_interval_days?, tag_ids?}
 *   plus {id} to edit an existing one.
 *
 * → the saved issue, decorated with its tags.
 *
 * CREATE AND EDIT SHARE ONE ENDPOINT because they share one form. Two
 * endpoints would mean two places to add a field to, and the second one always
 * gets forgotten.
 *
 * The capture flow calls this FIRST and attaches photos afterwards against the
 * returned id — api/upload.php requires the owner row to exist.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/issues.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();
$id   = (int) ($body['id'] ?? 0);

try {
    if ($id > 0) {
        if (!issue_save($id, $body)) {
            json_error('not_found', 404);
        }
    } else {
        $id = issue_create($body);
    }
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 400);
}

$issue = issue_get($id);
$decorated = issues_decorate(array($issue));

json_out($decorated[0]);
