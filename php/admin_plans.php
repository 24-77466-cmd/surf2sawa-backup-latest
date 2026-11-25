<?php
header("Content-Type: application/json");
session_start();
include "db.php";
include "log_helper.php";
require_once __DIR__ . "/plan_image_helper.php";

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

if ($method === 'GET') {
    $q      = trim($_GET['q'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    $where = '';
    $params = [];
    $types = '';
    if ($q !== '') {
        $where = "WHERE name LIKE ? OR description LIKE ?";
        $like = "%$q%";
        $params = [$like, $like];
        $types = 'ss';
    }

    // Count
    $cSql = "SELECT COUNT(*) AS c FROM plans $where";
    $cStmt = $conn->prepare($cSql);
    if ($params) $cStmt->bind_param($types, ...$params);
    $cStmt->execute();
    $total = (int)$cStmt->get_result()->fetch_assoc()['c'];

    // Select
    $sql = "SELECT * FROM plans $where ORDER BY price ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types . 'ii', $params[0], $params[1], $limit, $offset);
    } else {
        $stmt->bind_param('ii', $limit, $offset);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $plans = [];
    while ($r = $res->fetch_assoc()) {
        $r['price'] = (float)$r['price'];
        $r['duration_hours'] = (int)$r['duration_hours'];
        $plans[] = $r;
    }

    echo json_encode([
        "success" => true,
        "plans"   => $plans,
        "total"   => $total,
        "page"    => $page,
        "pages"   => max(1, (int)ceil($total / $limit))
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true) ?: [];

if ($action === 'add') {
    $name  = trim($data['name'] ?? '');
    $desc  = trim($data['description'] ?? '');
    $price = (float)($data['price'] ?? 0);
    $hrs   = (int)($data['duration_hours'] ?? 1);
    $image = trim((string)($data['image'] ?? ''));

    if (!$name) {
        echo json_encode(["success" => false, "message" => "Plan name is required."]);
        exit;
    }
    if ($price <= 0) {
        echo json_encode(["success" => false, "message" => "Price must be greater than ₱0.00."]);
        exit;
    }
    if ($hrs < 1) {
        echo json_encode(["success" => false, "message" => "Duration must be at least 1 hour."]);
        exit;
    }
    if ($image !== '' && plan_image_absolute($image) === null) {
        echo json_encode(["success" => false, "message" => "Invalid picture. Please upload it again."]);
        exit;
    }
    if ($image === '') $image = null;

    $stmt = $conn->prepare("INSERT INTO plans (name, description, price, duration_hours, image) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdis", $name, $desc, $price, $hrs, $image);
    if ($stmt->execute()) {
        log_action($conn, 'ADD_PLAN', $name, "₱" . number_format($price, 2) . " · {$hrs}h");
        echo json_encode(["success" => true, "message" => "Plan added successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to add plan: " . $conn->error]);
    }
    exit;
}

if ($action === 'edit') {
    $id    = (int)($data['id'] ?? 0);
    $name  = trim($data['name'] ?? '');
    $desc  = trim($data['description'] ?? '');
    $price = (float)($data['price'] ?? 0);
    $hrs   = (int)($data['duration_hours'] ?? 1);

    if ($id <= 0 || !$name) {
        echo json_encode(["success" => false, "message" => "Plan name is required."]);
        exit;
    }
    if ($price <= 0) {
        echo json_encode(["success" => false, "message" => "Price must be greater than ₱0.00."]);
        exit;
    }
    if ($hrs < 1) {
        echo json_encode(["success" => false, "message" => "Duration must be at least 1 hour."]);
        exit;
    }

    $cur = $conn->prepare("SELECT image FROM plans WHERE id = ?");
    $cur->bind_param("i", $id);
    $cur->execute();
    $existing = $cur->get_result()->fetch_assoc();
    if (!$existing) {
        echo json_encode(["success" => false, "message" => "Plan not found."]);
        exit;
    }
    $oldImage = $existing['image'];
    // An explicit empty string removes the picture; an absent key keeps it.
    $image = array_key_exists('image', $data) ? trim((string)$data['image']) : (string)$oldImage;
    if ($image !== '' && plan_image_absolute($image) === null) {
        echo json_encode(["success" => false, "message" => "Invalid picture. Please upload it again."]);
        exit;
    }
    if ($image === '') $image = null;

    $stmt = $conn->prepare("UPDATE plans SET name=?, description=?, price=?, duration_hours=?, image=? WHERE id=?");
    $stmt->bind_param("ssdisi", $name, $desc, $price, $hrs, $image, $id);
    if ($stmt->execute()) {
        if ($oldImage !== null && $oldImage !== $image) {
            plan_image_delete($oldImage);
        }
        log_action($conn, 'EDIT_PLAN', "ID#$id", "Updated $name (₱" . number_format($price, 2) . ", {$hrs}h)");
        echo json_encode(["success" => true, "message" => "Plan updated successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to update plan: " . $conn->error]);
    }
    exit;
}

if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid plan ID."]);
        exit;
    }

    // Check if plan has purchase records
    $chk = $conn->prepare("SELECT COUNT(*) AS c FROM purchases WHERE plan_id = ?");
    $chk->bind_param("i", $id);
    $chk->execute();
    $pCount = (int)$chk->get_result()->fetch_assoc()['c'];

    if ($pCount > 0) {
        echo json_encode([
            "success" => false,
            "message" => "Cannot delete plan: $pCount existing purchase record(s) depend on it."
        ]);
        exit;
    }

    $imgStmt = $conn->prepare("SELECT image FROM plans WHERE id = ?");
    $imgStmt->bind_param("i", $id);
    $imgStmt->execute();
    $imgRow = $imgStmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare("DELETE FROM plans WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($imgRow) {
            plan_image_delete($imgRow['image']);
        }
        log_action($conn, 'DELETE_PLAN', "ID#$id", "Removed plan");
        echo json_encode(["success" => true, "message" => "Plan deleted successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to delete plan: " . $conn->error]);
    }
    exit;
}

echo json_encode(["success" => false, "message" => "Unknown action."]);
?>
