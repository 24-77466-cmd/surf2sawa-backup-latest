<?php
header("Content-Type: application/json");
session_start();
include "db.php";
include "log_helper.php";

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

/* ----- LIST with search + pagination ----- */
if ($method === 'GET' && $action === 'list') {
    $q      = trim($_GET['q'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    $where = '';
    $params = [];
    $types = '';
    if ($q !== '') {
        $where = "WHERE (fullname LIKE ? OR email LIKE ? OR CAST(id AS CHAR) LIKE ?)";
        $like = "%$q%";
        $params = [$like, $like, $like];
        $types = 'sss';
    }

    // Total count
    $countSql = "SELECT COUNT(*) AS c FROM users $where";
    $cStmt = $conn->prepare($countSql);
    if ($params) $cStmt->bind_param($types, ...$params);
    $cStmt->execute();
    $total = (int)$cStmt->get_result()->fetch_assoc()['c'];

    // Data query
    $sql = "SELECT id, fullname, email, balance, role, created_at
            FROM users $where ORDER BY id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types . 'ii', $params[0], $params[1], $params[2], $limit, $offset);
    } else {
        $stmt->bind_param('ii', $limit, $offset);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $users = [];
    while ($r = $res->fetch_assoc()) {
        $r['balance'] = (float)$r['balance'];
        $users[] = $r;
    }

    echo json_encode([
        "success" => true,
        "users"   => $users,
        "total"   => $total,
        "page"    => $page,
        "pages"   => max(1, (int)ceil($total / $limit))
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true) ?: [];

/* ----- ADD ----- */
if ($action === 'add') {
    $name     = trim($data['fullname'] ?? '');
    $email    = trim($data['email'] ?? '');
    $pass     = $data['password'] ?? '';
    $role     = in_array($data['role'] ?? '', ['user', 'admin']) ? $data['role'] : 'user';
    $bal      = max(0, (float)($data['balance'] ?? 0));
    $address  = trim($data['address'] ?? '');
    $barangay = trim($data['barangay'] ?? '');

    if (!$name || !$email || !$pass) {
        echo json_encode(["success" => false, "message" => "Name, email, and password are required."]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["success" => false, "message" => "Please enter a valid email address."]);
        exit;
    }

    if (strlen($pass) < 6) {
        echo json_encode(["success" => false, "message" => "Password must be at least 6 characters."]);
        exit;
    }

    // Check duplicate email
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $chk->bind_param("s", $email);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "Email is already registered by another user."]);
        exit;
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, role, balance) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssd", $name, $email, $hash, $role, $bal);

    if ($stmt->execute()) {
        $userId = $conn->insert_id;
        log_action($conn, 'ADD_USER', $email, "Created $role account for $name");

        // If location details provided, add to customer map as well
        $regName   = trim($data['region'] ?? '');
        $provName  = trim($data['province'] ?? '');
        $cityName  = trim($data['city'] ?? '');
        $brgyName  = trim($data['barangay_name'] ?? '');
        $brgyCode  = trim($data['barangay_code'] ?? '');

        if (!$brgyName && $barangay) $brgyName = $barangay;
        if (!$cityName) $cityName = $provName ?: 'Metro Manila';
        if (!$regName)  $regName  = 'National Capital Region';

        if ($address && ($brgyName || $barangay)) {
            $brgyId = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($brgyCode ?: ($barangay ?: $brgyName))), 0, 30);
            if (!$brgyId) $brgyId = 'brgy_' . substr(md5($brgyName . $cityName), 0, 10);

            $bStmt = $conn->prepare("SELECT id, name, city, region, x, y, zone FROM barangays WHERE id = ? OR (name = ? AND city = ?) LIMIT 1");
            $bStmt->bind_param("sss", $brgyId, $brgyName, $cityName);
            $bStmt->execute();
            $brgy = $bStmt->get_result()->fetch_assoc();

            if (!$brgy) {
                $h = crc32($regName . $cityName . $brgyName);
                $baseX = 180.0 + (float)(abs($h % 520));
                $baseY = 90.0  + (float)(abs(($h >> 5) % 380));
                $zonePrefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $cityName ?: 'PH'), 0, 3));
                $zoneSuffix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $brgyName ?: 'ZN'), 0, 2));
                $zoneCode   = ($zonePrefix ?: 'S2S') . '-' . ($zoneSuffix ?: '01');

                $insB = $conn->prepare("INSERT INTO barangays (id, name, city, region, x, y, zone) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insB->bind_param("ssssdds", $brgyId, $brgyName, $cityName, $regName, $baseX, $baseY, $zoneCode);
                $insB->execute();
                $brgy = [
                    'id'     => $brgyId,
                    'name'   => $brgyName,
                    'city'   => $cityName,
                    'region' => $regName,
                    'x'      => $baseX,
                    'y'      => $baseY,
                    'zone'   => $zoneCode
                ];
            } else {
                $brgyId = $brgy['id'];
            }

            $cStmt = $conn->prepare("SELECT id, name, x, y, zone FROM cabinets WHERE barangay_id = ? LIMIT 1");
            $cStmt->bind_param("s", $brgyId);
            $cStmt->execute();
            $cabinet = $cStmt->get_result()->fetch_assoc();

            if (!$cabinet) {
                $cabId   = 'CAB-' . strtoupper(substr(md5($brgyId), 0, 5));
                $cabName = substr($brgy['name'] . ' Cabinet', 0, 60);
                $cabX    = round($brgy['x'] + 12.0, 1);
                $cabY    = round($brgy['y'] + 8.0, 1);
                $insC    = $conn->prepare("INSERT INTO cabinets (id, name, barangay_id, x, y, zone) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name)");
                $insC->bind_param("sssdds", $cabId, $cabName, $brgyId, $cabX, $cabY, $brgy['zone']);
                $insC->execute();
                $cabinet = ['id' => $cabId, 'name' => $cabName, 'x' => $cabX, 'y' => $cabY, 'zone' => $brgy['zone']];
            }

            $h = crc32($address . $brgyId);
            $angle = (abs($h) % 6283) / 1000.0;
            $dist  = 14.0 + ((abs($h >> 8) % 360) / 10.0);
            $cabX  = (float)$cabinet['x'];
            $cabY  = (float)$cabinet['y'];
            $x     = max(176.0, round($cabX + cos($angle) * $dist, 1));
            $y     = round($cabY + sin($angle) * $dist, 1);

            $custCode = 'S2S-' . (10200 + $userId);
            $reqId    = 'SR-' . (5000 + $userId);
            $zone     = $cabinet['zone'] . ' · ' . $cabinet['id'];

            $cIns = $conn->prepare("
                INSERT INTO customers (
                    user_id, customer_code, full_name, address, city, region,
                    province, municipality, psgc_brgy_code,
                    barangay_id, cabinet_id, zone, request_id, plan, status,
                    has_issue, signal_dbm, x, y, registered_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, NULL, 'active',
                    0, -16, ?, ?, NOW()
                )
            ");
            $cIns->bind_param(
                "issssssssssssdd",
                $userId, $custCode, $name, $address, $brgy['city'], $brgy['region'],
                $provName, $brgy['city'], $brgyCode,
                $brgy['id'], $cabinet['id'], $zone, $reqId, $x, $y
            );
            $cIns->execute();
        }

        echo json_encode(["success" => true, "message" => "User added successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to add user: " . $conn->error]);
    }
    exit;
}

