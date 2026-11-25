<?php
/** Philippine mobile number normalization shared by registration and phone change. */

function normalize_ph_mobile_number($value)
{
    if (!is_string($value) && !is_numeric($value)) {
        return false;
    }

    $number = preg_replace('/[\s().-]/', '', trim((string) $value));
    if (preg_match('/^09\d{9}$/', $number)) {
        return '+63' . substr($number, 1);
    }
    if (preg_match('/^639\d{9}$/', $number)) {
        return '+' . $number;
    }
    if (preg_match('/^\+639\d{9}$/', $number)) {
        return $number;
    }

    return false;
}
?>
