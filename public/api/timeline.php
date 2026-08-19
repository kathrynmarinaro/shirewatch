<?php
/* GET /api/timeline.php?after=YYYY-MM-DD&key=task:12&limit=40
 *
 * → { "rows": [...], "cursor": {"date": "...", "key": "task:19"}, "done": false }
 *
 * One page of the dashboard's forward stream. The browser calls this as you
 * scroll, passing back the previous page's cursor.
 *
 * KEYSET, NOT OFFSET. `after` plus `key` identify the last row of the previous
 * page exactly — several tasks can share one date, so the date alone cannot
 * mark a page boundary. With OFFSET, anything inserted mid-scroll silently
 * repeats or skips a row, and on this screen the row being inserted is usually
 * the one you just completed.
 *
 * A GET, and read-only: it is the only endpoint in the app that a refresh
 * should be able to repeat harmlessly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/dashboard.php';

require_login_api();
require_method('GET');

$after = (string) ($_GET['after'] ?? '');
if (sw_parse_date($after) === null) {
    $after = sw_today();
}

$key   = (string) ($_GET['key'] ?? '');
$limit = (int) ($_GET['limit'] ?? 0);

json_out(dashboard_timeline($after, $key === '' ? null : $key, $limit > 0 ? min($limit, 100) : null));
