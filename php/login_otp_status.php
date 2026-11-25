<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/totp.php';

function login_status_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$userId = (int) ($_SESSION['login_totp_user_id'] ?? 0);
$pendingSecret = (string) ($_SESSION['login_totp_pending_secret'] ?? '');
if ($userId <= 0) {
    login_status_response(['success' => false, 'pending' => false], 404);
}

$stmt = $conn->prepare('SELECT email, totp_secret FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    unset($_SESSION['login_totp_user_id'], $_SESSION['login_totp_pending_secret']);
    login_status_response(['success' => false, 'pending' => false], 404);
}

if ($pendingSecret !== '') {
    login_status_response([
        'success' => true,
        'pending' => true,
        'totp_setup_required' => true,
        'totp_secret' => $pendingSecret,
        'otpauth_uri' => totp_uri($pendingSecret, $user['email']),
        'message' => 'Set up Google Authenticator: scan the QR code, then enter the 6-digit code it shows.'
    ]);
}
if (!empty($user['totp_secret'])) {
    login_status_response([
        'success' => true,
        'pending' => true,
        'totp_required' => true,
        'message' => 'Enter the 6-digit code from your Google Authenticator app.'
    ]);
}
unset($_SESSION['login_totp_user_id']);
login_status_response(['success' => false, 'pending' => false], 404);
?>
