<?php
/* Image validation and derivative generation.
 *
 * Two jobs:
 *   1. Decide whether an uploaded blob is really an image we can process,
 *      based ONLY on its bytes — never on the client-supplied name or MIME.
 *   2. Turn an original into the two WebP copies the app serves, honouring
 *      EXIF orientation and never upscaling.
 *
 * Imagick is preferred (it is the only path that can read HEIC and the only
 * one with a decent resampling filter); GD is a fallback for the formats it
 * understands so the app still works on a host without Imagick.
 */

declare(strict_types=1);

/**
 * Hard ceiling per uploaded file. Config-driven here, unlike the Gallery's
 * hardcoded constant, because this app accepts DOCUMENTS as well as photos and
 * a scanned warranty booklet is a different size of thing from a snapshot.
 */
function imageproc_max_bytes(): int
{
    return max(1, (int) cfg('media.max_upload_mb', 25)) * 1024 * 1024;
}

/* Sub-directories of public/uploads/ we are allowed to write into.
 *
 * 'docs' is new here and is NEVER a destination for imageproc_derive() — a PDF
 * has nothing to resize. It is in this whitelist only so that
 * imageproc_upload_path() will build a path into it; see lib/media.php. */
const IMAGEPROC_DIRS = array('original', 'thumb', 'detail', 'docs');

/* ------------------------------------------------------------ detection */

/**
 * Content types we accept => the canonical extension we store them under.
 * The extension is ours, derived from the sniffed type, never from the name.
 */
function imageproc_accepted_types(): array
{
    return array(
        'image/jpeg'          => 'jpg',
        'image/png'           => 'png',
        'image/webp'          => 'webp',
        'image/gif'           => 'gif',
        'image/heic'          => 'heic',
        'image/heif'          => 'heic',
        'image/heic-sequence' => 'heic',
        'image/heif-sequence' => 'heic',
        'image/avif'          => 'heic',
    );
}

/**
 * ISO-BMFF brands that mean "this is a HEIF-family still image".
 * Read from the `ftyp` box rather than trusting finfo, which is missing or
 * out of date on plenty of shared hosts.
 */
function imageproc_heif_brand(string $path): ?string
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $head = fread($fh, 32);
    fclose($fh);

    if ($head === false || strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
        return null;
    }

    $major  = strtolower(substr($head, 8, 4));
    $brands = array(
        'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs',
        'mif1', 'msf1', 'avif', 'avis',
    );

    return in_array($major, $brands, true) ? $major : null;
}

/**
 * Identify a file by its bytes.
 *
 * Returns array{mime: string, ext: string, family: 'raster'|'heif'} or null
 * when the file is not an image we accept. `family` tells the resize path
 * whether GD could ever handle it (it cannot handle heif).
 */
function imageproc_sniff(string $path): ?array
{
    if (!is_file($path) || filesize($path) === 0) {
        return null;
    }

    $accepted = imageproc_accepted_types();

    $mime = null;
    if (class_exists('finfo')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);
        if (is_string($detected) && $detected !== '') {
            $mime = strtolower($detected);
        }
    }

    /* HEIF first: getimagesize() cannot see it and older finfo builds
     * report it as application/octet-stream or video/quicktime. */
    $brand = imageproc_heif_brand($path);
    if ($brand !== null) {
        return array(
            'mime'   => ($brand === 'avif' || $brand === 'avis') ? 'image/avif' : 'image/heic',
            'ext'    => 'heic',
            'family' => 'heif',
        );
    }

    /* Everything else must survive getimagesize(), which actually parses the
     * header — a .php renamed to .jpg never gets this far. */
    $info = @getimagesize($path);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return null;
    }

    $byGd = array(
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_GIF  => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    );
    $type = $info[2] ?? 0;
    if (!isset($byGd[$type])) {
        return null;
    }

    $sniffed = $byGd[$type];
    /* If finfo disagrees with getimagesize, distrust the file. */
    if ($mime !== null && isset($accepted[$mime]) && $mime !== $sniffed) {
        return null;
    }

    return array(
        'mime'   => $sniffed,
        'ext'    => $accepted[$sniffed],
        'family' => 'raster',
    );
}

