<?php
/**
 * /api/visibility              → GET bulk (all DSOs + planets + moon summary)
 * /api/visibility/{id}         → GET single object with full altitudeProfile
 *
 * This is the heaviest endpoint. It mirrors the JS server's behavior exactly:
 *   - Uses astroDusk → astroDawn as the "dark window" for peak altitude / hours calc
 *   - Returns moon separation for every DSO and planet
 *   - Per-object endpoint additionally returns altitudeProfile and moonSepProfile
 */

require_once __DIR__ . '/_bootstrap.php';

$id = $ROUTE_ID ?? null;
$method = method();

if ($method !== 'GET') errorResponse('Method not allowed', 405);

// Common query params
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
if ($lat === null || $lon === null) errorResponse('lat and lon required');

$minAlt  = isset($_GET['min_altitude']) ? (float)$_GET['min_altitude'] : 20.0;
$dateStr = $_GET['date'] ?? gmdate('Y-m-d');

// ─── Per-object endpoint: /api/visibility/{id} ───────────────────────────────
if ($id !== null && $id !== '') {
    $solar = Astro::getNightSolarTimes($lat, $lon, $dateStr);
    $obsDate = $dateStr . 'T12:00:00Z';
    $moonInfo = Astro::moonInfo($obsDate);

    $isMoon   = $id === 'Moon';
    $isPlanet = !$isMoon && isset(Astro::PLANET_ELEMENTS[$id]);
    $ra = null; $dec = null; $responseObj = null;

    if ($isMoon) {
        $pos = Astro::moonPosition($obsDate);
        $ra = $pos['ra']; $dec = $pos['dec'];
    } elseif ($isPlanet) {
        $pos = Astro::planetPosition($id, $obsDate);
        $ra = $pos['ra']; $dec = $pos['dec'];
    } else {
        // Built-in catalog first, then user catalog
        $builtIn = Catalog::getById($id);
        if ($builtIn) {
            $ov = DB::one('SELECT * FROM catalog_overrides WHERE id = ?', [$id]);
            $effObj = applyOverride($builtIn, $ov);
            $ra = $effObj['ra']; $dec = $effObj['dec'];
            $responseObj = $effObj;
        } else {
            $row = DB::one('SELECT * FROM user_catalog WHERE id = ?', [$id]);
            if (!$row) errorResponse('Not found', 404);
            $ra  = (float)$row['ra'];
            $dec = (float)$row['dec'];
            $responseObj = [
                'id' => $row['id'], 'name' => $row['name'], 'type' => $row['type'],
                'constellation' => $row['constellation'] ?? '',
                'ra' => $ra, 'dec' => $dec,
                'mag' => $row['mag'] !== null ? (float)$row['mag'] : null,
                'sizeX' => $row['size_x'] !== null ? (float)$row['size_x'] : null,
                'sizeY' => $row['size_y'] !== null ? (float)$row['size_y'] : null,
                'filters' => json_decode($row['filters'] ?? '[]', true) ?: [],
                'otherNames' => $row['other_names'] ?? '',
                'description' => $row['description'] ?? '',
                'starRating' => (int)($row['star_rating'] ?? 0),
                '_userAdded' => true,
            ];
        }
    }

    $profile = Astro::getVisibilityProfile($ra, $dec, $lat, $lon, $obsDate, $solar);

    // Moon separation profile — compute moon pos at each sample point
    $moonSepProfile = [];
    foreach ($profile as $p) {
        $t = $p['time'];
        $tMs = (int)round(Astro::toUnix($t) * 1000);
        $mPos = Astro::moonPosition($t);
        $sep = $isMoon ? null : (int)round(Astro::angularSeparation($ra, $dec, $mPos['ra'], $mPos['dec']));
        $moonSepProfile[] = ['t' => $tMs, 'sep' => $sep];
    }

    $moonPos = Astro::moonPosition($obsDate);
    $moonSep = $isMoon ? 0 : (int)round(Astro::angularSeparation($ra, $dec, $moonPos['ra'], $moonPos['dec']));

    $base = [
        'solar'           => $solar,
        'altitudeProfile' => $profile,
        'moonSepProfile'  => $moonSepProfile,
        'moon'            => $moonInfo,
        'moonSeparation'  => $moonSep,
    ];

    if ($isPlanet || $isMoon) {
        jsonResponse(array_merge($base, [
            'isPlanet' => true,
            'isMoon'   => $isMoon,
            'name'     => $id,
            'ra'       => $ra,
            'dec'      => $dec,
        ]));
    }
    jsonResponse(array_merge($base, ['object' => $responseObj]));
}

// ─── Bulk endpoint: /api/visibility ──────────────────────────────────────────
$solar = Astro::getNightSolarTimes($lat, $lon, $dateStr);
$darkStartMs = $solar['astroDusk']
    ? Astro::toUnix($solar['astroDusk']) * 1000
    : Astro::toUnix($dateStr . 'T22:00:00Z') * 1000;
$darkEndMs = $solar['astroDawn']
    ? Astro::toUnix($solar['astroDawn']) * 1000
    : (Astro::toUnix($dateStr . 'T10:00:00Z') + 86400) * 1000;

