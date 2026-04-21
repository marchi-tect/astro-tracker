<?php
/**
 * /api/stats   → GET dashboard statistics
 *
 * Response format matches the original Node.js server exactly — the frontend
 * expects specific field names (totalTracked, byStatus as array, totalIntegration).
 */

require_once __DIR__ . '/_bootstrap.php';

if (method() !== 'GET') errorResponse('Method not allowed', 405);

// Total tracked targets (any status)
$totalTracked = (int)(DB::one('SELECT COUNT(*) as c FROM targets')['c'] ?? 0);

// byStatus: array of {status, c} — frontend does: arr.forEach(s => sm[s.status] = s.c)
$byStatusRows = DB::all('SELECT status, COUNT(*) as c FROM targets GROUP BY status');
$byStatus = array_map(fn($r) => ['status' => $r['status'], 'c' => (int)$r['c']], $byStatusRows);

$totalSessions    = (int)(DB::one('SELECT COUNT(*) as c FROM sessions')['c'] ?? 0);
$totalIntegration = (int)(DB::one('SELECT COALESCE(SUM(integration_time), 0) as s FROM sessions')['s'] ?? 0);

$recentSessions = DB::all('SELECT * FROM sessions ORDER BY session_date DESC LIMIT 8');
foreach ($recentSessions as &$s) {
    $s['id'] = (int)$s['id'];
    if ($s['integration_time'] !== null) $s['integration_time'] = (int)$s['integration_time'];
    if ($s['frame_count']      !== null) $s['frame_count']      = (int)$s['frame_count'];
    if ($s['gain']             !== null) $s['gain']             = (int)$s['gain'];
    if ($s['bortle']           !== null) $s['bortle']           = (int)$s['bortle'];
}
unset($s);

jsonResponse([
    'totalTracked'     => $totalTracked,
    'byStatus'         => $byStatus,
    'totalSessions'    => $totalSessions,
    'totalIntegration' => $totalIntegration,
    'recentSessions'   => $recentSessions,
]);