/** True when this build of Imagick can actually decode HEIF, not just list it. */
function imageproc_heif_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!class_exists('Imagick')) {
        return $ok = false;
    }
    /* queryFormats() reports registered coders, which exist even with no
     * delegate behind them — so it is necessary but not sufficient. */
    $formats = @Imagick::queryFormats('HEI*');
    return $ok = (is_array($formats) && $formats !== array());
}

/* ------------------------------------------------------------ safe paths */

/** A generated, name-independent basename. */
function imageproc_new_slug(): string
{
    return bin2hex(random_bytes(8));
}

function imageproc_is_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $slug);
}

/**
 * Build an absolute path inside public/uploads/<dir>/.
 * Both components are whitelisted, so nothing a caller passes can walk out
 * of the uploads tree even if it came from the database.
 */
function imageproc_upload_path(string $dir, string $slug, string $ext): string
{
    if (!in_array($dir, IMAGEPROC_DIRS, true)) {
        throw new RuntimeException('bad upload directory: ' . $dir);
    }
    if (!imageproc_is_slug($slug)) {
        throw new RuntimeException('bad slug');
    }
    if (!preg_match('/^[a-z0-9]{2,5}$/', $ext)) {
        throw new RuntimeException('bad extension');
    }
    return UPLOAD_DIR . '/' . $dir . '/' . $slug . '.' . $ext;
}

/** The public-relative form of an upload path, e.g. "uploads/thumb/ab.webp". */
function imageproc_relative_path(string $dir, string $slug, string $ext): string
{
    imageproc_upload_path($dir, $slug, $ext);      // reuses the validation
    return 'uploads/' . $dir . '/' . $slug . '.' . $ext;
}

/**
 * Resolve a stored relative path to a real file inside uploads/, or null.
 * Anything that escapes the uploads directory is refused, so a poisoned
 * original_path column cannot make the worker read /etc/passwd.
 */
function imageproc_resolve_upload(?string $rel): ?string
{
    if ($rel === null || $rel === '') {
        return null;
    }
    if (str_contains($rel, "\0")) {
        return null;
    }

    $full = realpath(PUBLIC_DIR . '/' . $rel);
    $base = realpath(UPLOAD_DIR);
    if ($full === false || $base === false) {
        return null;
    }
    if (!str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
        error_log('imageproc: refusing path outside uploads: ' . $rel);
        return null;
    }
    return is_file($full) ? $full : null;
}

/** mkdir -p for one of our upload sub-directories. */
function imageproc_ensure_dir(string $dir): string
{
    if (!in_array($dir, IMAGEPROC_DIRS, true)) {
        throw new RuntimeException('bad upload directory: ' . $dir);
    }
    $path = UPLOAD_DIR . '/' . $dir;
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('cannot create ' . $path);
    }
    if (!is_writable($path)) {
        throw new RuntimeException('not writable: ' . $path);
    }
    return $path;
}

/* ------------------------------------------------------------ derivatives */

