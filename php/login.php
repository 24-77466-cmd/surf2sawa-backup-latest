<?php
header("Content-Type: application/json");
session_start();
include "db.php";
require_once __DIR__ . "/totp.php";

$data = json_decode(file_get_contents("php://input"), true);

$email    = strtolower(trim($data['email'] ?? ''));
$password = $data['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(["success" => false, "message" => "Email and password are required."]);
    exit;
}

$stmt = $conn->prepare("SELECT id, fullname, email, phone_e164, phone_verified, totp_secret, password, role, balance FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Email not found."]);
    exit;
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user['password'])) {
    echo json_encode(["success" => false, "message" => "Incorrect password."]);
    exit;
}

if (!empty($user['phone_e164']) && (int) $user['phone_verified'] !== 1) {
    echo json_encode(["success" => false, "message" => "Please verify your phone number before logging in."]);
    exit;
}

// Regular users log in with email + password only. The authenticator is used
// for registration, phone-number changes, and admin logins — not every login.
if ($user['role'] !== 'admin') {
    session_regenerate_id(true);
    unset($_SESSION['login_totp_user_id'], $_SESSION['login_totp_pending_secret']);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['fullname'] = $user['fullname'];
    $_SESSION['role']     = $user['role'];

    echo json_encode([
        "success" => true,
        "message" => "Login successful!",
        "user" => [
            "id"       => (int) $user['id'],
            "fullname" => $user['fullname'],
            "email"    => $user['email'],
            "role"     => $user['role'],
            "balance"  => (float) $user['balance']
        ]
    ]);
    exit;
}

// Admin accounts always scan a FRESH QR code at every login. Deleting the
// entry in Google Authenticator can never lock an admin out — the next login
// simply issues a new secret, and confirming its code replaces the old one.
session_regenerate_id(true);
unset($_SESSION['user_id'], $_SESSION['fullname'], $_SESSION['role']);
$_SESSION['login_totp_user_id'] = (int) $user['id'];

$secret = totp_generate_secret();
$_SESSION['login_totp_pending_secret'] = $secret;
echo json_encode([
    "success" => true,
    "totp_setup_required" => true,
    "message" => "Admin login: scan this QR code with Google Authenticator, then enter the 6-digit code it shows.",
    "totp_secret" => $secret,
    "otpauth_uri" => totp_uri($secret, $user['email'])
]);
?>