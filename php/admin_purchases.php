<?php
header("Content-Type: application/json");
session_start();
include "db.php";

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

$where = '';
$params = [];
$types = '';
if ($q !== '') {
    $where = "WHERE (u.fullname LIKE ? OR u.email LIKE ? OR p.name LIKE ? OR CAST(pu.id AS CHAR) LIKE ?)";
    $like = "%$q%";
    $params = [$like, $like, $like, $like];
    $types = 'ssss';
}

// Count
$countSql = "
    SELECT COUNT(*) AS c
    FROM purchases pu
    LEFT JOIN users u ON u.id = pu.user_id
    LEFT JOIN plans p ON p.id = pu.plan_id
    $where
";
$cStmt = $conn->prepare($countSql);
if ($params) $cStmt->bind_param($types, ...$params);
$cStmt->execute();
$total = (int)$cStmt->get_result()->fetch_assoc()['c'];

// Select
$sql = "
    SELECT
        pu.id,
        COALESCE(u.fullname, '[Deleted User]') AS fullname,
        COALESCE(u.email, '—') AS email,
        COALESCE(p.name, '[Deleted Plan]') AS plan_name,
        COALESCE(p.price, 0.00) AS price,
        pu.purchased_at,
        pu.expires_at,
        CASE WHEN pu.expires_at > NOW() THEN 'active' ELSE 'expired' END AS status
    FROM purchases pu
    LEFT JOIN users u ON u.id = pu.user_id
    LEFT JOIN plans p ON p.id = pu.plan_id
    $where
    ORDER BY pu.purchased_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types . 'ii', $params[0], $params[1], $params[2], $params[3], $limit, $offset);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $r['price'] = (float)$r['price'];
    $rows[] = $r;
}

echo json_encode([
    "success"   => true,
    "purchases" => $rows,
    "total"     => $total,
    "page"      => $page,
    "pages"     => max(1, (int)ceil($total / $limit))
]);
?>
