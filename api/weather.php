<?php
/**
 * /api/weather?lat=&lon=   → GET Open-Meteo hourly forecast proxy
 *
 * Tries curl first (more reliable, better error handling) then falls back to
 * file_get_contents with allow_url_fopen. One of these is enabled by default
 * in every XAMPP/MAMP/Laragon install.
 */

require_once __DIR__ . '/_bootstrap.php';

if (method() !== 'GET') errorResponse('Method not allowed', 405);

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
if ($lat === null || $lon === null) errorResponse('lat and lon required');

$url = 'https://api.open-meteo.com/v1/forecast?'
     . 'latitude=' . urlencode((string)$lat)
     . '&longitude=' . urlencode((string)$lon)
     . '&hourly=cloud_cover,cloud_cover_low,cloud_cover_mid,cloud_cover_high,'
     . 'temperature_2m,relative_humidity_2m,dew_point_2m,wind_speed_10m,'
     . 'precipitation_probability,visibility'
     . '&timezone=auto&forecast_days=2';

$raw = null;
$method = 'none';

// Try curl first
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'AstroTracker/1.0');
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $httpCode !== 200) $raw = null;
    else $method = 'curl';
}

// Fallback: file_get_contents
if ($raw === null && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'AstroTracker/1.0',
        ],
    ]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result !== false) {
        $raw = $result;
        $method = 'file_get_contents';
    }
}

if ($raw === null) {
    errorResponse('Could not fetch weather — neither curl nor allow_url_fopen is available', 502);
}

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['hourly'])) {
    errorResponse('Invalid response from Open-Meteo', 502);
}

jsonResponse([
    'source'     => 'Open-Meteo',
    'source_url' => 'https://open-meteo.com/',
    'timezone'   => $data['timezone'] ?? 'UTC',
    'hourly'     => $data['hourly'],
    '_method'    => $method, // helpful for debugging, harmless to include
]);