/** Longest-edge box fit that never upscales. Returns [w, h]. */
function imageproc_fit(int $w, int $h, int $max): array
{
    if ($w <= 0 || $h <= 0) {
        throw new RuntimeException('zero-sized image');
    }
    $longest = max($w, $h);
    if ($longest <= $max) {
        return array($w, $h);
    }
    $scale = $max / $longest;
    return array(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
}

/**
 * Produce the thumb and detail WebP copies for one original.
 *
 * Returns array{thumb_path, detail_path, width, height} with public-relative
 * paths and the DETAIL image's dimensions (what the canvas uses for aspect).
 *
 * Throws on failure, after cleaning up any half-written derivative — a retry
 * must not leave orphans behind.
 */
function imageproc_derive(string $srcAbs, string $slug, array $sniff): array
{
    $thumbMax = max(32, (int) cfg('media.thumb_max', 400));
    $detailMax= max($thumbMax, (int) cfg('media.detail_max', 1600));
    $quality  = min(100, max(1, (int) cfg('media.webp_quality', 82)));

    imageproc_ensure_dir('thumb');
    imageproc_ensure_dir('detail');

    $thumbAbs = imageproc_upload_path('thumb', $slug, 'webp');
    $detailAbs= imageproc_upload_path('detail', $slug, 'webp');

    $written = array();
    try {
        if (class_exists('Imagick')) {
            $dims = imageproc_derive_imagick(
                $srcAbs, $thumbAbs, $detailAbs, $thumbMax, $detailMax, $quality, $written
            );
        } elseif ($sniff['family'] === 'raster') {
            $dims = imageproc_derive_gd(
                $srcAbs, $thumbAbs, $detailAbs, $thumbMax, $detailMax, $quality, $written
            );
        } else {
            throw new RuntimeException('heif_decode_unavailable');
        }
    } catch (Throwable $e) {
        foreach ($written as $path) {
            @unlink($path);
        }
        /* Imagick could not read a HEIF file: say so plainly rather than
         * leaving a generic decode error the user cannot act on. */
        if ($sniff['family'] === 'heif' && !imageproc_heif_supported()) {
            throw new RuntimeException('heif_decode_unavailable', 0, $e);
        }
        throw $e;
    }

    return array(
        'thumb_path'  => imageproc_relative_path('thumb', $slug, 'webp'),
        'detail_path' => imageproc_relative_path('detail', $slug, 'webp'),
        'width'       => $dims[0],
        'height'      => $dims[1],
    );
}

/** @return array{0:int,1:int} detail dimensions */
function imageproc_derive_imagick(
    string $srcAbs,
    string $thumbAbs,
    string $detailAbs,
    int $thumbMax,
    int $detailMax,
    int $quality,
    array &$written
): array {
    $im = new Imagick();
    try {
        /* Cap the decoder so a decompression bomb cannot exhaust memory. */
        $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        $im->setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);

        $im->readImage($srcAbs);

        /* Animated GIF / HEIC burst: keep the first frame only. */
        if ($im->getNumberImages() > 1) {
            $im->setIteratorIndex(0);
            $frame = $im->getImage();
            $im->clear();
            $im = $frame;
        }

        imageproc_imagick_orient($im);

        if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        /* Flatten onto white so a transparent PNG does not become a black
         * rectangle once WebP alpha is dropped downstream. */
        if ($im->getImageAlphaChannel()) {
            $im->setImageBackgroundColor(new ImagickPixel('white'));
            $flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->clear();
            $im = $flat;
        }

        $srcW = $im->getImageWidth();
        $srcH = $im->getImageHeight();
        if ($srcW < 1 || $srcH < 1) {
            throw new RuntimeException('zero-sized image');
        }

        /* EXIF and any embedded profiles are dropped once orientation has
         * been baked in — they are pure liability in a served file. */
        $im->stripImage();
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality($quality);
        $im->setOption('webp:method', '4');

        list($dw, $dh) = imageproc_fit($srcW, $srcH, $detailMax);
        $detail = clone $im;
        $detail->resizeImage($dw, $dh, Imagick::FILTER_LANCZOS, 1);
        $detail->setImagePage(0, 0, 0, 0);
        if (!$detail->writeImage($detailAbs)) {
            throw new RuntimeException('detail write failed');
        }
        $written[] = $detailAbs;
        $detail->clear();

        list($tw, $th) = imageproc_fit($srcW, $srcH, $thumbMax);
        $thumb = clone $im;
        $thumb->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1);
        $thumb->setImagePage(0, 0, 0, 0);
        if (!$thumb->writeImage($thumbAbs)) {
            throw new RuntimeException('thumb write failed');
        }
        $written[] = $thumbAbs;
        $thumb->clear();

        return array($dw, $dh);
    } finally {
        $im->clear();
    }
}

