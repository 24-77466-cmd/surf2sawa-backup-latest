<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/topup_helpers.php';

function topup_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    topup_json_response(['success' => false, 'message' => 'Please log in with a user account to request a demo top-up.'], 401);
}

$data = json_decode(file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];
$amount = normalize_topup_amount($data['amount'] ?? null);
$method = $data['method'] ?? null;
if ($amount === null) {
    topup_json_response(['success' => false, 'message' => 'Enter an amount between ₱20.00 and ₱5,000.00 with up to two decimal places.'], 400);
}
if (!is_demo_payment_method($method)) {
    topup_json_response(['success' => false, 'message' => 'Choose GCash or bank transfer.'], 400);
}

$userId = (int) $_SESSION['user_id'];
$pendingCheck = $conn->prepare("SELECT COUNT(*) AS total FROM topup_requests WHERE user_id = ? AND status = 'pending'");
$pendingCheck->bind_param('i', $userId);
$pendingCheck->execute();
$pendingCount = (int) ($pendingCheck->get_result()->fetch_assoc()['total'] ?? 0);
if ($pendingCount >= 3) {
    topup_json_response(['success' => false, 'message' => 'You already have three pending demo top-up requests. Wait for admin review before submitting another.'], 429);
}

$reference = generate_topup_reference();
try {
    $stmt = $conn->prepare("INSERT INTO topup_requests (reference, user_id, amount, payment_method, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->bind_param('siss', $reference, $userId, $amount, $method);
    $stmt->execute();
    $requestId = (int) $conn->insert_id;
} catch (Throwable $e) {
    error_log('Demo top-up request creation failed: ' . $e->getMessage());
    topup_json_response(['success' => false, 'message' => 'Could not create the demo top-up request. Please try again.'], 500);
}

topup_json_response([
    'success' => true,
    'message' => 'Demo request submitted. No payment was made and your balance has not changed. An admin must approve it before demo credits are added.',
    'request' => [
        'id' => $requestId,
        'reference' => $reference,
        'amount' => $amount,
        'method' => $method,
        'status' => 'pending'
    ],
    'demo_only' => true
]);
?>