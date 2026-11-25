<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/registration_otp.php';
require_once __DIR__ . '/totp.php';

function registration_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/** Mask an email for display, e.g. ju**@example.com. */
function mask_email_address(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) {
        return $email;
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at);
    $keep = min(2, strlen($local));
    return substr($local, 0, $keep) . str_repeat('*', max(1, strlen($local) - $keep)) . $domain;
}

$data = json_decode(file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];

$fullname = trim((string) ($data['fullname'] ?? ''));
$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
$phone = normalize_ph_mobile_number($data['phone'] ?? '');
$address = trim((string) ($data['address'] ?? ''));
$region = trim((string) ($data['region'] ?? ''));
$province = trim((string) ($data['province'] ?? ''));
$city = trim((string) ($data['city'] ?? ''));
$barangayName = trim((string) ($data['barangay_name'] ?? ''));
$barangayCode = trim((string) ($data['barangay_code'] ?? ''));

if (!$fullname || !$email || !$password || !$phone) {
    registration_response(['success' => false, 'message' => 'Name, email, password, and a valid Philippine mobile number are required.'], 400);
}
if (strlen($fullname) > 100 || strlen($email) > 100 || strlen($address) > 255 || strlen($password) < 6) {
    registration_response(['success' => false, 'message' => 'Check the name, email, address, and password length. Password must be at least 6 characters.'], 400);
}
if (strlen($region) > 30 || strlen($province) > 100 || strlen($city) > 60 || strlen($barangayName) > 60 || strlen($barangayCode) > 20) {
    registration_response(['success' => false, 'message' => 'One of the selected location values is too long. Please select your location again.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    registration_response(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
}
if (!$address || !$region || !$province || !$city || !$barangayName || !$barangayCode) {
    registration_response(['success' => false, 'message' => 'Please provide your full service address and location.'], 400);
}

$check = $conn->prepare('SELECT id FROM users WHERE email = ? OR phone_e164 = ? LIMIT 1');
$check->bind_param('ss', $email, $phone);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    registration_response(['success' => false, 'message' => 'Email or phone number is already registered. Please log in instead.'], 409);
}

$pendingCheck = $conn->prepare('SELECT id, expires_at FROM pending_registrations WHERE email = ? LIMIT 1');
$pendingCheck->bind_param('s', $email);
$pendingCheck->execute();
$existingPending = $pendingCheck->get_result()->fetch_assoc();
if ($existingPending && strtotime($existingPending['expires_at']) > time()) {
    if (hash_equals((string) ($_SESSION['registration_pending_id'] ?? ''), $existingPending['id'])) {
        registration_response(['success' => false, 'message' => 'This registration is already awaiting verification. Scan the QR code and enter the code from your authenticator app.'], 409);
    }
    registration_response(['success' => false, 'message' => 'A verification is already pending for this email. Continue in the browser where you started, or try again after it expires.'], 409);
}
if ($existingPending) {
    $removeExpired = $conn->prepare('DELETE FROM pending_registrations WHERE id = ?');
    $removeExpired->bind_param('s', $existingPending['id']);
    $removeExpired->execute();
}

$totpSecret = totp_generate_secret();
$pendingId = bin2hex(random_bytes(16));
$registrationData = [
    'fullname' => $fullname,
    'email' => $email,
    'phone_e164' => $phone,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'address' => $address,
    'region' => $region,
    'province' => $province,
    'city' => $city,
    'barangay_name' => $barangayName,
    'barangay_code' => $barangayCode,
    'totp_secret' => $totpSecret
];
$encodedData = json_encode($registrationData, JSON_UNESCAPED_UNICODE);
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
// Authenticator setup needs time to scan the QR code; allow 30 minutes.
$expiresAt = date('Y-m-d H:i:s', time() + 1800);

$insert = $conn->prepare("INSERT INTO pending_registrations (id, email, phone_e164, registration_data, otp_hash, otp_mode, totp_secret, last_sent_at, expires_at, request_ip) VALUES (?, ?, ?, ?, NULL, 'totp', ?, NOW(), ?, ?)");
$insert->bind_param('sssssss', $pendingId, $email, $phone, $encodedData, $totpSecret, $expiresAt, $ip);
$insert->execute();

session_regenerate_id(true);
$_SESSION['registration_pending_id'] = $pendingId;
registration_response([
    'success' => true,
    'message' => 'Scan the QR code with Google Authenticator, then enter the 6-digit code it shows.',
    'delivery_mode' => 'totp',
    'totp_secret' => $totpSecret,
    'otpauth_uri' => totp_uri($totpSecret, $email),
    'email_masked' => mask_email_address($email),
    'phone_masked' => substr($phone, 0, 5) . str_repeat('*', max(0, strlen($phone) - 9)) . substr($phone, -4)
]);
?>
