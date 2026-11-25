<?php
header("Content-Type: application/json");
session_start();
include "db.php";
include "log_helper.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Please log in first."]);
    exit;
}

$data   = json_decode(file_get_contents("php://input"), true);
$planId = intval($data['plan_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($planId <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid plan selected."]);
    exit;
}

// Get plan
$stmt = $conn->prepare("SELECT id, name, price, duration_hours FROM plans WHERE id = ?");
$stmt->bind_param("i", $planId);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();

if (!$plan) {
    echo json_encode(["success" => false, "message" => "Plan not found."]);
    exit;
}

$conn->begin_transaction();

try {
    // Lock user row and check balance
    $stmt = $conn->prepare("SELECT balance, fullname FROM users WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "User account not found."]);
        exit;
    }

    $currentBalance = (float)$user['balance'];
    $planPrice      = (float)$plan['price'];

    if ($currentBalance < $planPrice) {
        $conn->rollback();
        echo json_encode([
            "success" => false,
            "message" => "Insufficient balance (₱" . number_format($currentBalance, 2) . "). Plan price: ₱" . number_format($planPrice, 2) . ". Please top up."
        ]);
        exit;
    }

    // Atomic deduction
    $stmt = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
    $stmt->bind_param("did", $planPrice, $userId, $planPrice);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Transaction could not be completed. Please try again."]);
        exit;
    }

    $newBalance = $currentBalance - $planPrice;

    // Extend from existing active expiry if any, otherwise from now
    $subStmt = $conn->prepare("SELECT expires_at FROM purchases WHERE user_id = ? AND expires_at > NOW() ORDER BY expires_at DESC LIMIT 1");
    $subStmt->bind_param("i", $userId);
    $subStmt->execute();
    $existingSub = $subStmt->get_result()->fetch_assoc();

    $baseTime = $existingSub ? strtotime($existingSub['expires_at']) : time();
    $expires = date("Y-m-d H:i:s", $baseTime + ($plan['duration_hours'] * 3600));

    // Record purchase
    $stmt = $conn->prepare("INSERT INTO purchases (user_id, plan_id, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $userId, $planId, $expires);
    $stmt->execute();

    $conn->commit();

    echo json_encode([
        "success"     => true,
        "message"     => "Purchase successful! Enjoy your Surf to Sawa.",
        "plan_name"   => $plan['name'],
        "new_balance" => $newBalance,
        "expires_at"  => $expires
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Error processing purchase: " . $e->getMessage()]);
}
?>
