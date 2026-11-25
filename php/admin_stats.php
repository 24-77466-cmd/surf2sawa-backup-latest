<?php
header("Content-Type: application/json");
session_start();
include "db.php";

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

function safeCount($conn, $sql) {
    try {
        $res = $conn->query($sql);
        if ($res) {
            $row = $res->fetch_assoc();
            return (int)($row['c'] ?? 0);
        }
    } catch (Exception $e) {}
    return 0;
}

function safeSum($conn, $sql) {
    try {
        $res = $conn->query($sql);
        if ($res) {
            $row = $res->fetch_assoc();
            return (float)($row['t'] ?? 0);
        }
    } catch (Exception $e) {}
    return 0.0;
}

$users     = safeCount($conn, "SELECT COUNT(*) AS c FROM users");
$plans     = safeCount($conn, "SELECT COUNT(*) AS c FROM plans");
$purchases = safeCount($conn, "SELECT COUNT(*) AS c FROM purchases");
$active    = safeCount($conn, "SELECT COUNT(*) AS c FROM purchases WHERE expires_at > NOW()");
$revenue   = safeSum($conn, "
    SELECT COALESCE(SUM(p.price), 0) AS t
    FROM purchases pu
    LEFT JOIN plans p ON p.id = pu.plan_id
");

echo json_encode([
    "success" => true,
    "stats"   => [
        "total_users"     => $users,
        "total_plans"     => $plans,
        "total_purchases" => $purchases,
        "active_subs"     => $active,
        "total_revenue"   => $revenue
    ]
]);
?>
