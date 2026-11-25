<?php
// End-to-end test for the simulated wallet top-up flow. No payment is made.
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/totp.php';

$baseUrl = rtrim((string) (getenv('S2S_TEST_BASE_URL') ?: 'http://localhost/surf-to-sawa'), '/');
$suffix = bin2hex(random_bytes(5));
$userEmail = 'topup-user-' . $suffix . '@example.invalid';
$adminEmail = 'topup-admin-' . $suffix . '@example.invalid';
$password = 'TestTopupPass123';
$userCookie = tempnam(__DIR__, 's2s-topup-user-');
$adminCookie = tempnam(__DIR__, 's2s-topup-admin-');

function topup_api_request(string $url, ?array $payload, string $cookieFile): array
{
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 20
    ];
    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($curl, $options);
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

function topup_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Complete the Google Authenticator step after a password login. */
function topup_complete_login(mysqli $conn, array $login, int $userId, string $cookieFile): void
{
    if (!empty($login['totp_setup_required'])) {
        $secret = (string) ($login['totp_secret'] ?? '');
    } elseif (!empty($login['totp_required'])) {
        $stmt = $conn->prepare('SELECT totp_secret FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $secret = (string) ($stmt->get_result()->fetch_assoc()['totp_secret'] ?? '');
    } else {
        return;
    }
    topup_assert($secret !== '', 'login should provide or reference a TOTP secret');
    [$otpStatus, $otpVerified] = topup_api_request(
        $GLOBALS['baseUrl'] . '/php/verify_login_otp.php',
        ['code' => totp_current_code($secret)],
        $cookieFile
    );
    topup_assert($otpStatus === 200 && !empty($otpVerified['success']), 'authenticator code should complete the login');
}

try {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $user = $conn->prepare("INSERT INTO users (fullname,email,password,role,balance) VALUES ('Topup Test User',?,?, 'user',0.00)");
    $user->bind_param('ss', $userEmail, $passwordHash);
    $user->execute();
    $userId = (int) $conn->insert_id;

    $admin = $conn->prepare("INSERT INTO users (fullname,email,password,role,balance) VALUES ('Topup Test Admin',?,?, 'admin',0.00)");
    $admin->bind_param('ss', $adminEmail, $passwordHash);
    $admin->execute();
    $adminId = (int) $conn->insert_id;

    [$userLoginStatus, $userLogin] = topup_api_request($baseUrl . '/php/login.php', ['email' => $userEmail, 'password' => $password], $userCookie);
    topup_assert($userLoginStatus === 200 && !empty($userLogin['success']), 'test user should log in');
    topup_complete_login($conn, $userLogin, $userId, $userCookie);
    [$adminLoginStatus, $adminLogin] = topup_api_request($baseUrl . '/php/login.php', ['email' => $adminEmail, 'password' => $password], $adminCookie);
    topup_assert($adminLoginStatus === 200 && !empty($adminLogin['success']), 'test admin should log in');
    topup_complete_login($conn, $adminLogin, $adminId, $adminCookie);

    [$badAmountStatus, $badAmount] = topup_api_request($baseUrl . '/php/create_topup_request.php', ['amount' => '10', 'method' => 'gcash'], $userCookie);
    topup_assert($badAmountStatus === 400 && empty($badAmount['success']), 'invalid amount should be rejected');
    [$badMethodStatus, $badMethod] = topup_api_request($baseUrl . '/php/create_topup_request.php', ['amount' => '50', 'method' => 'card'], $userCookie);
    topup_assert($badMethodStatus === 400 && empty($badMethod['success']), 'unsupported method should be rejected');

    [$createStatus, $created] = topup_api_request($baseUrl . '/php/create_topup_request.php', ['amount' => '50.50', 'method' => 'gcash'], $userCookie);
    topup_assert($createStatus === 200 && !empty($created['success']), 'valid GCash demo request should be created');
    $requestId = (int) $created['request']['id'];
    $reference = (string) $created['request']['reference'];
    topup_assert(($created['request']['status'] ?? '') === 'pending', 'request should start pending');

    $balance = $conn->prepare('SELECT balance FROM users WHERE id = ?');
    $balance->bind_param('i', $userId);
    $balance->execute();
    topup_assert((float) $balance->get_result()->fetch_assoc()['balance'] === 0.0, 'pending request must not credit balance');

    [$historyStatus, $history] = topup_api_request($baseUrl . '/php/get_topup_requests.php', null, $userCookie);
    topup_assert($historyStatus === 200 && count($history['requests'] ?? []) === 1, 'user should see their own request history');
    [$adminListStatus, $adminList] = topup_api_request($baseUrl . '/php/admin_topup_requests.php', null, $adminCookie);
    topup_assert($adminListStatus === 200 && count($adminList['requests'] ?? []) >= 1, 'admin should see pending requests');

    [$approveStatus, $approved] = topup_api_request($baseUrl . '/php/admin_review_topup.php', ['request_id' => $requestId, 'decision' => 'approve'], $adminCookie);
    topup_assert($approveStatus === 200 && !empty($approved['success']), 'admin should approve a pending request');
    [$repeatStatus, $repeat] = topup_api_request($baseUrl . '/php/admin_review_topup.php', ['request_id' => $requestId, 'decision' => 'approve'], $adminCookie);
    topup_assert($repeatStatus === 409 && empty($repeat['success']), 'request must not be approved twice');

    $balance->execute();
    topup_assert((float) $balance->get_result()->fetch_assoc()['balance'] === 50.5, 'approval should credit the balance exactly once');

    [$secondStatus, $second] = topup_api_request($baseUrl . '/php/create_topup_request.php', ['amount' => '100', 'method' => 'bank'], $userCookie);
    topup_assert($secondStatus === 200 && !empty($second['success']), 'valid bank demo request should be created');
    [$rejectStatus, $rejected] = topup_api_request($baseUrl . '/php/admin_review_topup.php', ['request_id' => (int) $second['request']['id'], 'decision' => 'reject'], $adminCookie);
    topup_assert($rejectStatus === 200 && !empty($rejected['success']), 'admin should reject a pending request');
    $balance->execute();
    topup_assert((float) $balance->get_result()->fetch_assoc()['balance'] === 50.5, 'rejection must not credit the balance');

    echo "PASS: simulated GCash/bank requests, admin review, and exactly-once balance credit.\n";
} finally {
    if (isset($userId)) {
        $refs = $conn->prepare('SELECT reference FROM topup_requests WHERE user_id = ?');
        $refs->bind_param('i', $userId);
        $refs->execute();
        $rows = $refs->get_result();
        while ($row = $rows->fetch_assoc()) {
            $log = $conn->prepare('DELETE FROM activity_logs WHERE target = ?');
            $log->bind_param('s', $row['reference']);
            $log->execute();
        }
        $remove = $conn->prepare('DELETE FROM users WHERE id IN (?, ?)');
        $remove->bind_param('ii', $userId, $adminId);
        $remove->execute();
    }
    if (is_file($userCookie)) unlink($userCookie);
    if (is_file($adminCookie)) unlink($adminCookie);
}
?>