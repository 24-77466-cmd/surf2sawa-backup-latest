<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$sql = "SELECT r.id, r.reference, r.user_id, u.fullname, u.email, r.amount,
               r.payment_method, r.status, r.created_at, r.reviewed_at,
               reviewer.fullname AS reviewer_name
        FROM topup_requests r
        JOIN users u ON u.id = r.user_id
        LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
        ORDER BY (r.status = 'pending') DESC, r.id DESC
        LIMIT 200";
$result = $conn->query($sql);
$requests = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['user_id'] = (int) $row['user_id'];
    $row['amount'] = number_format((float) $row['amount'], 2, '.', '');
    $requests[] = $row;
}

echo json_encode(['success' => true, 'requests' => $requests, 'demo_only' => true]);
?>