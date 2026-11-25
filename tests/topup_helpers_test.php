<?php
require_once __DIR__ . '/../php/topup_helpers.php';

$tests = 0;
function assert_same($expected, $actual, string $name): void
{
    global $tests;
    $tests++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $name\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function assert_true(bool $condition, string $name): void
{
    assert_same(true, $condition, $name);
}

assert_same('50.00', normalize_topup_amount('50'), 'format whole peso amount');
assert_same('50.50', normalize_topup_amount('50.5'), 'format decimal amount');
assert_same('5000.00', normalize_topup_amount('5000'), 'accept maximum amount');
assert_same(null, normalize_topup_amount('19.99'), 'reject amount below minimum');
assert_same(null, normalize_topup_amount('5000.01'), 'reject amount above maximum');
assert_same(null, normalize_topup_amount('50.999'), 'reject more than two decimal places');
assert_same(null, normalize_topup_amount('-50'), 'reject negative amount');
assert_same(null, normalize_topup_amount('1e3'), 'reject scientific notation');
assert_same(true, is_demo_payment_method('gcash'), 'accept GCash demo method');
assert_same(true, is_demo_payment_method('bank'), 'accept bank demo method');
assert_same(false, is_demo_payment_method('card'), 'reject unsupported payment method');
assert_true((bool) preg_match('/^S2S-TP-\d{6}-[A-F0-9]{16}$/', generate_topup_reference()), 'generate a demo top-up reference');

fwrite(STDOUT, "PASS: $tests top-up helper tests\n");
