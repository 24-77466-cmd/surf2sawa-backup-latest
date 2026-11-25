<?php
session_start();
include "db.php";

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit("Forbidden: Admin access required.");
}

$type = $_GET['type'] ?? 'users';
$allowed = ['users', 'plans', 'purchases', 'logs', 'customers'];
if (!in_array($type, $allowed)) {
    http_response_code(400);
    exit("Invalid export type.");
}

// Helper to sanitize CSV formula injection
function sanitize_csv_row($row) {
    return array_map(function($val) {
        if (is_string($val) && strlen($val) > 0 && in_array($val[0], ['=', '+', '-', '@'])) {
            return "'" . $val;
        }
        return $val;
    }, $row);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel displays special characters (₱, ñ, emojis) properly
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

switch ($type) {
    case 'users':
        fputcsv($out, ['ID', 'Full Name', 'Email', 'Role', 'Balance (PHP)', 'Joined Date']);
        $res = $conn->query("SELECT id, fullname, email, role, balance, created_at FROM users ORDER BY id ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['balance'] = number_format((float)$r['balance'], 2, '.', '');
                fputcsv($out, sanitize_csv_row($r));
            }
        }
        break;

    case 'plans':
        fputcsv($out, ['ID', 'Plan Name', 'Description', 'Price (PHP)', 'Duration (Hours)']);
        $res = $conn->query("SELECT id, name, description, price, duration_hours FROM plans ORDER BY price ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['price'] = number_format((float)$r['price'], 2, '.', '');
                fputcsv($out, sanitize_csv_row($r));
            }
        }
        break;

    case 'purchases':
        fputcsv($out, ['Purchase ID', 'Customer Name', 'Email', 'Plan', 'Price (PHP)', 'Purchased At', 'Expires At', 'Status']);
        $res = $conn->query("
            SELECT pu.id,
                   COALESCE(u.fullname, '[Deleted User]') AS fullname,
                   COALESCE(u.email, '—') AS email,
                   COALESCE(p.name, '[Deleted Plan]') AS plan,
                   COALESCE(p.price, 0.00) AS price,
                   pu.purchased_at,
                   pu.expires_at,
                   CASE WHEN pu.expires_at > NOW() THEN 'active' ELSE 'expired' END AS status
            FROM purchases pu
            LEFT JOIN users u ON u.id = pu.user_id
            LEFT JOIN plans p ON p.id = pu.plan_id
            ORDER BY pu.purchased_at DESC
        ");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['price'] = number_format((float)$r['price'], 2, '.', '');
                fputcsv($out, sanitize_csv_row($r));
            }
        }
        break;

    case 'logs':
        fputcsv($out, ['Log ID', 'Admin Name', 'Action', 'Target', 'Detail', 'IP Address', 'Timestamp']);
        $res = $conn->query("SELECT id, admin_name, action, target, detail, ip, created_at FROM activity_logs ORDER BY created_at DESC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                fputcsv($out, sanitize_csv_row($r));
            }
        }
        break;

    case 'customers':
        fputcsv($out, ['Customer Code', 'Full Name', 'Address', 'City', 'Region', 'Zone', 'Status', 'Registered Date']);
        $res = $conn->query("SELECT customer_code, full_name, address, city, region, zone, status, registered_at FROM customers ORDER BY registered_at DESC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                fputcsv($out, sanitize_csv_row($r));
            }
        }
        break;
}

fclose($out);
exit;
?>
