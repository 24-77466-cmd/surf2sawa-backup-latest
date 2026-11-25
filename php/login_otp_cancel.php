<?php
header('Content-Type: application/json');
session_start();
// Abandon a pending admin authenticator step so the login form starts fresh.
unset($_SESSION['login_totp_user_id'], $_SESSION['login_totp_pending_secret']);
echo json_encode(['success' => true]);
?>