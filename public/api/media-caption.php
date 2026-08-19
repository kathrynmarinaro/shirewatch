<?php
/* POST /api/media-caption.php   {id, caption}  ->  {ok: true, caption: "…"}
 *
 * An empty caption stores NULL rather than an empty string, so "no caption"
 * is one value everywhere and a template can test it with one condition.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/media.php';

require_login_api();
require_same_origin();
require_method('POST');

$body    = json_body();
$id      = (int) ($body['id'] ?? 0);
$caption = trim((string) ($body['caption'] ?? ''));

if ($id <= 0) {
    json_error('bad_id', 400);
}
if (q('SELECT 1 FROM media WHERE id = ?', array($id))->fetch() === false) {
    json_error('not_found', 404);
}

/* Truncated to the column width rather than refused. A caption is a note to
 * yourself; losing the tail of a long one is a smaller surprise than losing
 * the whole edit. */
$caption = $caption === '' ? null : mb_substr($caption, 0, 255, 'UTF-8');

q('UPDATE media SET caption = ? WHERE id = ?', array($caption, $id));

json_out(array('ok' => true, 'caption' => $caption));
