<?php
/**
 * /api/planet-catalog   → GET current-epoch positions for all 7 planets + Moon
 *
 * Returns entries that plug into the Catalog page's listing. Coordinates are
 * computed for "now" (when the request arrives), so reloads naturally pick up
 * updated planet positions.
 */

require_once __DIR__ . '/_bootstrap.php';

if (method() !== 'GET') errorResponse('Method not allowed', 405);

$now = gmdate('Y-m-d\TH:i:s\Z');
$items = [];

// Moon first — it goes at the top of the planet listing
$mPos  = Astro::moonPosition($now);
$mInfo = Astro::moonInfo($now);
$items[] = [
    'id' => 'Moon', 'name' => 'Moon',
    'type' => 'Natural Satellite', 'constellation' => '',
    'ra' => $mPos['ra'], 'dec' => $mPos['dec'],
    'mag' => null, 'sizeX' => 30, 'sizeY' => 30,
    'filters' => [], 'otherNames' => '',
    'description' => "Earth's natural satellite — currently " . $mInfo['name']
        . ' (' . (int)round($mInfo['illumination'] * 100) . '% illuminated).',
    'starRating' => 0,
    '_planet' => true,
];

// Planets
foreach (['Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune'] as $name) {
    $pos = Astro::planetPosition($name, $now);
    if (!$pos) continue;
    $items[] = [
        'id' => $name, 'name' => $name,
        'type' => 'Planet', 'constellation' => '',
        'ra' => $pos['ra'], 'dec' => $pos['dec'],
        'mag' => null, 'sizeX' => null, 'sizeY' => null,
        'filters' => [], 'otherNames' => '',
        'description' => "$name — position changes nightly.",
        'starRating' => 0,
        '_planet' => true,
    ];
}

jsonResponse($items);
