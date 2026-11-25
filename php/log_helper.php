<?php
function log_action($conn, $action, $target = '', $detail = '') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $admin_id   = $_SESSION['user_id'] ?? null;
    $admin_name = $_SESSION['fullname'] ?? 'System';
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $conn->prepare("
        INSERT INTO activity_logs (admin_id, admin_name, action, target, detail, ip)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("isssss", $admin_id, $admin_name, $action, $target, $detail, $ip);
        $stmt->execute();
    }
}
?>
