<?php
require_once __DIR__ . '/../php/totp.php';

$tests = 0;
function expect_same($expected, $actual, string $name): void {
    global $tests;
    $tests++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $name\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function expect_true(bool $actual, string $name): void {
    expect_same(true, $actual, $name);
}

// Base32 round-trip and RFC 4648 test vectors.
expect_same('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', base32_encode('12345678901234567890'), 'base32 encodes the RFC 6238 seed');
expect_same('12345678901234567890', base32_decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'), 'base32 decodes the RFC 6238 seed');
expect_same('', base32_decode('ABCD0189'), 'base32 rejects invalid characters (0, 1, 8, 9 are not in the alphabet)');
expect_same('foobar', base32_decode('mzxw6ytboi======'), 'base32 tolerates lowercase and padding from manual entry');
expect_same('foobar', base32_decode(base32_encode('foobar')), 'base32 round-trips arbitrary bytes');

// Generated secrets are 160-bit, Base32, and unique.
$secretA = totp_generate_secret();
$secretB = totp_generate_secret();
expect_same(32, strlen($secretA), 'generated secret is 32 Base32 characters');
expect_true((bool) preg_match('/^[A-Z2-7]{32}$/', $secretA), 'generated secret uses the Base32 alphabet');
expect_true($secretA !== $secretB, 'generated secrets are random');
expect_same(20, strlen(base32_decode($secretA)), 'generated secret decodes to 20 bytes');

// RFC 6238 SHA1 test vectors (6-digit truncation of the published 8-digit codes).
$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
expect_same('287082', totp_code_at($rfcSecret, intdiv(59, 30)), 'RFC 6238 vector T=59');
expect_same('081804', totp_code_at($rfcSecret, intdiv(1111111109, 30)), 'RFC 6238 vector T=1111111109');
expect_same('050471', totp_code_at($rfcSecret, intdiv(1111111111, 30)), 'RFC 6238 vector T=1111111111');
expect_same('005924', totp_code_at($rfcSecret, intdiv(1234567890, 30)), 'RFC 6238 vector T=1234567890');
expect_same('279037', totp_code_at($rfcSecret, intdiv(2000000000, 30)), 'RFC 6238 vector T=2000000000');
expect_same('353130', totp_code_at($rfcSecret, intdiv(20000000000, 30)), 'RFC 6238 vector T=20000000000');

// Verification accepts the current code and one step of drift, rejects anything else.
expect_true(totp_verify($secretA, totp_current_code($secretA)), 'current code verifies');
$nextCode = totp_code_at($secretA, intdiv(time(), 30) + 1);
expect_true(totp_verify($secretA, $nextCode), 'adjacent time step is accepted for clock drift');
expect_true(!totp_verify($secretA, $nextCode, 0), 'zero window rejects the adjacent step');
expect_true(!totp_verify($secretA, '000000') || totp_current_code($secretA) === '000000', 'wrong code is rejected');
expect_true(!totp_verify($secretA, '12345'), 'non-6-digit input is rejected');
expect_true(!totp_verify('', '123456'), 'empty secret is rejected');

// Enrollment URI and manual key formatting.
$uri = totp_uri($secretA, 'user@example.com');
expect_true(str_starts_with($uri, 'otpauth://totp/Surf%20to%20Sawa:user%40example.com?'), 'otpauth URI label encodes issuer and account');
expect_true(str_contains($uri, 'secret=' . $secretA), 'otpauth URI carries the secret');
expect_true(str_contains($uri, 'algorithm=SHA1&digits=6&period=30'), 'otpauth URI matches Google Authenticator defaults');
expect_same('ABCD EFGH IJKL MNOP QRST UVWX YZ23 4567', totp_display_secret('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'), 'manual key is grouped in fours');

fwrite(STDOUT, "PASS: $tests Google Authenticator (TOTP) tests\n");
?>