/** Bake EXIF orientation into the pixels. */
function imageproc_imagick_orient(Imagick $im): void
{
    $orientation = Imagick::ORIENTATION_TOPLEFT;
    try {
        $orientation = $im->getImageOrientation();
    } catch (Throwable $e) {
        return;
    }
    if ($orientation === Imagick::ORIENTATION_TOPLEFT || $orientation === Imagick::ORIENTATION_UNDEFINED) {
        return;
    }

    $white = new ImagickPixel('white');
    switch ($orientation) {
        case Imagick::ORIENTATION_TOPRIGHT:    $im->flopImage(); break;
        case Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage($white, 180); break;
        case Imagick::ORIENTATION_BOTTOMLEFT:  $im->flipImage(); break;
        case Imagick::ORIENTATION_LEFTTOP:     $im->flopImage(); $im->rotateImage($white, -90); break;
        case Imagick::ORIENTATION_RIGHTTOP:    $im->rotateImage($white, 90); break;
        case Imagick::ORIENTATION_RIGHTBOTTOM: $im->flopImage(); $im->rotateImage($white, 90); break;
        case Imagick::ORIENTATION_LEFTBOTTOM:  $im->rotateImage($white, -90); break;
    }
    $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
}

/** GD fallback. Cannot read HEIF; callers must not route HEIF here. */
function imageproc_derive_gd(
    string $srcAbs,
    string $thumbAbs,
    string $detailAbs,
    int $thumbMax,
    int $detailMax,
    int $quality,
    array &$written
): array {
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('gd_webp_unavailable');
    }

    $data = file_get_contents($srcAbs);
    if ($data === false) {
        throw new RuntimeException('unreadable original');
    }
    $src = @imagecreatefromstring($data);
    unset($data);
    if ($src === false) {
        throw new RuntimeException('gd decode failed');
    }

    try {
        $src = imageproc_gd_orient($src, $srcAbs);

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $flat = imagecreatetruecolor($srcW, $srcH);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $src, 0, 0, 0, 0, $srcW, $srcH);
        imagedestroy($src);
        $src = $flat;

        foreach (array(array($detailAbs, $detailMax), array($thumbAbs, $thumbMax)) as $job) {
            list($dest, $max) = $job;
            list($w, $h) = imageproc_fit($srcW, $srcH, $max);

            $out = imagescale($src, $w, $h, IMG_BICUBIC_FIXED);
            if ($out === false) {
                throw new RuntimeException('gd resize failed');
            }
            $ok = imagewebp($out, $dest, $quality);
            imagedestroy($out);
            if (!$ok) {
                throw new RuntimeException('gd webp write failed');
            }
            $written[] = $dest;
        }

        return imageproc_fit($srcW, $srcH, $detailMax);
    } finally {
        if ($src instanceof GdImage) {
            imagedestroy($src);
        }
    }
}

function imageproc_gd_orient(GdImage $img, string $srcAbs): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($srcAbs);
    $o    = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    if ($o <= 1 || $o > 8) {
        return $img;
    }

    $rotate = array(3 => 180, 4 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90);
    $flip   = array(2 => IMG_FLIP_HORIZONTAL, 4 => IMG_FLIP_HORIZONTAL,
                    5 => IMG_FLIP_HORIZONTAL, 7 => IMG_FLIP_HORIZONTAL);

    if (isset($rotate[$o])) {
        $rotated = imagerotate($img, $rotate[$o], 0);
        if ($rotated !== false) {
            imagedestroy($img);
            $img = $rotated;
        }
    }
    if (isset($flip[$o])) {
        imageflip($img, $flip[$o]);
    }
    return $img;
}

/* -------------------------------------------------------------------- crop */

/** Smallest crop accepted, as a fraction of the source edge. */
const IMAGEPROC_MIN_CROP = 0.02;

/**
 * Write an EXIF-corrected crop of $srcAbs to a new intermediate file, and
 * return its absolute path. The caller derives from it and then deletes it.
 *
 * The rect is normalised (0..1 fractions of the source), not pixels, because
 * the client draws the crop on the 1600px detail copy while the server may be
 * cutting the full-resolution original — fractions survive that difference,
 * pixel coordinates would silently crop the wrong region.
 *
 * Orientation is applied BEFORE the crop. The user drew the rect on an already-
 * upright image, so cropping a still-rotated source would cut the wrong side of
 * the photo.
 *
 * The intermediate is PNG: it sits between the source and the WebP encoder, and
 * a lossy hop there would show up in the final image for no reason.
 */
