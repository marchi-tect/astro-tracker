<?php
/**
 * /api/targets       → GET  list all
 * /api/targets/{id}  → PUT upsert / DELETE
 */

require_once __DIR__ . '/_bootstrap.php';

$id = $ROUTE_ID ?? null;
$method = method();

if ($id === null) {
    if ($method !== 'GET') errorResponse('Method not allowed', 405);
    $rows = DB::all('SELECT * FROM targets ORDER BY updated_at DESC');
    foreach ($rows as &$r) {
        $r['rating'] = (int)($r['rating'] ?? 0);
    }
    unset($r);
    jsonResponse($rows);
}

if ($method === 'PUT') {
    $body = requestBody();
    $status = $body['status'] ?? '';
    $notes  = $body['notes']  ?? '';
    $rating = isset($body['rating']) ? (int)$body['rating'] : 0;

    // Only remove row if ALL three fields are empty (preserves notes/rating
    // when user clears status). Mirrors the JS server logic.
    $hasContent = $status
        || (trim($notes) !== '')
        || $rating > 0;

    if (!$hasContent) {
        DB::exec('DELETE FROM targets WHERE id = ?', [$id]);
    } else {
        $existing = DB::one('SELECT id FROM targets WHERE id = ?', [$id]);
        if ($existing) {
            DB::exec(
                'UPDATE targets SET status=?, notes=?, rating=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                [$status, $notes, $rating, $id]
            );
        } else {
            DB::exec(
                'INSERT INTO targets (id, status, notes, rating) VALUES (?, ?, ?, ?)',
                [$id, $status, $notes, $rating]
            );
        }
    }
    jsonResponse(['ok' => true]);
}

if ($method === 'DELETE') {
    DB::exec('DELETE FROM sessions WHERE target_id = ?', [$id]);
    DB::exec('DELETE FROM targets WHERE id = ?', [$id]);
    jsonResponse(['ok' => true]);
}

errorResponse('Method not allowed', 405);
