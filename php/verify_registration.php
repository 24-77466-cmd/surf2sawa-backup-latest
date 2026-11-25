<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/registration_service.php';

function verify_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$pendingId = (string) ($_SESSION['registration_pending_id'] ?? '');
$data = json_decode(file_get_contents('php://input'), true);
$code = is_array($data) && is_string($data['code'] ?? null) ? trim($data['code']) : '';
if (!preg_match('/^\d{6}$/', $code)) {
    verify_response(['success' => false, 'message' => 'Enter the 6-digit code from your authenticator app.'], 400);
}
if (!preg_match('/^[a-f0-9]{32}$/', $pendingId)) {
    verify_response(['success' => false, 'message' => 'No registration is waiting for verification. Please register again.'], 401);
}

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT * FROM pending_registrations WHERE id = ? FOR UPDATE');
    $stmt->bind_param('s', $pendingId);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc();

    if (!$pending) {
        $conn->rollback();
        unset($_SESSION['registration_pending_id']);
        verify_response(['success' => false, 'message' => 'Verification request not found. Please register again.'], 404);
    }
    if (strtotime($pending['expires_at']) <= time()) {
        $conn->commit();
        verify_response(['success' => false, 'message' => 'This registration has expired. Please submit the registration form again.'], 410);
    }
    if ((int) $pending['otp_attempts'] >= 5) {
        $conn->commit();
        verify_response(['success' => false, 'message' => 'Too many incorrect attempts. Please register again.'], 429);
    }
    if ($pending['otp_mode'] !== 'totp' || empty($pending['totp_secret'])) {
        $conn->rollback();
        verify_response(['success' => false, 'message' => 'This registration cannot be verified. Please register again.'], 409);
    }

    if (!totp_verify($pending['totp_secret'], $code)) {
        $increment = $conn->prepare('UPDATE pending_registrations SET otp_attempts = otp_attempts + 1 WHERE id = ?');
        $increment->bind_param('s', $pendingId);
        $increment->execute();
        $conn->commit();
        verify_response(['success' => false, 'message' => 'That code is incorrect. Open Google Authenticator and use the current code for Surf to Sawa.'], 400);
    }

    $registrationData = json_decode($pending['registration_data'], true);
    if (!is_array($registrationData)) {
        throw new RuntimeException('Stored registration data is invalid.');
    }
    $registrationData['totp_secret'] = $pending['totp_secret'];
    $result = complete_verified_registration($conn, $registrationData);
    $delete = $conn->prepare('DELETE FROM pending_registrations WHERE id = ?');
    $delete->bind_param('s', $pendingId);
    $delete->execute();
    $conn->commit();

    unset($_SESSION['registration_pending_id']);
    session_regenerate_id(true);
    verify_response([
        'success' => true,
        'message' => 'Authenticator verified and registration complete. You can now log in.',
        'customer_code' => $result['customer_code']
    ]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Registration verification failed: ' . $e->getMessage());
    verify_response(['success' => false, 'message' => 'Could not complete registration. Please try again.'], 500);
}
?>
