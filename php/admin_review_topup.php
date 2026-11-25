<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log_helper.php';

function admin_topup_review_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    admin_topup_review_response(['success' => false, 'message' => 'Unauthorized'], 403);
}
$data = json_decode(file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];
$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT);
$decision = $data['decision'] ?? '';
if (!$requestId || !in_array($decision, ['approve', 'reject'], true)) {
    admin_topup_review_response(['success' => false, 'message' => 'Choose a valid request and decision.'], 400);
}

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT user_id, reference, amount, status FROM topup_requests WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    if (!$request) {
        $conn->rollback();
        admin_topup_review_response(['success' => false, 'message' => 'Top-up request not found.'], 404);
    }
    if ($request['status'] !== 'pending') {
        $conn->rollback();
        admin_topup_review_response(['success' => false, 'message' => 'This request has already been reviewed.'], 409);
    }

    if ($decision === 'approve') {
        $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
        $credit->bind_param('di', $request['amount'], $request['user_id']);
        $credit->execute();
        if ($credit->affected_rows !== 1) {
            throw new RuntimeException('Unable to apply the demo credit.');
        }
    }

    $status = $decision === 'approve' ? 'approved' : 'rejected';
    $adminId = (int) $_SESSION['user_id'];
    $update = $conn->prepare("UPDATE topup_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
    $update->bind_param('sii', $status, $adminId, $requestId);
    $update->execute();
    if ($update->affected_rows !== 1) {
        throw new RuntimeException('The request status changed before it could be reviewed.');
    }

    $action = $decision === 'approve' ? 'DEMO_TOPUP_APPROVED' : 'DEMO_TOPUP_REJECTED';
    $detail = $decision === 'approve'
        ? 'Approved simulated ' . strtoupper((string) $request['reference']) . ' for ₱' . number_format((float) $request['amount'], 2)
        : 'Rejected simulated ' . strtoupper((string) $request['reference']) . ' for ₱' . number_format((float) $request['amount'], 2);
    log_action($conn, $action, (string) $request['reference'], $detail);
    $conn->commit();

    admin_topup_review_response([
        'success' => true,
        'status' => $status,
        'message' => $decision === 'approve'
            ? 'Demo request approved; the demo balance was credited.'
            : 'Demo request rejected; no balance was added.'
    ]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Demo top-up review failed: ' . $e->getMessage());
    admin_topup_review_response(['success' => false, 'message' => 'Could not review this demo request. Please retry.'], 500);
}
?>