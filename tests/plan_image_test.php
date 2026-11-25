<?php
// End-to-end test: admin-only plan picture upload, attach/replace/remove, cleanup.
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/totp.php';

$baseUrl = rtrim((string) (getenv('S2S_TEST_BASE_URL') ?: 'http://localhost/surf-to-sawa'), '/');
$suffix = bin2hex(random_bytes(5));
$adminEmail = 'planimg-admin-' . $suffix . '@example.invalid';
$userEmail = 'planimg-user-' . $suffix . '@example.invalid';
$password = 'TestPlanImgPass123';
$planName = 'Plan Image Test ' . $suffix;
$adminCookie = tempnam(__DIR__, 's2s-planimg-admin-');
$userCookie = tempnam(__DIR__, 's2s-planimg-user-');
$anonCookie = tempnam(__DIR__, 's2s-planimg-anon-');
$pngFile = tempnam(__DIR__, 's2s-planimg-png-');
$fakeFile = tempnam(__DIR__, 's2s-planimg-fake-');
$uploadedPaths = [];

function planimg_request(string $url, ?array $json, string $cookieFile, ?CURLFile $file = null): array
{
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 20
    ];
    if ($file !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = ['image' => $file];
    } elseif ($json !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($json);
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
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Endpoint returned non-JSON (HTTP ' . $status . ').');
    }
    return [$status, $decoded];
}

function planimg_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** File-existence assertion with a fresh stat cache (the server may have deleted the file). */
function planimg_file_exists(string $relative): bool
{
    clearstatcache(true);
    return is_file(__DIR__ . '/../' . $relative);
}

function planimg_upload(string $baseUrl, string $path, string $cookieFile): array
{
    return planimg_request($baseUrl . '/php/admin_upload_plan_image.php', null, $cookieFile, new CURLFile($path, 'image/png', 'picture.png'));
}

