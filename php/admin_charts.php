<?php
header("Content-Type: application/json");
session_start();
include "db.php";

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

// Generate the 14 calendar dates up to today
$dateMapRevenue = [];
$dateMapCounts  = [];
$dateMapUsers   = [];

for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dateMapRevenue[$d] = 0.0;
    $dateMapCounts[$d]  = 0;
    $dateMapUsers[$d]   = 0;
}

// --- Revenue last 14 days ---
$res = $conn->query("
    SELECT DATE(pu.purchased_at) AS d, COALESCE(SUM(p.price), 0) AS total
    FROM purchases pu
    LEFT JOIN plans p ON p.id = pu.plan_id
    WHERE pu.purchased_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(pu.purchased_at)
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if (isset($dateMapRevenue[$r['d']])) {
            $dateMapRevenue[$r['d']] = (float)$r['total'];
        }
    }
}
$revenue = [];
foreach ($dateMapRevenue as $d => $tot) {
    $revenue[] = ["date" => $d, "total" => $tot];
}

// --- Purchases count last 14 days ---
$res = $conn->query("
    SELECT DATE(purchased_at) AS d, COUNT(*) AS c
    FROM purchases
    WHERE purchased_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(purchased_at)
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if (isset($dateMapCounts[$r['d']])) {
            $dateMapCounts[$r['d']] = (int)$r['c'];
        }
    }
}
$counts = [];
foreach ($dateMapCounts as $d => $c) {
    $counts[] = ["date" => $d, "count" => $c];
}

// --- Plan popularity (top 5) ---
// Note: GROUP BY p.id, p.name for ONLY_FULL_GROUP_BY compliance
$plans = [];
$res = $conn->query("
    SELECT p.name, COUNT(pu.id) AS c
    FROM plans p
    LEFT JOIN purchases pu ON pu.plan_id = p.id
    GROUP BY p.id, p.name
    ORDER BY c DESC, p.price ASC
    LIMIT 5
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $plans[] = ["name" => $r['name'], "count" => (int)$r['c']];
    }
}

// --- User growth last 14 days ---
$res = $conn->query("
    SELECT DATE(created_at) AS d, COUNT(*) AS c
    FROM users
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(created_at)
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if (isset($dateMapUsers[$r['d']])) {
            $dateMapUsers[$r['d']] = (int)$r['c'];
        }
    }
}
$users = [];
foreach ($dateMapUsers as $d => $c) {
    $users[] = ["date" => $d, "count" => $c];
}

// --- Customer status breakdown ---
$status = ["active" => 0, "pending" => 0, "suspended" => 0, "unavailable" => 0];
try {
    $res = $conn->query("
        SELECT
          COALESCE(SUM(CASE WHEN status='active'      THEN 1 ELSE 0 END), 0) AS active,
          COALESCE(SUM(CASE WHEN status='pending'     THEN 1 ELSE 0 END), 0) AS pending,
          COALESCE(SUM(CASE WHEN status='suspended'   THEN 1 ELSE 0 END), 0) AS suspended,
          COALESCE(SUM(CASE WHEN status='unavailable' THEN 1 ELSE 0 END), 0) AS unavailable
        FROM customers
    ");
    if ($res && ($row = $res->fetch_assoc())) {
        $status = [
            "active"      => (int)$row['active'],
            "pending"     => (int)$row['pending'],
            "suspended"   => (int)$row['suspended'],
            "unavailable" => (int)$row['unavailable']
        ];
    }
} catch (Exception $e) {}

echo json_encode([
    "success" => true,
    "revenue" => $revenue,
    "counts"  => $counts,
    "plans"   => $plans,
    "users"   => $users,
    "status"  => $status
]);
?>
