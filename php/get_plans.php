<?php
header("Content-Type: application/json");
include "db.php";

$plans = [];
$res = $conn->query("SELECT id, name, description, price, duration_hours, image FROM plans ORDER BY price ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['id']             = (int)$row['id'];
        $row['price']          = (float)$row['price'];
        $row['duration_hours'] = (int)$row['duration_hours'];
        $plans[] = $row;
    }
}

echo json_encode($plans);
?>
