<?php
/**
 * /api/prefs?key=K     → GET retrieve / PUT store JSON
 * /api/prefs/K         → same, via clean URL
 */

require_once __DIR__ . '/_bootstrap.php';

// Accept the pref key from either the route ID or the ?key= query string
$key = $ROUTE_ID ?? ($_GET['key'] ?? null);
if (!$key) errorResponse('Missing pref key');

$method = method();

if ($method === 'GET') {
    $row = DB::one('SELECT value FROM user_prefs WHERE key = ?', [$key]);
    if (!$row) jsonResponse(null);
    $decoded = json_decode($row['value'], true);
    // Decoded null is ambiguous (could mean "literal null" or "parse error");
    // we stored JSON so any non-null value decodes fine, and literal "null" also
    // matches this branch (which is semantically correct).
    jsonResponse($decoded);
}

if ($method === 'PUT') {
    $body = requestBody();
    // The frontend sometimes sends primitives (strings, numbers) — requestBody()
    // returns [] for those. Use raw input directly.
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') $raw = 'null';
    // Validate it's valid JSON
    json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE) errorResponse('Invalid JSON body');

    DB::exec(
        'INSERT INTO user_prefs (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP',
        [$key, $raw]
    );
    jsonResponse(['ok' => true]);
}

errorResponse('Method not allowed', 405);
