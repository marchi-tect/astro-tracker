<?php
/**
 * /api/user-catalog         → POST create
 * /api/user-catalog/{id}    → PUT update / DELETE
 */

require_once __DIR__ . '/_bootstrap.php';

$id = $ROUTE_ID ?? null;
$method = method();

// ─── POST /api/user-catalog ──────────────────────────────────────────────────
if ($id === null) {
    if ($method !== 'POST') errorResponse('Method not allowed', 405);
    $b = requestBody();
    $newId = $b['id'] ?? '';
    $name  = $b['name'] ?? '';
    $ra = $b['ra'] ?? null;
    $dec = $b['dec'] ?? null;
    if (!$newId || !$name || $ra === null || $dec === null) {
        errorResponse('id, name, ra, dec required');
    }
    // Collision check against built-in catalog and user catalog
    if (Catalog::getById($newId) !== null) {
        errorResponse("ID already exists in built-in catalog — choose a different one", 409);
    }
    if (DB::one('SELECT id FROM user_catalog WHERE id = ?', [$newId]) !== null) {
        errorResponse("ID already exists — choose a different one", 409);
    }

    DB::exec(
        'INSERT INTO user_catalog
         (id, name, type, constellation, ra, dec, mag, size_x, size_y,
          filters, other_names, description, star_rating)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $newId, $name,
            $b['type'] ?? 'Galaxy',
            $b['constellation'] ?? '',
            (float)$ra, (float)$dec,
            isset($b['mag'])   && $b['mag']   !== '' ? (float)$b['mag']   : null,
            isset($b['sizeX']) && $b['sizeX'] !== '' ? (float)$b['sizeX'] : null,
            isset($b['sizeY']) && $b['sizeY'] !== '' ? (float)$b['sizeY'] : null,
            json_encode($b['filters'] ?? []),
            $b['otherNames']  ?? '',
            $b['description'] ?? '',
            (int)($b['starRating'] ?? 0),
        ]
    );
    jsonResponse(['ok' => true]);
}

// ─── PUT/DELETE /api/user-catalog/{id} ───────────────────────────────────────
if ($method === 'PUT') {
    $b = requestBody();
    $existing = DB::one('SELECT id FROM user_catalog WHERE id = ?', [$id]);
    if (!$existing) errorResponse('Not found', 404);

    DB::exec(
        'UPDATE user_catalog SET
           name=?, type=?, constellation=?, ra=?, dec=?, mag=?, size_x=?, size_y=?,
           filters=?, other_names=?, description=?, star_rating=?
         WHERE id=?',
        [
            $b['name'] ?? '',
            $b['type'] ?? 'Galaxy',
            $b['constellation'] ?? '',
            isset($b['ra']) ? (float)$b['ra'] : 0,
            isset($b['dec']) ? (float)$b['dec'] : 0,
            isset($b['mag'])   && $b['mag']   !== '' ? (float)$b['mag']   : null,
            isset($b['sizeX']) && $b['sizeX'] !== '' ? (float)$b['sizeX'] : null,
            isset($b['sizeY']) && $b['sizeY'] !== '' ? (float)$b['sizeY'] : null,
            json_encode($b['filters'] ?? []),
            $b['otherNames']  ?? '',
            $b['description'] ?? '',
            (int)($b['starRating'] ?? 0),
            $id,
        ]
    );
    jsonResponse(['ok' => true]);
}

if ($method === 'DELETE') {
    DB::exec('DELETE FROM user_catalog WHERE id = ?', [$id]);
    DB::exec('DELETE FROM targets WHERE id = ?', [$id]);
    jsonResponse(['ok' => true]);
}

errorResponse('Method not allowed', 405);
