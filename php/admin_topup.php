<?php
header("Content-Type: application/json");
session_start();
include "db.php";
include "log_helper.php";

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$data   = json_decode(file_get_contents("php://input"), true) ?: [];
$userId = (int)($data['user_id'] ?? 0);
$amount = (float)($data['amount'] ?? 0);

if ($userId <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid user specified."]);
    exit;
}

if ($amount <= 0 || $amount > 100000) {
    echo json_encode(["success" => false, "message" => "Top-up amount must be between ₱1.00 and ₱100,000.00."]);
    exit;
}

// Check user existence
$chk = $conn->prepare("SELECT fullname, balance FROM users WHERE id = ?");
$chk->bind_param("i", $userId);
$chk->execute();
$user = $chk->get_result()->fetch_assoc();

if (!$user) {
    echo json_encode(["success" => false, "message" => "User not found."]);
    exit;
}

$stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
$stmt->bind_param("di", $amount, $userId);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    $newBalance = (float)$user['balance'] + $amount;
    log_action($conn, 'TOPUP', $user['fullname'], "Added ₱" . number_format($amount, 2) . " (New balance: ₱" . number_format($newBalance, 2) . ")");
    echo json_encode([
        "success"     => true,
        "message"     => "Successfully added ₱" . number_format($amount, 2) . " to " . $user['fullname'] . "'s account.",
        "new_balance" => $newBalance
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to update balance: " . $conn->error]);
}
?>