$obsDate = $dateStr . 'T12:00:00Z';
$moonInfo = Astro::moonInfo($obsDate);
$moonPos = Astro::moonPosition($obsDate);
$moonAltAz = Astro::getAltAz($moonPos['ra'], $moonPos['dec'], $lat, $lon, $obsDate);

// Overrides map
$overrides = DB::all('SELECT * FROM catalog_overrides');
$ovMap = [];
foreach ($overrides as $o) { $ovMap[$o['id']] = $o; }

// Merge built-in + user catalog
$allObjects = [];
foreach (Catalog::getAll() as $obj) {
    $allObjects[] = applyOverride($obj, $ovMap[$obj['id']] ?? null);
}
foreach (getUserCatalogObjects() as $u) {
    $allObjects[] = $u;
}

// Compute visibility for each DSO
$visibility = [];
foreach ($allObjects as $effObj) {
    $profile = Astro::getVisibilityProfile($effObj['ra'], $effObj['dec'], $lat, $lon, $obsDate, $solar);

    // Filter to samples within dark window AND above minimum altitude
    $darkAbove = [];
    foreach ($profile as $p) {
        $tMs = Astro::toUnix($p['time']) * 1000;
        if ($tMs >= $darkStartMs && $tMs <= $darkEndMs && $p['altitude'] >= $minAlt) {
            $darkAbove[] = $p;
        }
    }
    if (empty($darkAbove)) continue;

    // Peak altitude during the dark window
    $peak = $darkAbove[0];
    foreach ($darkAbove as $p) if ($p['altitude'] > $peak['altitude']) $peak = $p;

    $visibility[] = [
        'id'             => $effObj['id'],
        'name'           => $effObj['name'],
        'type'           => $effObj['type'],
        'constellation'  => $effObj['constellation'] ?? '',
        'mag'            => $effObj['mag'] ?? null,
        'sizeX'          => $effObj['sizeX'] ?? null,
        'sizeY'          => $effObj['sizeY'] ?? null,
        'filters'        => $effObj['filters'] ?? [],
        'starRating'     => $effObj['starRating'] ?? 0,
        'peakAltitude'   => (int)round($peak['altitude']),
        'peakTime'       => $peak['time'],
        'hoursVisible'   => round(count($darkAbove) * (5 / 60) * 10) / 10,
        'moonSeparation' => (int)round(Astro::angularSeparation($effObj['ra'], $effObj['dec'], $moonPos['ra'], $moonPos['dec'])),
    ];
}

// Planets
$planets = [];
foreach (['Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune'] as $name) {
    $pos = Astro::planetPosition($name, $obsDate);
    if (!$pos) continue;
    $profile = Astro::getVisibilityProfile($pos['ra'], $pos['dec'], $lat, $lon, $obsDate, $solar);
    $darkAbove = [];
    foreach ($profile as $p) {
        $tMs = Astro::toUnix($p['time']) * 1000;
        if ($tMs >= $darkStartMs && $tMs <= $darkEndMs && $p['altitude'] >= $minAlt) {
            $darkAbove[] = $p;
        }
    }
    if (empty($darkAbove)) continue;

    $peak = $darkAbove[0];
    foreach ($darkAbove as $p) if ($p['altitude'] > $peak['altitude']) $peak = $p;

    $planets[] = [
        'name'           => $name,
        'ra'             => $pos['ra'],
        'dec'            => $pos['dec'],
        'peakAltitude'   => (int)round($peak['altitude']),
        'peakTime'       => $peak['time'],
        'hoursVisible'   => round(count($darkAbove) * (5 / 60) * 10) / 10,
        'moonSeparation' => (int)round(Astro::angularSeparation($pos['ra'], $pos['dec'], $moonPos['ra'], $moonPos['dec'])),
    ];
}

// Moon profile for moon widget
$moonProfile = Astro::getVisibilityProfile($moonPos['ra'], $moonPos['dec'], $lat, $lon, $obsDate, $solar);
$moonDarkAbove = [];
foreach ($moonProfile as $p) {
    $tMs = Astro::toUnix($p['time']) * 1000;
    if ($tMs >= $darkStartMs && $tMs <= $darkEndMs && $p['altitude'] >= $minAlt) {
        $moonDarkAbove[] = $p;
    }
}

// Compact moon profile (matches JS format: {t:ms, alt:deg})
$moonProfileOut = array_map(fn($p) => [
    't'   => (int)round(Astro::toUnix($p['time']) * 1000),
    'alt' => $p['altitude'],
], $moonProfile);

jsonResponse([
    'solar'            => $solar,
    'moon'             => [
        'phase'        => $moonInfo['phase'],
        'illumination' => $moonInfo['illumination'],
        'name'         => $moonInfo['name'],
    ],
    'moonAltitude'     => (int)round($moonAltAz['altitude']),
    'moonProfile'      => $moonProfileOut,
    'moonHoursVisible' => round(count($moonDarkAbove) * (5 / 60) * 10) / 10,
    'visibility'       => $visibility,
    'planets'          => $planets,
]);
