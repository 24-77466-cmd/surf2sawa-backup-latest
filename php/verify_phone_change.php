<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/registration_otp.php';
require_once __DIR__ . '/totp.php';

function phone_verify_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    phone_verify_response(['success' => false, 'message' => 'Please log in to change your mobile number.'], 401);
}
$userId = (int) $_SESSION['user_id'];

$newPhone = normalize_ph_mobile_number($_SESSION['phone_change_pending_phone'] ?? '');
$pendingSecret = (string) ($_SESSION['phone_change_pending_secret'] ?? '');
$data = json_decode(file_get_contents('php://input'), true);
$code = is_array($data) && is_string($data['code'] ?? null) ? trim($data['code']) : '';

if (!$newPhone) {
    phone_verify_response(['success' => false, 'message' => 'No phone change is waiting for verification. Please start again.'], 401);
}
if (!preg_match('/^\d{6}$/', $code)) {
    phone_verify_response(['success' => false, 'message' => 'Enter the 6-digit code from your authenticator app.'], 400);
}

$stmt = $conn->prepare('SELECT id, email, totp_secret FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    unset($_SESSION['phone_change_pending_phone'], $_SESSION['phone_change_pending_secret']);
    phone_verify_response(['success' => false, 'message' => 'User account no longer exists.'], 401);
}
// A stored secret is authoritative; the pending secret is a first-time enrollment.
$secret = !empty($user['totp_secret']) ? (string) $user['totp_secret'] : $pendingSecret;
if ($secret === '') {
    unset($_SESSION['phone_change_pending_phone'], $_SESSION['phone_change_pending_secret']);
    phone_verify_response(['success' => false, 'message' => 'No authenticator is set up for this account. Please start again.'], 409);
}

$locked = totp_attempt_check($conn, $userId, 'phone_change');
if ($locked !== null) {
    phone_verify_response(['success' => false, 'message' => $locked], 429);
}
if (!totp_verify($secret, $code)) {
    totp_attempt_record_failure($conn, $userId, 'phone_change');
    phone_verify_response(['success' => false, 'message' => 'That code is incorrect. Open Google Authenticator and use the current code for Surf to Sawa.'], 400);
}
totp_attempt_clear($conn, $userId, 'phone_change');

$check = $conn->prepare('SELECT id FROM users WHERE phone_e164 = ? AND id != ? LIMIT 1');
$check->bind_param('si', $newPhone, $userId);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    unset($_SESSION['phone_change_pending_phone']);
    phone_verify_response(['success' => false, 'message' => 'That mobile number is already registered to another account.'], 409);
}

$update = $conn->prepare('UPDATE users SET phone_e164 = ?, phone_verified = 1 WHERE id = ?');
$update->bind_param('si', $newPhone, $userId);
$update->execute();

// Persist the confirmed secret when this change was also the first enrollment.
if (empty($user['totp_secret']) && $pendingSecret !== '') {
    $save = $conn->prepare('UPDATE users SET totp_secret = ? WHERE id = ?');
    $save->bind_param('si', $pendingSecret, $userId);
    $save->execute();
}
unset($_SESSION['phone_change_pending_phone'], $_SESSION['phone_change_pending_secret']);

log_action($conn, 'PHONE_CHANGED', $user['email'], "Mobile number changed to $newPhone");
phone_verify_response([
    'success' => true,
    'message' => 'Mobile number updated successfully.',
    'phone' => $newPhone
]);
?>
