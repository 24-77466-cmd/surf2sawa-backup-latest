<?php
/** Finalize a registration after phone verification succeeds. */
function complete_verified_registration(mysqli $conn, array $data): array
{
    $fullname = trim((string) ($data['fullname'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone_e164'] ?? ''));
    $passwordHash = (string) ($data['password_hash'] ?? '');
    $address = trim((string) ($data['address'] ?? ''));
    $regionName = trim((string) ($data['region'] ?? ''));
    $provinceName = trim((string) ($data['province'] ?? ''));
    $cityName = trim((string) ($data['city'] ?? ''));
    $barangayName = trim((string) ($data['barangay_name'] ?? ''));
    $barangayCode = trim((string) ($data['barangay_code'] ?? ''));
    $totpSecret = trim((string) ($data['totp_secret'] ?? ''));

    if (!$fullname || !$email || !$phone || !$passwordHash || !$address || !$regionName || !$cityName || !$barangayName || !$barangayCode || !$totpSecret) {
        throw new RuntimeException('Registration information is incomplete.');
    }

    $check = $conn->prepare('SELECT id FROM users WHERE email = ? OR phone_e164 = ? LIMIT 1');
    $check->bind_param('ss', $email, $phone);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        throw new RuntimeException('The email or phone number is already registered.');
    }

    $brgyLookupKey = $barangayCode;
    $brgyId = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($brgyLookupKey)), 0, 30);
    if (!$brgyId) {
        $brgyId = 'brgy_' . substr(md5($barangayName . $cityName), 0, 10);
    }

    $hash = crc32($regionName . $cityName . $barangayName);
    $baseX = 180.0 + (float) (abs($hash % 520));
    $baseY = 90.0 + (float) (abs(($hash >> 5) % 380));
    $zonePrefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $cityName ?: 'PH'), 0, 3));
    $zoneSuffix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $barangayName ?: 'ZN'), 0, 2));
    $zoneCode = ($zonePrefix ?: 'S2S') . '-' . ($zoneSuffix ?: '01');

    $bStmt = $conn->prepare('SELECT id, name, city, region, x, y, zone FROM barangays WHERE id = ? OR (name = ? AND city = ?) LIMIT 1');
    $bStmt->bind_param('sss', $brgyId, $barangayName, $cityName);
    $bStmt->execute();
    $brgy = $bStmt->get_result()->fetch_assoc();

    if (!$brgy) {
        $insB = $conn->prepare('INSERT INTO barangays (id, name, city, region, x, y, zone) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insB->bind_param('ssssdds', $brgyId, $barangayName, $cityName, $regionName, $baseX, $baseY, $zoneCode);
        $insB->execute();
        $brgy = [
            'id' => $brgyId, 'name' => $barangayName, 'city' => $cityName,
            'region' => $regionName, 'x' => $baseX, 'y' => $baseY, 'zone' => $zoneCode
        ];
    } else {
        $brgyId = $brgy['id'];
    }

    $cStmt = $conn->prepare('SELECT id, name, x, y, zone FROM cabinets WHERE barangay_id = ? LIMIT 1');
    $cStmt->bind_param('s', $brgyId);
    $cStmt->execute();
    $cabinet = $cStmt->get_result()->fetch_assoc();

    if (!$cabinet) {
        $cabId = 'CAB-' . strtoupper(substr(md5($brgyId), 0, 5));
        $cabName = substr($brgy['name'] . ' Cabinet', 0, 60);
        $cabX = round((float) $brgy['x'] + 12.0, 1);
        $cabY = round((float) $brgy['y'] + 8.0, 1);
        $cabZone = $brgy['zone'];
        $insC = $conn->prepare('INSERT INTO cabinets (id, name, barangay_id, x, y, zone) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        $insC->bind_param('sssdds', $cabId, $cabName, $brgyId, $cabX, $cabY, $cabZone);
        $insC->execute();
        $cabinet = ['id' => $cabId, 'name' => $cabName, 'x' => $cabX, 'y' => $cabY, 'zone' => $cabZone];
    }

    $stmt = $conn->prepare("INSERT INTO users (fullname, email, phone_e164, phone_verified, totp_secret, password, role, balance) VALUES (?, ?, ?, 1, ?, ?, 'user', 0.00)");
    $stmt->bind_param('sssss', $fullname, $email, $phone, $totpSecret, $passwordHash);
    $stmt->execute();
    $userId = (int) $conn->insert_id;

    $addrHash = crc32($address . $brgyId);
    $angle = (abs($addrHash) % 6283) / 1000.0;
    $dist = 14.0 + ((abs($addrHash >> 8) % 360) / 10.0);
    $cabX = (float) $cabinet['x'];
    $cabY = (float) $cabinet['y'];
    $x = max(176.0, round($cabX + cos($angle) * $dist, 1));
    $y = round($cabY + sin($angle) * $dist, 1);

    // Customer codes must stay unique even when seeded/demo customers already
    // occupy the S2S-102xx range that this user's ID would map to.
    $maxRow = $conn->query("SELECT MAX(CAST(SUBSTRING(customer_code, 5) AS UNSIGNED)) AS m FROM customers WHERE customer_code LIKE 'S2S-%'")->fetch_assoc();
    $codeNum = max(10200 + $userId, (int) ($maxRow['m'] ?? 0) + 1);
    $codeCheck = $conn->prepare('SELECT id FROM customers WHERE customer_code = ? LIMIT 1');
    do {
        $custCode = 'S2S-' . $codeNum;
        $codeCheck->bind_param('s', $custCode);
        $codeCheck->execute();
        $taken = $codeCheck->get_result()->num_rows > 0;
        $codeNum++;
    } while ($taken);
    $reqId = 'SR-' . (5000 + $userId);
    $zone = $cabinet['zone'] . ' · ' . $cabinet['id'];
    $cIns = $conn->prepare("INSERT INTO customers (
        user_id, customer_code, full_name, address, city, region, province,
        municipality, psgc_brgy_code, barangay_id, cabinet_id, zone, request_id,
        plan, status, has_issue, signal_dbm, x, y, registered_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'active', 0, -16, ?, ?, NOW())");
    $cIns->bind_param(
        'issssssssssssdd',
        $userId, $custCode, $fullname, $address, $cityName, $regionName,
        $provinceName, $cityName, $barangayCode, $brgyId, $cabinet['id'],
        $zone, $reqId, $x, $y
    );
    $cIns->execute();

    log_action($conn, 'NEW_REGISTRATION', $custCode, "Registered: $fullname at $address, {$brgy['name']}, $cityName");
    return ['customer_code' => $custCode, 'city' => $cityName, 'barangay' => $brgy['name']];
}
?>