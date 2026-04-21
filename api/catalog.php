<?php
/**
 * /api/catalog                             → GET  list all
 * /api/catalog?id=X                        → GET  single object
 * /api/catalog?id=X&action=override        → PUT  apply override / DELETE remove
 *
 * (Clean URL equivalents via mod_rewrite: /api/catalog, /api/catalog/X,
 *  /api/catalog/X/override — both styles work.)
 */

require_once __DIR__ . '/_bootstrap.php';

$routeId = $ROUTE_ID;
$isOverride = false;

// Detect override sub-resource — via clean URL suffix or ?action=override
if ($routeId !== null && str_ends_with($routeId, '/override')) {
    $isOverride = true;
    $routeId = substr($routeId, 0, -strlen('/override'));
} elseif (isset($_GET['action']) && $_GET['action'] === 'override') {
    $isOverride = true;
}

$method = method();

// ─── Override sub-resource ───────────────────────────────────────────────────
if ($isOverride) {
    if ($routeId === null || $routeId === '') errorResponse('Missing object ID', 400);

    if ($method === 'PUT') {
        $body = requestBody();
        $mag   = $body['mag']   ?? null;
        $sizeX = $body['sizeX'] ?? null;
        $sizeY = $body['sizeY'] ?? null;
        $filters = $body['filters'] ?? null;
        $ra    = $body['ra']    ?? null;
        $dec   = $body['dec']   ?? null;
        $notes = $body['notes'] ?? '';

        DB::exec(
            'INSERT OR REPLACE INTO catalog_overrides
             (id, mag, size_x, size_y, filters, ra, dec, notes, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$routeId, $mag, $sizeX, $sizeY,
             $filters !== null ? json_encode($filters) : null,
             $ra, $dec, $notes]
        );
        jsonResponse(['ok' => true]);
    }
    if ($method === 'DELETE') {
        DB::exec('DELETE FROM catalog_overrides WHERE id = ?', [$routeId]);
        jsonResponse(['ok' => true]);
    }
    errorResponse('Method not allowed', 405);
}

// ─── Single object GET /api/catalog/{id} ─────────────────────────────────────
if ($routeId !== null && $routeId !== '') {
    if ($method !== 'GET') errorResponse('Method not allowed', 405);

    // Built-in?
    $builtIn = Catalog::getById($routeId);
    if ($builtIn) {
        $ov = DB::one('SELECT * FROM catalog_overrides WHERE id = ?', [$routeId]);
        jsonResponse(applyOverride($builtIn, $ov));
    }

    // User-added?
    $row = DB::one('SELECT * FROM user_catalog WHERE id = ?', [$routeId]);
    if ($row) {
        jsonResponse([
            'id' => $row['id'], 'name' => $row['name'], 'type' => $row['type'],
            'constellation' => $row['constellation'], 'ra' => (float)$row['ra'],
            'dec' => (float)$row['dec'],
            'mag' => $row['mag'] !== null ? (float)$row['mag'] : null,
            'sizeX' => $row['size_x'] !== null ? (float)$row['size_x'] : null,
            'sizeY' => $row['size_y'] !== null ? (float)$row['size_y'] : null,
            'filters' => json_decode($row['filters'] ?? '[]', true) ?: [],
            'otherNames' => $row['other_names'] ?? '',
            'description' => $row['description'] ?? '',
            'starRating' => (int)($row['star_rating'] ?? 0),
            '_userAdded' => true,
        ]);
    }

    errorResponse('Not found', 404);
}

// ─── List all /api/catalog ───────────────────────────────────────────────────
if ($method === 'GET') {
    // Build overrides map
    $overrides = DB::all('SELECT * FROM catalog_overrides');
    $ovMap = [];
    foreach ($overrides as $o) { $ovMap[$o['id']] = $o; }

    // Apply overrides to built-in entries
    $builtIn = array_map(function ($obj) use ($ovMap) {
        return applyOverride($obj, $ovMap[$obj['id']] ?? null);
    }, Catalog::getAll());

    $userObjs = getUserCatalogObjects();

    jsonResponse(array_merge($builtIn, $userObjs));
}

errorResponse('Method not allowed', 405);
