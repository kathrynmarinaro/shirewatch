<?php
/* GET /api/export.php        everything, as a .zip
 * GET /api/export.php?f=json just the data, as .json
 *
 * ---------------------------------------------------------------------------
 * THIS IS A BACKUP, NOT A CONVENIENCE.
 * ---------------------------------------------------------------------------
 *
 * The full-resolution originals under public/uploads/original/ are kept
 * deliberately and are regenerated from nothing (CLAUDE.md). They ARE the
 * record of what the house looked like on the day you photographed it, which
 * is the entire point of the app. A database dump without them is half a
 * backup, so the zip carries the files too.
 *
 * ---------------------------------------------------------------------------
 * STREAMED FROM A TEMP FILE, NOT BUILT IN MEMORY.
 * ---------------------------------------------------------------------------
 *
 * A few hundred photos is easily a gigabyte, and ZipArchive writing to a real
 * file keeps that on disk rather than in PHP's memory_limit. The file is
 * unlinked on shutdown so a client that disconnects mid-download does not
 * leave it behind.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/issues.php';
require_once __DIR__ . '/../../lib/tasks.php';
require_once __DIR__ . '/../../lib/records.php';

require_login_api();
require_method('GET');

$stamp = date('Y-m-d');
$data  = export_payload();

if (($_GET['f'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . export_slug() . '-' . $stamp . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!class_exists('ZipArchive')) {
    /* Say which one is missing and what still works, rather than failing with
     * a generic error on the one feature that exists to save you. */
    json_error('zip_unavailable', 500,
        'The zip extension is not installed. Use ?f=json for the data on its own.');
}

$tmp = tempnam(sys_get_temp_dir(), 'sw-export-');
if ($tmp === false) {
    json_error('export_failed', 500);
}
register_shutdown_function(static function () use ($tmp): void {
    @unlink($tmp);
});

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    json_error('export_failed', 500);
}

$zip->addFromString(
    'data.json',
    (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

/* A CSV of the money beside the JSON. The JSON is the real backup; this is the
 * file you can open without a programmer when somebody asks what the roof cost. */
$zip->addFromString('service-records.csv', export_records_csv($data['service_records']));

$zip->addFromString('README.txt', export_readme($stamp, count($data['media'])));

$missing = 0;
foreach ($data['media'] as $item) {
    foreach (array('original_path', 'thumb_path', 'detail_path') as $key) {
        $rel = $item[$key] ?? null;
        if ($rel === null) {
            continue;
        }
        $abs = imageproc_resolve_upload($rel);
        if ($abs === null) {
            $missing++;
            continue;
        }
        $zip->addFile($abs, $rel);
    }
}

if ($missing > 0) {
    /* Named in the archive rather than logged and forgotten. A backup that is
     * quietly short of files is worse than one that says so. */
    $zip->addFromString('MISSING-FILES.txt',
        $missing . " file(s) referenced by the database were not on disk.\n"
        . "They are listed in data.json but their bytes are gone.\n");
}

$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . export_slug() . '-' . $stamp . '.zip"');
header('Content-Length: ' . (string) filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
exit;

/* ------------------------------------------------------------------ helpers */

/** A filesystem-safe form of the app's name, for the download filename. */
function export_slug(): string
{
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', app_name()) ?? '');
    $slug = trim($slug, '-');
    return $slug === '' ? 'shirewatch' : $slug;
}

/**
 * Every table, as plain arrays.
 *
 * Read through the repo functions where they exist, so the export sees the
 * same shapes the app does — an export built from its own queries drifts from
 * the app the first time a column is added.
 */
function export_payload(): array
{
    return array(
        'app'          => app_name(),
        'exported_at'  => date('c'),
        'schema_note'  => 'Dates are Y-m-d. NULL means unset, never zero — see CLAUDE.md.',
        'properties'   => q('SELECT * FROM properties')->fetchAll(),
        'tags'         => q('SELECT * FROM tags ORDER BY kind, sort_order, name')->fetchAll(),
        'issues'       => q('SELECT * FROM issues ORDER BY id')->fetchAll(),
        'issue_updates'=> q('SELECT * FROM issue_updates ORDER BY issue_id, noted_on, id')->fetchAll(),
        'issue_tags'   => q('SELECT * FROM issue_tags')->fetchAll(),
        'tasks'        => q('SELECT * FROM maintenance_tasks ORDER BY id')->fetchAll(),
        'task_completions' => q('SELECT * FROM task_completions ORDER BY task_id, completed_on')->fetchAll(),
        'task_tags'    => q('SELECT * FROM task_tags')->fetchAll(),
        'vendors'      => q('SELECT * FROM vendors ORDER BY name')->fetchAll(),
        'vendor_tags'  => q('SELECT * FROM vendor_tags')->fetchAll(),
        'service_records' => q('SELECT * FROM service_records ORDER BY performed_on DESC, id DESC')->fetchAll(),
        'record_tags'  => q('SELECT * FROM record_tags')->fetchAll(),
        'media'        => q('SELECT * FROM media ORDER BY id')->fetchAll(),
    );
}

/** The service records as a spreadsheet-openable CSV. */
function export_records_csv(array $records): string
{
    $out = fopen('php://temp', 'r+');
    fputcsv($out, array('Date', 'What', 'Vendor', 'Cost', 'Rating', 'Notes'));

    foreach ($records as $row) {
        fputcsv($out, array(
            $row['performed_on'],
            $row['title'],
            $row['vendor_name'] ?? '',
            /* An empty cell, NOT a zero. "Not recorded" is not "free", and a
             * spreadsheet full of zeros would sum to a number that is wrong. */
            $row['cost'] ?? '',
            $row['rating'] ?? '',
            $row['description'] ?? '',
        ));
    }

    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);

    /* A BOM, so Excel opens UTF-8 correctly rather than mangling every
     * accented vendor name. */
    return "\xEF\xBB\xBF" . $csv;
}

function export_readme(string $stamp, int $mediaCount): string
{
    return "Shirewatch export — " . $stamp . "\n"
        . str_repeat('=', 32) . "\n\n"
        . "data.json             every table, as plain JSON\n"
        . "service-records.csv   the money, openable in a spreadsheet\n"
        . "uploads/              " . $mediaCount . " media rows' files, at the paths data.json refers to\n\n"
        . "THE PHOTOS ARE THE PART THAT CANNOT BE REGENERATED. The database can\n"
        . "be retyped; the full-resolution originals under uploads/original/ are\n"
        . "the record of what the house looked like on the day, and nothing else\n"
        . "has a copy. Keep this archive somewhere other than the server.\n\n"
        . "To restore: load schema.sql into a fresh database, insert the rows\n"
        . "from data.json, and copy uploads/ back into public/.\n";
}