/* ----- EDIT ----- */
if ($action === 'edit') {
    $id    = (int)($data['id'] ?? 0);
    $name  = trim($data['fullname'] ?? '');
    $email = trim($data['email'] ?? '');
    $pass  = $data['password'] ?? '';
    $role  = in_array($data['role'] ?? '', ['user', 'admin']) ? $data['role'] : 'user';
    $bal   = max(0, (float)($data['balance'] ?? 0));

    if ($id <= 0 || !$name || !$email) {
        echo json_encode(["success" => false, "message" => "ID, name, and email are required."]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["success" => false, "message" => "Please enter a valid email address."]);
        exit;
    }

    // Prevent admin from demoting themselves
    if ($id === (int)$_SESSION['user_id'] && $role !== 'admin') {
        echo json_encode(["success" => false, "message" => "You cannot change your own admin role."]);
        exit;
    }

    // Check if email taken by someone else
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk->bind_param("si", $email, $id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "Email is already taken by another account."]);
        exit;
    }

    if ($pass !== '') {
        if (strlen($pass) < 6) {
            echo json_encode(["success" => false, "message" => "Password must be at least 6 characters."]);
            exit;
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET fullname=?, email=?, password=?, role=?, balance=? WHERE id=?");
        $stmt->bind_param("ssssdi", $name, $email, $hash, $role, $bal, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET fullname=?, email=?, role=?, balance=? WHERE id=?");
        $stmt->bind_param("sssdi", $name, $email, $role, $bal, $id);
    }

    if ($stmt->execute()) {
        log_action($conn, 'EDIT_USER', "ID#$id", "Updated $email ($role)");
        echo json_encode(["success" => true, "message" => "User updated successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to update user: " . $conn->error]);
    }
    exit;
}

/* ----- DELETE ----- */
if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid user ID"]);
        exit;
    }
    if ($id === (int)$_SESSION['user_id']) {
        echo json_encode(["success" => false, "message" => "You cannot delete your own account."]);
        exit;
    }

    // Delete associated purchases
    $delSub = $conn->prepare("DELETE FROM purchases WHERE user_id = ?");
    if ($delSub) {
        $delSub->bind_param("i", $id);
        $delSub->execute();
    }

    // Delete associated customer record if any
    $delCust = $conn->prepare("DELETE FROM customers WHERE user_id = ?");
    if ($delCust) {
        $delCust->bind_param("i", $id);
        $delCust->execute();
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        log_action($conn, 'DELETE_USER', "ID#$id", "Removed user and location");
        echo json_encode(["success" => true, "message" => "User deleted successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to delete user: " . $conn->error]);
    }
    exit;
}

echo json_encode(["success" => false, "message" => "Unknown action."]);
?>
