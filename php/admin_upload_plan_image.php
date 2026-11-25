<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/plan_image_helper.php';

function upload_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// Only administrators may upload plan pictures.
if (($_SESSION['role'] ?? '') !== 'admin') {
    upload_response(['success' => false, 'message' => 'Unauthorized'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upload_response(['success' => false, 'message' => 'Use POST to upload a picture.'], 405);
}

$file = $_FILES['image'] ?? null;
if (!$file || !is_array($file)) {
    upload_response(['success' => false, 'message' => 'No picture was uploaded.'], 400);
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $message = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
        ? 'The picture is too large. Maximum size is 2 MB.'
        : 'Upload failed. Please try again.';
    upload_response(['success' => false, 'message' => $message], 400);
}
if (($file['size'] ?? 0) <= 0 || $file['size'] > PLAN_IMAGE_MAX_BYTES) {
    upload_response(['success' => false, 'message' => 'The picture is too large. Maximum size is 2 MB.'], 400);
}

// Verify the file is a real image, not just a renamed script.
$info = @getimagesize($file['tmp_name']);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp'
];
if ($info === false || !isset($allowed[$info['mime']])) {
    upload_response(['success' => false, 'message' => 'Only JPG, PNG, GIF, or WebP pictures are allowed.'], 400);
}

if (!is_dir(PLAN_IMAGE_DIR) && !mkdir(PLAN_IMAGE_DIR, 0775, true) && !is_dir(PLAN_IMAGE_DIR)) {
    upload_response(['success' => false, 'message' => 'Could not create the upload directory.'], 500);
}

$relative = 'uploads/plans/' . bin2hex(random_bytes(16)) . '.' . $allowed[$info['mime']];
$target = PLAN_IMAGE_DIR . '/' . basename($relative);
if (!move_uploaded_file($file['tmp_name'], $target)) {
    upload_response(['success' => false, 'message' => 'Could not save the picture.'], 500);
}

upload_response([
    'success' => true,
    'message' => 'Picture uploaded.',
    'image' => $relative
]);
?>