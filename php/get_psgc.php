<?php
/**
 * PSGC API Proxy with 24-hour file cache
 * Endpoints:
 *   ?type=regions
 *   ?type=provinces&region=CODE
 *   ?type=cities&province=CODE
 *   ?type=municipalities&province=CODE
 *   ?type=barangays&city=CODE
 *   ?type=barangays&mun=CODE
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$type   = preg_replace('/[^a-z]/', '', strtolower($_GET['type'] ?? ''));
$region = preg_replace('/[^0-9]/', '', $_GET['region'] ?? '');
$prov   = preg_replace('/[^0-9]/', '', $_GET['province'] ?? '');
$city   = preg_replace('/[^0-9]/', '', $_GET['city'] ?? '');
$mun    = preg_replace('/[^0-9]/', '', $_GET['mun'] ?? '');

$BASE = "https://psgc.gitlab.io/api";

// Build URL & cache key
switch ($type) {
    case 'regions':
        $url      = "$BASE/regions/";
        $cacheKey = "regions";
        break;
    case 'provinces':
        if (!$region) { echo json_encode(["error" => "region code required"]); exit; }
        $url      = "$BASE/regions/$region/provinces/";
        $cacheKey = "provinces_$region";
        break;
    case 'cities':
        if ($prov) {
            $url      = "$BASE/provinces/$prov/cities/";
            $cacheKey = "cities_$prov";
        } elseif ($region) {
            $url      = "$BASE/regions/$region/cities/";
            $cacheKey = "cities_reg_$region";
        } else {
            echo json_encode(["error" => "province or region code required"]); exit;
        }
        break;
    case 'municipalities':
        if ($prov) {
            $url      = "$BASE/provinces/$prov/municipalities/";
            $cacheKey = "muns_$prov";
        } elseif ($region) {
            $url      = "$BASE/regions/$region/municipalities/";
            $cacheKey = "muns_reg_$region";
        } else {
            echo json_encode(["error" => "province or region code required"]); exit;
        }
        break;
    case 'citymuns':
        // Combined cities + municipalities for a province or region (e.g. NCR)
        if ($prov) {
            $url      = "$BASE/provinces/$prov/cities-municipalities/";
            $cacheKey = "citymuns_$prov";
        } elseif ($region) {
            $url      = "$BASE/regions/$region/cities-municipalities/";
            $cacheKey = "citymuns_reg_$region";
        } else {
            echo json_encode(["error" => "province or region code required"]); exit;
        }
        break;
    case 'barangays':
        if ($city) {
            $url      = "$BASE/cities/$city/barangays/";
            $cacheKey = "brgy_city_$city";
        } elseif ($mun) {
            $url      = "$BASE/municipalities/$mun/barangays/";
            $cacheKey = "brgy_mun_$mun";
        } else {
            echo json_encode(["error" => "city or mun code required"]); exit;
        }
        break;
    default:
        echo json_encode(["error" => "invalid type"]); exit;
}

// Cache path
$cacheDir  = __DIR__ . "/../cache/";
$cachePath = $cacheDir . "psgc_" . $cacheKey . ".json";
$cacheTTL  = 86400; // 24 hours

// Return cached version if fresh
if (file_exists($cachePath) && (time() - filemtime($cachePath)) < $cacheTTL) {
    echo file_get_contents($cachePath);
    exit;
}

// Fetch from PSGC API
$ctx  = stream_context_create([
    "http" => [
        "timeout"        => 15,
        "user_agent"     => "SurfToSawa/1.0 PHP/" . PHP_VERSION,
        "ignore_errors"  => true,
    ]
]);
$raw = @file_get_contents($url, false, $ctx);

if ($raw === false) {
    // Return cached stale data if available, otherwise error
    if (file_exists($cachePath)) {
        echo file_get_contents($cachePath);
    } else {
        echo json_encode(["error" => "PSGC API unreachable", "url" => $url]);
    }
    exit;
}

// Validate JSON
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    echo json_encode(["error" => "Invalid PSGC response"]);
    exit;
}

// Save to cache
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
file_put_contents($cachePath, $raw);

echo $raw;
