<?php
header("Content-Type: application/json");
session_start();
include "db.php";

// Strict Access Control: Admin only
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Access denied. Administrator privileges are required to view customer locations and records."
    ]);
    exit;
}

$barangays = [];
$cabinets  = [];
$customers = [];

try {
    $res = $conn->query("SELECT * FROM barangays ORDER BY name ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) $barangays[] = $r;
    }
} catch (Exception $e) {}

try {
    $res = $conn->query("SELECT * FROM cabinets ORDER BY id ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) $cabinets[] = $r;
    }
} catch (Exception $e) {}

try {
    $res = $conn->query("
        SELECT c.*, b.name AS barangay_name
        FROM customers c
        LEFT JOIN barangays b ON b.id = c.barangay_id
        ORDER BY c.registered_at DESC
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['has_issue']  = (int)($r['has_issue'] ?? 0);
            $r['signal_dbm'] = ($r['signal_dbm'] !== null && $r['signal_dbm'] !== '') ? (int)$r['signal_dbm'] : null;
            $r['x']          = (float)($r['x'] ?? 0);
            $r['y']          = (float)($r['y'] ?? 0);
            $customers[]     = $r;
        }
    }
} catch (Exception $e) {}

echo json_encode([
    "success"   => true,
    "barangays" => $barangays,
    "cabinets"  => $cabinets,
    "customers" => $customers
]);
?>
