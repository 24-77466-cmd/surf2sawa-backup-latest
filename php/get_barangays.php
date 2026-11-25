<?php
header("Content-Type: application/json");
include "db.php";

$res = $conn->query("SELECT id, name, city, region, zone FROM barangays ORDER BY city ASC, name ASC");
$barangays = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $barangays[] = $r;
    }
}

echo json_encode([
    "success"   => true,
    "barangays" => $barangays
]);
?>
