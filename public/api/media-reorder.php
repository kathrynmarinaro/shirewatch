<?php
/* POST /api/media-reorder.php   {ids: [7, 3, 9]}  ->  {ok: true}
 *
 * The order photos appear in a gallery. Spaced by tens like the tag reorder,
 * so a later insertion has somewhere to land without renumbering everything.
 *
 * Order matters on a service record — the invoice first, then the damage — and
 * on an issue's opening photo set, where the widest shot wants to be the one
 * the card shows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/media.php';

require_login_api();
require_same_origin();
require_method('POST');

$ids = json_body()['ids'] ?? null;
if (!is_array($ids)) {
    json_error('bad_ids', 400);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $n = 10;
    foreach ($ids as $raw) {
        $id = (int) $raw;
        if ($id <= 0) {
            continue;
        }
        q('UPDATE media SET sort_order = ? WHERE id = ?', array($n, $id));
        $n += 10;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_out(array('ok' => true));
