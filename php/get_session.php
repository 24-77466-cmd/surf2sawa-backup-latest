<?php
header("Content-Type: application/json");
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, fullname, email, phone_e164, phone_verified, totp_secret, role, balance FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    session_destroy();
    echo json_encode(["success" => false, "message" => "User account no longer exists"]);
    exit;
}

// Fetch active subscription if any
$subStmt = $conn->prepare("
    SELECT pu.id, pu.plan_id, pu.purchased_at, pu.expires_at, p.name AS plan_name, p.duration_hours
    FROM purchases pu
    JOIN plans p ON p.id = pu.plan_id
    WHERE pu.user_id = ? AND pu.expires_at > NOW()
    ORDER BY pu.expires_at DESC
    LIMIT 1
");
$subStmt->bind_param("i", $userId);
$subStmt->execute();
$activeSub = $subStmt->get_result()->fetch_assoc();

// Fetch customer location & coverage status if registered
$custStmt = $conn->prepare("
    SELECT customer_code, address, city, region, province, status, signal_dbm, zone
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");
$custStmt->bind_param("i", $userId);
$custStmt->execute();
$cust = $custStmt->get_result()->fetch_assoc();

$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role']     = $user['role'];

echo json_encode([
    "success" => true,
    "user" => [
        "id"                  => (int)$user['id'],
        "fullname"            => $user['fullname'],
        "email"               => $user['email'],
        "phone"               => $user['phone_e164'],
        "phone_verified"      => (int)$user['phone_verified'] === 1,
        "totp_enabled"        => !empty($user['totp_secret']),
        "role"                => $user['role'],
        "balance"             => (float)$user['balance'],
        "active_subscription" => $activeSub ?: null,
        "location"            => $cust ? [
            "code"      => $cust['customer_code'],
            "address"   => $cust['address'],
            "city"      => $cust['city'],
            "province"  => $cust['province'] ?? '',
            "region"    => $cust['region'],
            "zone"      => $cust['zone'],
            "status"    => $cust['status'] ?: 'active',
            "signal"    => ($cust['signal_dbm'] !== null ? $cust['signal_dbm'] . ' dBm' : '-16 dBm')
        ] : null
    ]
]);
?>