function imageproc_crop(string $srcAbs, array $rect): string
{
    $x = (float) $rect['x'];
    $y = (float) $rect['y'];
    $w = (float) $rect['w'];
    $h = (float) $rect['h'];

    if ($w < IMAGEPROC_MIN_CROP || $h < IMAGEPROC_MIN_CROP) {
        throw new RuntimeException('crop_too_small');
    }

    // Clamp into range before use, so a rounding error at the edge trims the
    // rect rather than producing an out-of-bounds region.
    $x = max(0.0, min(1.0, $x));
    $y = max(0.0, min(1.0, $y));
    $w = max(0.0, min(1.0 - $x, $w));
    $h = max(0.0, min(1.0 - $y, $h));

    if ($w <= 0.0 || $h <= 0.0) {
        throw new RuntimeException('crop_out_of_bounds');
    }

    imageproc_ensure_dir('original');
    $outAbs = imageproc_upload_path('original', imageproc_new_slug(), 'png');

    if (class_exists('Imagick')) {
        imageproc_crop_imagick($srcAbs, $outAbs, $x, $y, $w, $h);
    } else {
        imageproc_crop_gd($srcAbs, $outAbs, $x, $y, $w, $h);
    }
    return $outAbs;
}

function imageproc_crop_imagick(
    string $srcAbs,
    string $outAbs,
    float $x,
    float $y,
    float $w,
    float $h
): void {
    $im = new Imagick();
    try {
        $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 512 * 1024 * 1024);
        $im->readImage($srcAbs);

        if ($im->getNumberImages() > 1) {
            $im->setIteratorIndex(0);
            $frame = $im->getImage();
            $im->clear();
            $im = $frame;
        }

        imageproc_imagick_orient($im);

        $sw = $im->getImageWidth();
        $sh = $im->getImageHeight();
        $cw = max(1, (int) round($sw * $w));
        $ch = max(1, (int) round($sh * $h));
        $cx = max(0, min($sw - $cw, (int) round($sw * $x)));
        $cy = max(0, min($sh - $ch, (int) round($sh * $y)));

        $im->cropImage($cw, $ch, $cx, $cy);
        // cropImage leaves the original canvas geometry behind, which some
        // encoders honour — the result would carry the uncropped page size.
        $im->setImagePage(0, 0, 0, 0);

        $im->setImageFormat('png');
        if (!$im->writeImage($outAbs)) {
            throw new RuntimeException('crop_write_failed');
        }
    } finally {
        $im->clear();
    }
}

function imageproc_crop_gd(
    string $srcAbs,
    string $outAbs,
    float $x,
    float $y,
    float $w,
    float $h
): void {
    $data = @file_get_contents($srcAbs);
    if ($data === false) {
        throw new RuntimeException('crop_source_unreadable');
    }
    $img = @imagecreatefromstring($data);
    unset($data);
    if ($img === false) {
        throw new RuntimeException('crop_decode_failed');
    }

    try {
        $img = imageproc_gd_orient($img, $srcAbs);

        $sw = imagesx($img);
        $sh = imagesy($img);
        $cw = max(1, (int) round($sw * $w));
        $ch = max(1, (int) round($sh * $h));
        $cx = max(0, min($sw - $cw, (int) round($sw * $x)));
        $cy = max(0, min($sh - $ch, (int) round($sh * $y)));

        $cropped = imagecrop($img, array('x' => $cx, 'y' => $cy, 'width' => $cw, 'height' => $ch));
        if ($cropped === false) {
            throw new RuntimeException('crop_failed');
        }

        try {
            if (!imagepng($cropped, $outAbs)) {
                throw new RuntimeException('crop_write_failed');
            }
        } finally {
            imagedestroy($cropped);
        }
    } finally {
        imagedestroy($img);
    }
}

/** Read back a produced WebP's dimensions — used by tests and sanity checks. */
function imageproc_dimensions(string $abs): ?array
{
    $info = @getimagesize($abs);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return null;
    }
    return array((int) $info[0], (int) $info[1]);
}
