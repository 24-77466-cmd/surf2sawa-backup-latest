<?php
// End-to-end test: registration, login, and phone change all verified with
// Google Authenticator (TOTP) codes. Run against a local PHP server.
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/totp.php';

$baseUrl = rtrim((string) (getenv('S2S_TEST_BASE_URL') ?: 'http://127.0.0.1:8082'), '/');
$email = 'totp-test-' . bin2hex(random_bytes(5)) . '@example.invalid';
$phone = '0917' . (string) random_int(1000000, 9999999);
$newPhone = '0918' . (string) random_int(1000000, 9999999);
$cookieFile = tempnam(__DIR__, 's2s-totp-cookie-');

function api_post_json(string $url, array $payload, string $cookieFile): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 20
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $message);
    }
    curl_close($curl);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Endpoint returned non-JSON (HTTP ' . $status . ').');
    }
    return [$status, $json];
}

function api_get_json(string $url, string $cookieFile): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 20
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $message);
    }
    curl_close($curl);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Endpoint returned non-JSON (HTTP ' . $status . ').');
    }
    return [$status, $json];
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** A 6-digit code that is NOT valid for the secret within the drift window. */
function wrong_totp_code(string $secret): string
{
    $slice = intdiv(time(), 30);
    $valid = [
        totp_code_at($secret, $slice - 1),
        totp_code_at($secret, $slice),
        totp_code_at($secret, $slice + 1)
    ];
    foreach (['000000', '000001', '111111', '222222', '333333', '444444'] as $candidate) {
        if (!in_array($candidate, $valid, true)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not pick an invalid test code.');
}

try {
    // ── Registration: QR enrollment, account stays pending until code verified ──
    $registerPayload = [
        'fullname' => 'TOTP Integration Test',
        'email' => $email,
        'phone' => $phone,
        'password' => 'TestPass123',
        'address' => '24 Test Street',
        'region' => 'CALABARZON',
        'province' => 'Batangas',
        'city' => 'Balayan',
        'barangay_name' => 'Gumamela',
        'barangay_code' => '041003029'
    ];
    [$status, $registered] = api_post_json($baseUrl . '/php/register.php', $registerPayload, $cookieFile);
    check($status === 200 && !empty($registered['success']), 'registration should create a pending authenticator enrollment');
    check(($registered['delivery_mode'] ?? '') === 'totp', 'registration must use Google Authenticator delivery');
    $secret = (string) ($registered['totp_secret'] ?? '');
    check((bool) preg_match('/^[A-Z2-7]{32}$/', $secret), 'registration should return a Base32 TOTP secret');
    check(!empty($registered['otpauth_uri']) && str_starts_with($registered['otpauth_uri'], 'otpauth://totp/'), 'registration should return an otpauth QR URI');

    [$resumeStatus, $resume] = api_get_json($baseUrl . '/php/registration_status.php', $cookieFile);
    check($resumeStatus === 200 && !empty($resume['pending']) && !empty($resume['otpauth_uri']), 'pending enrollment should be restorable after reload');

    $query = $conn->prepare('SELECT id FROM users WHERE email = ?');
    $query->bind_param('s', $email);
    $query->execute();
    check($query->get_result()->num_rows === 0, 'account must not exist before the authenticator code is verified');

    [$badStatus, $badVerify] = api_post_json($baseUrl . '/php/verify_registration.php', ['code' => wrong_totp_code($secret)], $cookieFile);
    check($badStatus === 400 && empty($badVerify['success']), 'wrong authenticator code should be rejected');

    [$verifyStatus, $verified] = api_post_json($baseUrl . '/php/verify_registration.php', ['code' => totp_current_code($secret)], $cookieFile);
    check($verifyStatus === 200 && !empty($verified['success']), 'correct authenticator code should finalize registration');

    $query = $conn->prepare('SELECT id, phone_e164, phone_verified, totp_secret FROM users WHERE email = ?');
    $query->bind_param('s', $email);
    $query->execute();
    $user = $query->get_result()->fetch_assoc();
    check($user && $user['phone_e164'] === '+63' . substr($phone, 1) && (int) $user['phone_verified'] === 1, 'verified phone should be stored on the new account');
    check($user['totp_secret'] === $secret, 'confirmed TOTP secret should be stored on the account');

    // ── Regular users log in with password only (no authenticator code) ──
    [$loginStatus, $login] = api_post_json($baseUrl . '/php/login.php', ['email' => $email, 'password' => 'TestPass123'], $cookieFile);
    check($loginStatus === 200 && !empty($login['success']) && empty($login['totp_required']) && empty($login['totp_setup_required']) && !empty($login['user']), 'regular user login should succeed without an authenticator code');

    [$sessionStatus, $session] = api_get_json($baseUrl . '/php/get_session.php', $cookieFile);
    check(!empty($session['success']) && ($session['user']['phone'] ?? '') === '+63' . substr($phone, 1) && !empty($session['user']['totp_enabled']), 'session should expose the phone and authenticator status');

    // ── Phone change requires an authenticator code ──
    [$sameStatus, $same] = api_post_json($baseUrl . '/php/request_phone_change.php', ['phone' => $phone], $cookieFile);
    check($sameStatus === 400 && empty($same['success']), 'phone change should reject the current number');

    [$reqStatus, $requested] = api_post_json($baseUrl . '/php/request_phone_change.php', ['phone' => $newPhone], $cookieFile);
    check($reqStatus === 200 && !empty($requested['success']) && !empty($requested['totp_required']), 'phone change should ask for an authenticator code');

    [$badChangeStatus, $badChange] = api_post_json($baseUrl . '/php/verify_phone_change.php', ['code' => wrong_totp_code($secret)], $cookieFile);
    check($badChangeStatus === 400 && empty($badChange['success']), 'wrong phone-change code should be rejected');

    [$changeStatus, $changed] = api_post_json($baseUrl . '/php/verify_phone_change.php', ['code' => totp_current_code($secret)], $cookieFile);
    check($changeStatus === 200 && !empty($changed['success']), 'correct phone-change code should update the number');

    $query = $conn->prepare('SELECT phone_e164, phone_verified FROM users WHERE email = ?');
    $query->bind_param('s', $email);
    $query->execute();
    $user = $query->get_result()->fetch_assoc();
    check($user && $user['phone_e164'] === '+63' . substr($newPhone, 1) && (int) $user['phone_verified'] === 1, 'new phone should be stored and verified');

    // ── Admin accounts get a FRESH QR code at every login ──
    $promote = $conn->prepare("UPDATE users SET role = 'admin' WHERE email = ?");
    $promote->bind_param('s', $email);
    $promote->execute();
    $adminCookie = tempnam(__DIR__, 's2s-totp-admin-');
    [$adminLoginStatus, $adminLogin] = api_post_json($baseUrl . '/php/login.php', ['email' => $email, 'password' => 'TestPass123'], $adminCookie);
    check($adminLoginStatus === 200 && !empty($adminLogin['success']) && !empty($adminLogin['totp_setup_required']) && !empty($adminLogin['otpauth_uri']), 'admin login should generate a fresh QR code every time');
    $adminSecret = (string) ($adminLogin['totp_secret'] ?? '');
    check($adminSecret !== '' && $adminSecret !== $secret, 'each admin login must issue a new secret, even when one is already stored');

    [$adminSessionStatus, $adminSession] = api_get_json($baseUrl . '/php/get_session.php', $adminCookie);
    check(empty($adminSession['success']), 'admin session must not be established before the code is verified');

    [$badAdminStatus, $badAdmin] = api_post_json($baseUrl . '/php/verify_login_otp.php', ['code' => wrong_totp_code($adminSecret)], $adminCookie);
    check($badAdminStatus === 400 && empty($badAdmin['success']), 'wrong admin login code should be rejected');

    [$okAdminStatus, $okAdmin] = api_post_json($baseUrl . '/php/verify_login_otp.php', ['code' => totp_current_code($adminSecret)], $adminCookie);
    check($okAdminStatus === 200 && !empty($okAdmin['success']) && ($okAdmin['user']['role'] ?? '') === 'admin', 'correct admin code should establish the admin session');

    $adminQuery = $conn->prepare('SELECT totp_secret FROM users WHERE email = ?');
    $adminQuery->bind_param('s', $email);
    $adminQuery->execute();
    $adminRow = $adminQuery->get_result()->fetch_assoc();
    check($adminRow['totp_secret'] === $adminSecret, 'the freshly confirmed secret should replace the stored one');

    // ── Legacy account without an authenticator enrolls during phone change ──
    $legacyEmail = 'totp-legacy-' . bin2hex(random_bytes(5)) . '@example.invalid';
    $legacyPhone = '0919' . (string) random_int(1000000, 9999999);
    $legacyNewPhone = '0920' . (string) random_int(1000000, 9999999);
    $legacyHash = password_hash('TestPass123', PASSWORD_DEFAULT);
    $legacyE164 = '+63' . substr($legacyPhone, 1);
    $insertLegacy = $conn->prepare("INSERT INTO users (fullname, email, phone_e164, phone_verified, password, role, balance) VALUES ('Legacy TOTP Test', ?, ?, 1, ?, 'user', 0.00)");
    $insertLegacy->bind_param('sss', $legacyEmail, $legacyE164, $legacyHash);
    $insertLegacy->execute();
    $legacyCookie = tempnam(__DIR__, 's2s-totp-legacy-');

    [$legacyLoginStatus, $legacyLogin] = api_post_json($baseUrl . '/php/login.php', ['email' => $legacyEmail, 'password' => 'TestPass123'], $legacyCookie);
    check($legacyLoginStatus === 200 && !empty($legacyLogin['success']) && empty($legacyLogin['totp_required']), 'legacy user should log in without an authenticator');

    [$legacyReqStatus, $legacyReq] = api_post_json($baseUrl . '/php/request_phone_change.php', ['phone' => $legacyNewPhone], $legacyCookie);
    check($legacyReqStatus === 200 && !empty($legacyReq['totp_setup_required']) && !empty($legacyReq['otpauth_uri']), 'phone change should offer QR enrollment for accounts without an authenticator');
    $legacySecret = (string) ($legacyReq['totp_secret'] ?? '');

    [$legacyBadStatus, $legacyBad] = api_post_json($baseUrl . '/php/verify_phone_change.php', ['code' => wrong_totp_code($legacySecret)], $legacyCookie);
    check($legacyBadStatus === 400 && empty($legacyBad['success']), 'wrong enrollment code should be rejected');

    [$legacyOkStatus, $legacyOk] = api_post_json($baseUrl . '/php/verify_phone_change.php', ['code' => totp_current_code($legacySecret)], $legacyCookie);
    check($legacyOkStatus === 200 && !empty($legacyOk['success']), 'confirmed enrollment code should apply the phone change');

    $legacyQuery = $conn->prepare('SELECT phone_e164, totp_secret FROM users WHERE email = ?');
    $legacyQuery->bind_param('s', $legacyEmail);
    $legacyQuery->execute();
    $legacyUser = $legacyQuery->get_result()->fetch_assoc();
    check($legacyUser && $legacyUser['phone_e164'] === '+63' . substr($legacyNewPhone, 1) && $legacyUser['totp_secret'] === $legacySecret, 'enrollment secret and new phone should be stored on the legacy account');

    echo "PASS: password-only user login; authenticator required for registration, phone change, and admin login; legacy accounts enroll during phone change.\n";
} finally {
    $query = $conn->prepare('SELECT id FROM users WHERE email = ?');
    $query->bind_param('s', $email);
    $query->execute();
    $user = $query->get_result()->fetch_assoc();
    if ($user) {
        $userId = (int) $user['id'];
        $customer = $conn->prepare('SELECT customer_code FROM customers WHERE user_id = ?');
        $customer->bind_param('i', $userId);
        $customer->execute();
        $customerResult = $customer->get_result();
        while ($row = $customerResult->fetch_assoc()) {
            $log = $conn->prepare('DELETE FROM activity_logs WHERE target = ?');
            $log->bind_param('s', $row['customer_code']);
            $log->execute();
        }
        $removeLogs = $conn->prepare('DELETE FROM activity_logs WHERE target = ?');
        $removeLogs->bind_param('s', $email);
        $removeLogs->execute();
        $removeCustomers = $conn->prepare('DELETE FROM customers WHERE user_id = ?');
        $removeCustomers->bind_param('i', $userId);
        $removeCustomers->execute();
        $removeUser = $conn->prepare('DELETE FROM users WHERE id = ?');
        $removeUser->bind_param('i', $userId);
        $removeUser->execute();
    }
    $pending = $conn->prepare('DELETE FROM pending_registrations WHERE email = ?');
    $pending->bind_param('s', $email);
    $pending->execute();
    if (isset($legacyEmail)) {
        $legacyLogs = $conn->prepare('DELETE FROM activity_logs WHERE target = ?');
        $legacyLogs->bind_param('s', $legacyEmail);
        $legacyLogs->execute();
        $removeLegacy = $conn->prepare('DELETE FROM users WHERE email = ?');
        $removeLegacy->bind_param('s', $legacyEmail);
        $removeLegacy->execute();
    }
    if (isset($adminCookie) && is_file($adminCookie)) unlink($adminCookie);
    if (isset($legacyCookie) && is_file($legacyCookie)) unlink($legacyCookie);
    if (is_file($cookieFile)) unlink($cookieFile);
}
?>