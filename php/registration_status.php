<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/totp.php';

function status_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$pendingId = (string) ($_SESSION['registration_pending_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $pendingId)) {
    status_response(['success' => false, 'pending' => false], 404);
}

$stmt = $conn->prepare('SELECT email, phone_e164, otp_mode, totp_secret, expires_at FROM pending_registrations WHERE id = ?');
$stmt->bind_param('s', $pendingId);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc();
if (!$pending) {
    unset($_SESSION['registration_pending_id']);
    status_response(['success' => false, 'pending' => false], 404);
}
if (strtotime($pending['expires_at']) <= time()) {
    status_response(['success' => false, 'pending' => false, 'message' => 'This verification has expired. Please submit the registration form again.'], 410);
}
if ($pending['otp_mode'] !== 'totp' || empty($pending['totp_secret'])) {
    status_response(['success' => false, 'pending' => false, 'message' => 'This registration cannot be verified. Please submit the registration form again.'], 409);
}

$phone = $pending['phone_e164'];
status_response([
    'success' => true,
    'pending' => true,
    'delivery_mode' => 'totp',
    'totp_secret' => $pending['totp_secret'],
    'otpauth_uri' => totp_uri($pending['totp_secret'], $pending['email']),
    'phone_masked' => substr($phone, 0, 5) . str_repeat('*', max(0, strlen($phone) - 9)) . substr($phone, -4),
    'message' => 'Scan the QR code with Google Authenticator, then enter the 6-digit code it shows.'
]);
?>