try {
    // 1x1 PNG and a fake "image" (plain text renamed to .png).
    file_put_contents($pngFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    file_put_contents($fakeFile, '<?php echo "not an image"; ?>');

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $adminSecret = totp_generate_secret();
    $admin = $conn->prepare("INSERT INTO users (fullname, email, password, role, balance, totp_secret) VALUES ('Plan Image Admin', ?, ?, 'admin', 0.00, ?)");
    $admin->bind_param('sss', $adminEmail, $passwordHash, $adminSecret);
    $admin->execute();
    $adminId = (int) $conn->insert_id;
    $user = $conn->prepare("INSERT INTO users (fullname, email, password, role, balance) VALUES ('Plan Image User', ?, ?, 'user', 0.00)");
    $user->bind_param('ss', $userEmail, $passwordHash);
    $user->execute();
    $userId = (int) $conn->insert_id;

    // ── Uploads are admin-only ──
    [$anonStatus, $anon] = planimg_upload($baseUrl, $pngFile, $anonCookie);
    planimg_check($anonStatus === 403 && empty($anon['success']), 'anonymous upload must be rejected');

    [$userLoginStatus, $userLogin] = planimg_request($baseUrl . '/php/login.php', ['email' => $userEmail, 'password' => $password], $userCookie);
    planimg_check($userLoginStatus === 200 && !empty($userLogin['success']), 'regular user should log in');
    [$userUpStatus, $userUp] = planimg_upload($baseUrl, $pngFile, $userCookie);
    planimg_check($userUpStatus === 403 && empty($userUp['success']), 'regular user upload must be rejected');

    // ── Admin login (fresh QR code every time) ──
    [$adminLoginStatus, $adminLogin] = planimg_request($baseUrl . '/php/login.php', ['email' => $adminEmail, 'password' => $password], $adminCookie);
    planimg_check($adminLoginStatus === 200 && !empty($adminLogin['totp_setup_required']) && !empty($adminLogin['totp_secret']), 'admin login should generate a fresh QR code');
    [$verifyStatus, $verify] = planimg_request($baseUrl . '/php/verify_login_otp.php', ['code' => totp_current_code((string) $adminLogin['totp_secret'])], $adminCookie);
    planimg_check($verifyStatus === 200 && !empty($verify['success']), 'admin should complete login');

    // ── Upload validation ──
    [$fakeStatus, $fake] = planimg_upload($baseUrl, $fakeFile, $adminCookie);
    planimg_check($fakeStatus === 400 && empty($fake['success']), 'a script renamed to .png must be rejected');

    [$upStatus, $up] = planimg_upload($baseUrl, $pngFile, $adminCookie);
    planimg_check($upStatus === 200 && !empty($up['success']), 'admin should upload a real picture');
    $image1 = (string) $up['image'];
    $uploadedPaths[] = $image1;
    planimg_check((bool) preg_match('#^uploads/plans/[a-f0-9]{32}\.png$#', $image1), 'upload should return a safe stored path');
    planimg_check(planimg_file_exists($image1), 'uploaded file should exist on disk');

    // ── Attach the picture to a new plan ──
    [$addStatus, $add] = planimg_request($baseUrl . '/php/admin_plans.php?action=add', [
        'name' => $planName, 'description' => 'Temporary test plan', 'price' => 99.0, 'duration_hours' => 24, 'image' => $image1
    ], $adminCookie);
    planimg_check($addStatus === 200 && !empty($add['success']), 'admin should create a plan with a picture');

    $find = $conn->prepare('SELECT id, image FROM plans WHERE name = ?');
    $find->bind_param('s', $planName);
    $find->execute();
    $plan = $find->get_result()->fetch_assoc();
    planimg_check($plan && $plan['image'] === $image1, 'plan should store the picture path');
    $planId = (int) $plan['id'];

    $public = json_decode(file_get_contents($baseUrl . '/php/get_plans.php'), true);
    $listed = null;
    foreach ((array) $public as $row) {
        if ((int) $row['id'] === $planId) $listed = $row;
    }
    planimg_check($listed && ($listed['image'] ?? '') === $image1, 'public plan list should expose the picture');

    // ── Replace the picture (old file must be removed) ──
    [$up2Status, $up2] = planimg_upload($baseUrl, $pngFile, $adminCookie);
    planimg_check($up2Status === 200 && !empty($up2['success']), 'second upload should succeed');
    $image2 = (string) $up2['image'];
    $uploadedPaths[] = $image2;
    [$editStatus, $edit] = planimg_request($baseUrl . '/php/admin_plans.php?action=edit', [
        'id' => $planId, 'name' => $planName, 'description' => 'Temporary test plan', 'price' => 99.0, 'duration_hours' => 24, 'image' => $image2
    ], $adminCookie);
    planimg_check($editStatus === 200 && !empty($edit['success']), 'admin should replace the picture');
    planimg_check(!planimg_file_exists($image1), 'replaced picture file should be deleted');

    // ── Remove the picture ──
    [$rmStatus, $rm] = planimg_request($baseUrl . '/php/admin_plans.php?action=edit', [
        'id' => $planId, 'name' => $planName, 'description' => 'Temporary test plan', 'price' => 99.0, 'duration_hours' => 24, 'image' => ''
    ], $adminCookie);
    planimg_check($rmStatus === 200 && !empty($rm['success']), 'admin should remove the picture');
    planimg_check(!planimg_file_exists($image2), 'removed picture file should be deleted');
    $find->execute();
    $plan = $find->get_result()->fetch_assoc();
    planimg_check($plan['image'] === null, 'picture path should be cleared in the database');

    // ── Deleting the plan deletes its picture ──
    [$up3Status, $up3] = planimg_upload($baseUrl, $pngFile, $adminCookie);
    $image3 = (string) $up3['image'];
    $uploadedPaths[] = $image3;
    planimg_request($baseUrl . '/php/admin_plans.php?action=edit', [
        'id' => $planId, 'name' => $planName, 'description' => 'Temporary test plan', 'price' => 99.0, 'duration_hours' => 24, 'image' => $image3
    ], $adminCookie);
    [$delStatus, $del] = planimg_request($baseUrl . '/php/admin_plans.php?action=delete', ['id' => $planId], $adminCookie);
    planimg_check($delStatus === 200 && !empty($del['success']), 'admin should delete the plan');
    planimg_check(!planimg_file_exists($image3), 'deleting a plan should delete its picture file');

    echo "PASS: plan pictures are admin-only, validated, attachable, replaceable, removable, and cleaned up.\n";
} finally {
    if (isset($planId)) {
        $removePlan = $conn->prepare('DELETE FROM plans WHERE id = ?');
        $removePlan->bind_param('i', $planId);
        $removePlan->execute();
    }
    $byName = $conn->prepare('DELETE FROM plans WHERE name = ?');
    $byName->bind_param('s', $planName);
    $byName->execute();
    if (isset($adminId, $userId)) {
        $logs = $conn->prepare('DELETE FROM activity_logs WHERE admin_id IN (?, ?)');
        $logs->bind_param('ii', $adminId, $userId);
        $logs->execute();
        $removeUsers = $conn->prepare('DELETE FROM users WHERE id IN (?, ?)');
        $removeUsers->bind_param('ii', $adminId, $userId);
        $removeUsers->execute();
    }
    foreach ($uploadedPaths as $path) {
        $absolute = __DIR__ . '/../' . $path;
        if (preg_match('#^uploads/plans/[a-f0-9]{32}\.(jpg|png|gif|webp)$#', $path) && planimg_file_exists($path)) {
            unlink($absolute);
        }
    }
    foreach ([$adminCookie, $userCookie, $anonCookie, $pngFile, $fakeFile] as $tmp) {
        if (is_file($tmp)) unlink($tmp);
    }
}
?>