<?php
/**
 * API Bootstrap — included by every api/*.php file.
 *
 * Responsibilities:
 *   - Configure error handling (convert to JSON errors in production)
 *   - Set no-cache headers
 *   - Set Content-Type to application/json
 *   - Load shared libraries (DB, Catalog, Astro)
 *   - Set SQLite database path (data/tracker.db)
 *   - Parse JSON request bodies
 *   - Resolve ID from path routing OR query string (?id=)
 */

// Minimum PHP version check — the app uses 8.1+ syntax
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'PHP 8.1 or higher required, running ' . PHP_VERSION]);
    exit;
}

// Always JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Set timezone to UTC — all astronomical calculations are UTC-based
date_default_timezone_set('UTC');

// Convert all PHP errors to exceptions so we can return them as clean JSON
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Fatal errors become JSON too
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
    exit;
});

// Project root (parent of api/)
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/db.php';
require_once $ROOT . '/lib/catalog.php';
require_once $ROOT . '/lib/astro.php';

DB::setPath($ROOT . '/data/tracker.db');

$LOCATIONS_PATH = $ROOT . '/data/locations.json';
$EQUIPMENT_PATH = $ROOT . '/data/equipment.json';

// ─── Resolve ID from path routing OR query string ────────────────────────────
// Endpoints accept both:
//   /api/foo.php?id=X      (query string — works without mod_rewrite)
//   /api/foo/X             (clean URL — only works if mod_rewrite is active)
// Priority: $ROUTE_ID (set by index.php router) > $_GET['id']
if (!isset($ROUTE_ID) || $ROUTE_ID === null || $ROUTE_ID === '') {
    $ROUTE_ID = $_GET['id'] ?? null;
}

// ─── Helper functions ────────────────────────────────────────────────────────

function readJSON(string $path, $fallback = []) {
    if (!file_exists($path)) return $fallback;
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') return $fallback;
    $decoded = json_decode($raw, true);
    return $decoded === null ? $fallback : $decoded;
}

function writeJSON(string $path, $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

function nextId(array $collection): int {
    if (empty($collection)) return 1;
    $max = 0;
    foreach ($collection as $item) {
        if (isset($item['id']) && $item['id'] > $max) $max = (int)$item['id'];
    }
    return $max + 1;
}

function requestBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function jsonResponse($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function errorResponse(string $msg, int $status = 400): void {
    jsonResponse(['error' => $msg], $status);
}

function applyOverride(array $obj, ?array $ov): array {
    if ($ov === null) return $obj;
    $out = $obj;
    if (isset($ov['mag']) && $ov['mag'] !== null) $out['mag'] = (float)$ov['mag'];
    if (isset($ov['size_x']) && $ov['size_x'] !== null) $out['sizeX'] = (float)$ov['size_x'];
    if (isset($ov['size_y']) && $ov['size_y'] !== null) $out['sizeY'] = (float)$ov['size_y'];
    if (!empty($ov['filters'])) {
        $f = json_decode($ov['filters'], true);
        if (is_array($f)) $out['filters'] = $f;
    }
    if (isset($ov['ra']) && $ov['ra'] !== null) $out['ra'] = (float)$ov['ra'];
    if (isset($ov['dec']) && $ov['dec'] !== null) $out['dec'] = (float)$ov['dec'];
    $out['_overridden'] = true;
    return $out;
}

function getUserCatalogObjects(): array {
    $rows = DB::all('SELECT * FROM user_catalog ORDER BY name ASC');
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => $r['id'],
            'name' => $r['name'],
            'type' => $r['type'],
            'constellation' => $r['constellation'] ?? '',
            'ra' => (float)$r['ra'],
            'dec' => (float)$r['dec'],
            'mag' => $r['mag'] !== null ? (float)$r['mag'] : null,
            'sizeX' => $r['size_x'] !== null ? (float)$r['size_x'] : null,
            'sizeY' => $r['size_y'] !== null ? (float)$r['size_y'] : null,
            'filters' => json_decode($r['filters'] ?? '[]', true) ?: [],
            'otherNames' => $r['other_names'] ?? '',
            'description' => $r['description'] ?? '',
            'starRating' => (int)($r['star_rating'] ?? 0),
            '_userAdded' => true,
        ];
    }
    return $out;
}
