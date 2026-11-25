<?php
header("Content-Type: application/json");
session_start();

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

echo json_encode([
    "success" => $isAdmin,
    "user" => $isAdmin ? [
        "id"       => $_SESSION['user_id'] ?? null,
        "fullname" => $_SESSION['fullname'] ?? 'Admin',
        "role"     => 'admin'
    ] : null
]);
?>
