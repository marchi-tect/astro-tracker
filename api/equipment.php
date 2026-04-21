<?php
/**
 * /api/equipment         → GET list / POST create
 * /api/equipment/{id}    → PUT update / DELETE
 */

require_once __DIR__ . '/_bootstrap.php';

$id = $ROUTE_ID !== null ? (int)$ROUTE_ID : null;
$method = method();

function equipFromBody(array $b): array {
    return [
        'name'          => $b['name'] ?? '',
        'telescope'     => $b['telescope'] ?? '',
        'focal_length'  => isset($b['focal_length']) ? (float)$b['focal_length'] : null,
        'aperture'      => isset($b['aperture']) ? (float)$b['aperture'] : null,
        'mount'         => $b['mount'] ?? '',
        'camera'        => $b['camera'] ?? '',
        'sensor_width'  => isset($b['sensor_width']) ? (float)$b['sensor_width'] : null,
        'sensor_height' => isset($b['sensor_height']) ? (float)$b['sensor_height'] : null,
        'pixel_size'    => isset($b['pixel_size']) ? (float)$b['pixel_size'] : null,
        'notes'         => $b['notes'] ?? '',
        'is_default'    => !empty($b['is_default']),
    ];
}

if ($id === null) {
    if ($method === 'GET') jsonResponse(readJSON($EQUIPMENT_PATH));

    if ($method === 'POST') {
        $b = requestBody();
        $eqs = readJSON($EQUIPMENT_PATH);
        $fields = equipFromBody($b);
        if ($fields['is_default']) {
            foreach ($eqs as &$e) $e['is_default'] = false;
            unset($e);
        }
        $new = array_merge(['id' => nextId($eqs)], $fields);
        $eqs[] = $new;
        writeJSON($EQUIPMENT_PATH, $eqs);
        jsonResponse($new);
    }
    errorResponse('Method not allowed', 405);
}

if ($method === 'PUT') {
    $b = requestBody();
    $eqs = readJSON($EQUIPMENT_PATH);
    $fields = equipFromBody($b);
    if ($fields['is_default']) {
        foreach ($eqs as &$e) $e['is_default'] = false;
        unset($e);
    }
    $idx = -1;
    foreach ($eqs as $i => $e) if ($e['id'] === $id) { $idx = $i; break; }
    if ($idx === -1) errorResponse('Not found', 404);
    $eqs[$idx] = array_merge($eqs[$idx], $fields);
    writeJSON($EQUIPMENT_PATH, $eqs);
    jsonResponse($eqs[$idx]);
}

if ($method === 'DELETE') {
    $eqs = readJSON($EQUIPMENT_PATH);
    $eqs = array_values(array_filter($eqs, fn($e) => $e['id'] !== $id));
    writeJSON($EQUIPMENT_PATH, $eqs);
    jsonResponse(['ok' => true]);
}

errorResponse('Method not allowed', 405);
