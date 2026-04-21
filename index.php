<?php
/**
 * AstroTracker — Router / Entry Point
 *
 * Handles routing of /api/* requests to the right endpoint files. Requests that
 * don't start with /api/ are passed through to Apache (for serving index.html,
 * themes.js, and static assets). If mod_rewrite isn't available, users can
 * alternatively access endpoints directly as /api/catalog.php etc.
 *
 * Request flow:
 *   Browser → Apache → .htaccess rewrites → index.php (this file) → api/xxx.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Determine the app's base path (everything up to index.php)
$scriptName = $_SERVER['SCRIPT_NAME']; // e.g. /projects/astro-tracker/index.php
$basePath   = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
// Strip the base path from the request URI
$path = $uri;
if ($basePath && str_starts_with($uri, $basePath)) {
    $path = substr($uri, strlen($basePath));
}
$path = '/' . ltrim($path, '/');

// ─── ROUTING ─────────────────────────────────────────────────────────────────

// Root: serve the HTML (in case .htaccess didn't handle it)
if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile(__DIR__ . '/index.html');
    exit;
}

// Match /api/resource or /api/resource/id
if (preg_match('#^/api/([a-z0-9_-]+)(?:/(.+))?/?$#i', $path, $m)) {
    $resource = $m[1];
    $id = isset($m[2]) ? urldecode($m[2]) : null;

    // Map the resource slug to its file
    $allowed = [
        'catalog', 'visibility', 'targets', 'sessions', 'locations',
        'equipment', 'user-catalog', 'user-catalog-csv', 'prefs',
        'planet-catalog', 'weather', 'stats',
    ];
    if (!in_array($resource, $allowed, true)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unknown endpoint: ' . $resource]);
        exit;
    }

    // Expose the parsed ID to the endpoint via a known global
    $ROUTE_ID = $id;
    require __DIR__ . '/api/' . $resource . '.php';
    exit;
}

// Unmatched: fall through to Apache for static files
// (index.html, themes.js, etc. are served by Apache directly via .htaccess)
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found: ' . $path]);
