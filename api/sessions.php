<?php
/**
 * /api/sessions              → GET list (optionally filtered by target_id)
 *                              POST create
 * /api/sessions/{id}         → PUT update / DELETE remove
 */

require_once __DIR__ . '/_bootstrap.php';

$id = $ROUTE_ID ?? null;
$method = method();

// ─── Collection: /api/sessions ───────────────────────────────────────────────
if ($id === null) {
    if ($method === 'GET') {
        $targetId = $_GET['target_id'] ?? null;
        $rows = $targetId
            ? DB::all('SELECT * FROM sessions WHERE target_id = ? ORDER BY session_date DESC', [$targetId])
            : DB::all('SELECT * FROM sessions ORDER BY session_date DESC');
        // SQLite/PDO returns all columns as strings by default. The frontend compares
        // session IDs with strict equality (===), so cast id to int before returning.
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            if ($r['integration_time'] !== null) $r['integration_time'] = (int)$r['integration_time'];
            if ($r['frame_count'] !== null) $r['frame_count'] = (int)$r['frame_count'];
            if ($r['gain'] !== null) $r['gain'] = (int)$r['gain'];
            if ($r['bortle'] !== null) $r['bortle'] = (int)$r['bortle'];
        }
        unset($r);
        jsonResponse($rows);
    }
    if ($method === 'POST') {
        $b = requestBody();
        $targetId = $b['target_id'] ?? '';
        if (!$targetId) errorResponse('target_id required');

        // Ensure target exists (mirrors JS behavior: create as 'captured' if missing)
        DB::exec("INSERT OR IGNORE INTO targets (id, status) VALUES (?, 'captured')", [$targetId]);

        DB::exec(
            'INSERT INTO sessions
             (target_id, session_date, location, telescope, camera, filters,
              integration_time, frame_count, gain, bortle, seeing, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $targetId,
                $b['session_date'] ?? '',
                $b['location'] ?? '',
                $b['telescope'] ?? '',
                $b['camera'] ?? '',
                $b['filters'] ?? '',
                (int)($b['integration_time'] ?? 0),
                (int)($b['frame_count'] ?? 0),
                isset($b['gain'])   ? (int)$b['gain']   : null,
                isset($b['bortle']) ? (int)$b['bortle'] : null,
                $b['seeing'] ?? '',
                $b['notes'] ?? '',
            ]
        );
        jsonResponse(['id' => (int)DB::lastId()]);
    }
    errorResponse('Method not allowed', 405);
}

// ─── Single: /api/sessions/{id} ──────────────────────────────────────────────
if ($method === 'PUT') {
    $b = requestBody();
    DB::exec(
        'UPDATE sessions SET
           session_date=?, location=?, telescope=?, camera=?, filters=?,
           integration_time=?, frame_count=?, gain=?, bortle=?, seeing=?, notes=?
         WHERE id=?',
        [
            $b['session_date'] ?? '',
            $b['location'] ?? '',
            $b['telescope'] ?? '',
            $b['camera'] ?? '',
            $b['filters'] ?? '',
            (int)($b['integration_time'] ?? 0),
            (int)($b['frame_count'] ?? 0),
            isset($b['gain'])   ? (int)$b['gain']   : null,
            isset($b['bortle']) ? (int)$b['bortle'] : null,
            $b['seeing'] ?? '',
            $b['notes'] ?? '',
            (int)$id,
        ]
    );
    jsonResponse(['ok' => true]);
}

if ($method === 'DELETE') {
    DB::exec('DELETE FROM sessions WHERE id = ?', [(int)$id]);
    jsonResponse(['ok' => true]);
}

errorResponse('Method not allowed', 405);
