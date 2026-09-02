<?php
require_once __DIR__ . '/constants.php';

function sanitizeText(?string $value): string
{
    return trim((string) $value);
}

/**
 * Validates one entry from a $_FILES[...] array as a genuine image:
 * real content/MIME (finfo + getimagesize, not the client-sent
 * Content-Type), an allowed extension matching the detected MIME, and a
 * size cap. Returns an error message string, or null if valid.
 */
function validateUploadedImage(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Upload failed. Please try again.';
    }

    if (($file['size'] ?? 0) <= 0 || $file['size'] > UPLOAD_MAX_BYTES) {
        return 'Each photo must be smaller than ' . (UPLOAD_MAX_BYTES / 1024 / 1024) . 'MB.';
    }

    $tmpName = $file['tmp_name'];

    if (getimagesize($tmpName) === false) {
        return 'That file is not a valid image.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $tmpName);
    finfo_close($finfo);

    if (!in_array($detectedMime, UPLOAD_ALLOWED_MIME_TYPES, true)) {
        return 'Only .jpg and .png photos are allowed.';
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $expectedExtension = $detectedMime === 'image/jpeg' ? 'jpg' : 'png';
    if ($extension !== $expectedExtension) {
        return 'The photo extension must match its file type. Only .jpg and .png photos are allowed.';
    }

    return null;
}

/**
 * Saves an already-validated upload under a server-generated random
 * filename (never the client-supplied name) and returns the stored
 * filename + detected mime + size, or null on move failure.
 */
function storeUploadedImage(array $file): ?array
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $extensionByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $extension = $extensionByMime[$mime] ?? 'jpg';

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = UPLOAD_DIR . $storedFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return null;
    }

    return [
        'stored_filename' => $storedFilename,
        'mime_type' => $mime,
        'file_size_bytes' => filesize($destination),
    ];
}
