<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT id, reference, amount, payment_method, status, created_at, reviewed_at FROM topup_requests WHERE user_id = ? ORDER BY id DESC LIMIT 25');
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$requests = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['amount'] = number_format((float) $row['amount'], 2, '.', '');
    $requests[] = $row;
}

echo json_encode(['success' => true, 'requests' => $requests, 'demo_only' => true]);
?>