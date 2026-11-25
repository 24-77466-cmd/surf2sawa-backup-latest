<?php
/** Helpers for demo-only wallet top-ups. */
function normalize_topup_amount($value): ?string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    $raw = trim((string) $value);
    if (!preg_match('/^\d{1,4}(?:\.\d{1,2})?$/', $raw)) {
        return null;
    }

    $parts = explode('.', $raw, 2);
    $whole = (int) $parts[0];
    $fraction = isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0;
    $cents = ($whole * 100) + $fraction;
    if ($cents < 2000 || $cents > 500000) {
        return null;
    }
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function is_demo_payment_method($method): bool
{
    return is_string($method) && in_array($method, ['gcash', 'bank'], true);
}

function generate_topup_reference(): string
{
    return 'S2S-TP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
}
?>