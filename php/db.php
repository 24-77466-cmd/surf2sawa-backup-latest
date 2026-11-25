<?php
date_default_timezone_set('Asia/Manila');

$host = "localhost";
$user = "root";
$pass = "";
$db   = "surf_to_sawa";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["success" => false, "message" => "DB connection failed: " . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");
?>
