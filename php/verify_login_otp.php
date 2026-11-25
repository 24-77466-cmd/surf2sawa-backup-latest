<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/totp.php';

function login_otp_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$userId = (int) ($_SESSION['login_totp_user_id'] ?? 0);
$pendingSecret = (string) ($_SESSION['login_totp_pending_secret'] ?? '');
$data = json_decode(file_get_contents('php://input'), true);
$code = is_array($data) && is_string($data['code'] ?? null) ? trim($data['code']) : '';

if ($userId <= 0) {
    login_otp_response(['success' => false, 'message' => 'No login is waiting for verification. Please log in again.'], 401);
}
if (!preg_match('/^\d{6}$/', $code)) {
    login_otp_response(['success' => false, 'message' => 'Enter the 6-digit code from your authenticator app.'], 400);
}

$stmt = $conn->prepare('SELECT id, fullname, email, phone_e164, phone_verified, totp_secret, role, balance FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    unset($_SESSION['login_totp_user_id'], $_SESSION['login_totp_pending_secret']);
    login_otp_response(['success' => false, 'message' => 'User account no longer exists.'], 404);
}
if (!empty($user['phone_e164']) && (int) $user['phone_verified'] !== 1) {
    login_otp_response(['success' => false, 'message' => 'Please verify your phone number before logging in.'], 403);
}

$secret = $pendingSecret !== '' ? $pendingSecret : (string) $user['totp_secret'];
if ($secret === '') {
    unset($_SESSION['login_totp_user_id'], $_SESSION['login_totp_pending_secret']);
    login_otp_response(['success' => false, 'message' => 'No authenticator is set up for this account. Please log in again.'], 409);
}

$locked = totp_attempt_check($conn, $userId, 'login');
if ($locked !== null) {
    login_otp_response(['success' => false, 'message' => $locked], 429);
}
if (!totp_verify($secret, $code)) {
    totp_attempt_record_failure($conn, $userId, 'login');
    login_otp_response(['success' => false, 'message' => 'That code is incorrect. Open Google Authenticator and use the current code for Surf to Sawa.'], 400);
}
totp_attempt_clear($conn, $userId, 'login');

// Enrollment: persist the confirmed secret on the account.
if ($pendingSecret !== '') {
    $save = $conn->prepare('UPDATE users SET totp_secret = ? WHERE id = ?');
    $save->bind_param('si', $pendingSecret, $userId);
    $save->execute();
}

unset($_SESSION['login_totp_user_id'], $_SESSION['login_totp_pending_secret']);
session_regenerate_id(true);
$_SESSION['user_id']  = $user['id'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role']     = $user['role'];

login_otp_response([
    'success' => true,
    'message' => 'Login successful!',
    'user' => [
        'id'       => (int) $user['id'],
        'fullname' => $user['fullname'],
        'email'    => $user['email'],
        'role'     => $user['role'],
        'balance'  => (float) $user['balance']
    ]
]);
?>
