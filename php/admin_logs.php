<?php
header("Content-Type: application/json");
session_start();
include "db.php";

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where = '';
$params = [];
$types = '';
if ($search !== '') {
    $where = "WHERE (admin_name LIKE ? OR action LIKE ? OR target LIKE ? OR detail LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like, $like];
    $types = 'ssss';
}

// Count
$countSql = "SELECT COUNT(*) AS c FROM activity_logs $where";
$cStmt = $conn->prepare($countSql);
if ($params) $cStmt->bind_param($types, ...$params);
$cStmt->execute();
$total = (int)$cStmt->get_result()->fetch_assoc()['c'];

// Select
$sql = "SELECT id, admin_id, admin_name, action, target, detail, ip, created_at
        FROM activity_logs $where
        ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types . 'ii', $params[0], $params[1], $params[2], $params[3], $limit, $offset);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$res = $stmt->get_result();

$logs = [];
while ($r = $res->fetch_assoc()) $logs[] = $r;

echo json_encode([
    "success" => true,
    "logs"    => $logs,
    "total"   => $total,
    "page"    => $page,
    "pages"   => max(1, (int)ceil($total / $limit))
]);
?>
