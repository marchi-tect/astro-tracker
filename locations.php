<?php
/**
 * /api/locations         → GET list / POST create
 * /api/locations/{id}    → PUT update / DELETE
 *
 * Locations are stored in data/locations.json so they survive a wiped tracker.db.
 */

require_once __DIR__ . '/_bootstrap.php';

$id = $ROUTE_ID !== null ? (int)$ROUTE_ID : null;
$method = method();

if ($id === null) {
    if ($method === 'GET') jsonResponse(readJSON($LOCATIONS_PATH));

    if ($method === 'POST') {
        $b = requestBody();
        $locs = readJSON($LOCATIONS_PATH);
        if (!empty($b['is_default'])) {
            foreach ($locs as &$l) $l['is_default'] = false;
            unset($l);
        }
        $new = [
            'id'           => nextId($locs),
            'name'         => $b['name'] ?? '',
            'lat'          => isset($b['lat']) ? (float)$b['lat'] : 0,
            'lon'          => isset($b['lon']) ? (float)$b['lon'] : 0,
            'bortle'       => isset($b['bortle']) ? (int)$b['bortle'] : null,
            'notes'        => $b['notes'] ?? '',
            'min_altitude' => isset($b['min_altitude']) ? (int)$b['min_altitude'] : 20,
            'is_default'   => !empty($b['is_default']),
        ];
        $locs[] = $new;
        writeJSON($LOCATIONS_PATH, $locs);
        jsonResponse($new);
    }
    errorResponse('Method not allowed', 405);
}

if ($method === 'PUT') {
    $b = requestBody();
    $locs = readJSON($LOCATIONS_PATH);
    if (!empty($b['is_default'])) {
        foreach ($locs as &$l) $l['is_default'] = false;
        unset($l);
    }
    $idx = -1;
    foreach ($locs as $i => $l) if ($l['id'] === $id) { $idx = $i; break; }
    if ($idx === -1) errorResponse('Not found', 404);
    $locs[$idx] = array_merge($locs[$idx], [
        'name'         => $b['name'] ?? '',
        'lat'          => isset($b['lat']) ? (float)$b['lat'] : 0,
        'lon'          => isset($b['lon']) ? (float)$b['lon'] : 0,
        'bortle'       => isset($b['bortle']) ? (int)$b['bortle'] : null,
        'notes'        => $b['notes'] ?? '',
        'min_altitude' => isset($b['min_altitude']) ? (int)$b['min_altitude'] : 20,
        'is_default'   => !empty($b['is_default']),
    ]);
    writeJSON($LOCATIONS_PATH, $locs);
    jsonResponse($locs[$idx]);
}

if ($method === 'DELETE') {
    $locs = readJSON($LOCATIONS_PATH);
    $locs = array_values(array_filter($locs, fn($l) => $l['id'] !== $id));
    writeJSON($LOCATIONS_PATH, $locs);
    jsonResponse(['ok' => true]);
}

errorResponse('Method not allowed', 405);
