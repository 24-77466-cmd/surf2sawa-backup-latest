<?php
/** Shared validation/removal helpers for admin-uploaded plan pictures. */

const PLAN_IMAGE_DIR = __DIR__ . '/../uploads/plans';
const PLAN_IMAGE_MAX_BYTES = 2097152; // 2 MB

/** Only paths produced by the upload endpoint are accepted (hex name + image extension). */
function plan_image_path_is_valid(string $path): bool
{
    return (bool) preg_match('#^uploads/plans/[a-f0-9]{32}\.(jpg|png|gif|webp)$#', $path);
}

/** Absolute filesystem path for a stored relative image path, or null. */
function plan_image_absolute(string $path): ?string
{
    if (!plan_image_path_is_valid($path)) {
        return null;
    }
    $absolute = realpath(PLAN_IMAGE_DIR . '/' . basename($path));
    $dir = realpath(PLAN_IMAGE_DIR);
    if ($absolute === false || $dir === false || !str_starts_with($absolute, $dir . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $absolute;
}

/** Delete a stored plan picture if it exists; ignores invalid or missing paths. */
function plan_image_delete(?string $path): void
{
    if (!is_string($path) || $path === '') {
        return;
    }
    $absolute = plan_image_absolute($path);
    if ($absolute !== null && is_file($absolute)) {
        @unlink($absolute);
    }
}
?>