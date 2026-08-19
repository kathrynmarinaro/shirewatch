<?php
/* POST /api/worker.php   ->  { "processed": 3, "remaining": 2, "results": [...] }
 *
 * Drains a few queued photos. The browser calls this in a loop after an upload
 * until `remaining` reaches zero.
 *
 * ---------------------------------------------------------------------------
 * BOUNDED PER CALL, ON PURPOSE.
 * ---------------------------------------------------------------------------
 *
 * cfg('media.batch') items, not "everything pending". A forty-photo batch in
 * one request is the max_execution_time problem that api/upload.php exists to
 * avoid, reintroduced one layer down. Several short calls also give the
 * progress pill something to count.
 *
 * SAFE TO RUN ALONGSIDE THE CRON. Claiming is a single conditional UPDATE
 * whose rowCount() decides the winner (lib/media.php), so the two workers just
 * take different rows.
 *
 * NEVER RETURNS AN ERROR FOR A FAILED PHOTO. A photo that cannot be processed
 * is a row with a reason on it and a placeholder in the gallery — the drain
 * loop's job is to finish, not to litigate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/media.php';

require_login_api();
require_same_origin();
require_method('POST');

$batch   = max(1, (int) cfg('media.batch', 5));
$results = array();

for ($i = 0; $i < $batch; $i++) {
    $status = media_process_next();
    if ($status === null) {
        break;                       // queue drained
    }
    $results[] = $status;
}

json_out(array(
    'processed' => count($results),
    'remaining' => media_pending_count(),
    'results'   => $results,
));
