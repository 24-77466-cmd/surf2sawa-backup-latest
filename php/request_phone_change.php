<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/registration_otp.php';
require_once __DIR__ . '/totp.php';

function phone_change_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    phone_change_response(['success' => false, 'message' => 'Please log in to change your mobile number.'], 401);
}
$userId = (int) $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];
$phone = normalize_ph_mobile_number($data['phone'] ?? '');

if (!$phone) {
    phone_change_response(['success' => false, 'message' => 'Enter a valid Philippine mobile number.'], 400);
}

$stmt = $conn->prepare('SELECT id, email, phone_e164, totp_secret FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    phone_change_response(['success' => false, 'message' => 'User account no longer exists.'], 401);
}
if ($phone === $user['phone_e164']) {
    phone_change_response(['success' => false, 'message' => 'That is already your current mobile number.'], 400);
}

$check = $conn->prepare('SELECT id FROM users WHERE phone_e164 = ? AND id != ? LIMIT 1');
$check->bind_param('si', $phone, $userId);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    phone_change_response(['success' => false, 'message' => 'That mobile number is already registered to another account.'], 409);
}

$locked = totp_attempt_check($conn, $userId, 'phone_change');
if ($locked !== null) {
    phone_change_response(['success' => false, 'message' => $locked], 429);
}

$_SESSION['phone_change_pending_phone'] = $phone;

// Accounts without an authenticator (e.g. created before TOTP) enroll here:
// scan the QR code, then confirm the new number with the app's first code.
if (empty($user['totp_secret'])) {
    $secret = totp_generate_secret();
    $_SESSION['phone_change_pending_secret'] = $secret;
    phone_change_response([
        'success' => true,
        'totp_setup_required' => true,
        'message' => 'Set up Google Authenticator: scan the QR code, then enter the 6-digit code it shows to confirm the new number.',
        'totp_secret' => $secret,
        'otpauth_uri' => totp_uri($secret, $user['email']),
        'phone_masked' => substr($phone, 0, 5) . str_repeat('*', max(0, strlen($phone) - 9)) . substr($phone, -4)
    ]);
}

unset($_SESSION['phone_change_pending_secret']);
phone_change_response([
    'success' => true,
    'totp_required' => true,
    'message' => 'Enter the 6-digit code from your Google Authenticator app to confirm the new number.',
    'phone_masked' => substr($phone, 0, 5) . str_repeat('*', max(0, strlen($phone) - 9)) . substr($phone, -4)
]);
?>
