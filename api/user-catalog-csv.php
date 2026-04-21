<?php
/**
 * /api/user-catalog-csv.php
 *
 * Query params:
 *   ?action=export            → GET: download all user_catalog entries as CSV
 *   ?action=preview           → POST: parse uploaded CSV and return duplicate analysis
 *   ?action=commit            → POST: apply a resolved import plan
 *
 * CSV format (RFC 4180, comma-separated, Excel/Sheets compatible):
 *   Header: id,name,type,constellation,ra,dec,mag,sizeX,sizeY,filters,otherNames,description
 *   Filters are pipe-separated inside a single cell: "Ha|OIII|SHO"
 *   RA in decimal hours, Dec in decimal degrees.
 *
 * Duplicate detection:
 *   - Exact ID match (against built-in + user catalogs) → "duplicate"
 *   - Coordinate match within 2 arcminutes            → "possible duplicate"
 *   - Otherwise                                        → "new"
 *
 * Commit actions (per row):
 *   - skip      → ignore this row
 *   - import    → insert as new (only valid for "new" status)
 *   - keep_both → insert with a user-supplied alternate ID (caller must provide altId)
 *   - merge     → apply non-empty imported fields onto the existing entry
 *   - replace   → overwrite the existing entry entirely
 */

require_once __DIR__ . '/_bootstrap.php';

// ─── Route by action ─────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'export':
        handleExport();
        break;
    case 'preview':
        handlePreview();
        break;
    case 'commit':
        handleCommit();
        break;
    default:
        errorResponse('Unknown action. Use ?action=export, preview, or commit', 400);
}

// ═══════════════════════════════════════════════════════════════════════════
// EXPORT
// ═══════════════════════════════════════════════════════════════════════════

function handleExport(): void {
    if (method() !== 'GET') errorResponse('Method not allowed', 405);

    $rows = DB::all('SELECT * FROM user_catalog ORDER BY name ASC');

    // Build CSV in memory. Using php://temp so we don't touch disk.
    $fp = fopen('php://temp', 'r+');

    // Header
    fputcsv($fp, [
        'id', 'name', 'type', 'constellation', 'ra', 'dec',
        'mag', 'sizeX', 'sizeY', 'filters', 'otherNames', 'description'
    ], ',', '"', '');

    foreach ($rows as $r) {
        $filters = json_decode($r['filters'] ?? '[]', true) ?: [];
        fputcsv($fp, [
            $r['id'],
            $r['name'],
            $r['type'],
            $r['constellation'] ?? '',
            $r['ra'],
            $r['dec'],
            $r['mag'] ?? '',
            $r['size_x'] ?? '',
            $r['size_y'] ?? '',
            implode('|', $filters),
            $r['other_names'] ?? '',
            $r['description'] ?? '',
        ], ',', '"', '');
    }

    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);

    // Override the JSON content-type that bootstrap set — this is a file download
    $filename = 'astrotracker-catalog-' . gmdate('Y-m-d') . '.csv';
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csv));

    echo $csv;
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// PREVIEW
// ═══════════════════════════════════════════════════════════════════════════

function handlePreview(): void {
    if (method() !== 'POST') errorResponse('Method not allowed', 405);

    // CSV content comes from either:
    //   (a) a file upload (multipart/form-data, field name "csvfile")
    //   (b) a raw body with Content-Type text/csv (pasted text)
    $csvText = '';
    if (!empty($_FILES['csvfile']) && is_uploaded_file($_FILES['csvfile']['tmp_name'])) {
        $csvText = file_get_contents($_FILES['csvfile']['tmp_name']);
        if ($csvText === false) errorResponse('Could not read uploaded file', 400);
    } else {
        $csvText = file_get_contents('php://input');
    }
    if (!$csvText) errorResponse('No CSV content provided', 400);

    // Strip BOM if present (Excel sometimes adds UTF-8 BOM)
    $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText);

    // Parse CSV
    $rows = parseCSV($csvText);
    if (empty($rows)) errorResponse('CSV file is empty', 400);

    // First row is header
    $header = array_map('trim', array_shift($rows));
    $headerLc = array_map('strtolower', $header);

    // Build column index lookup (case-insensitive)
    $colIndex = [];
    foreach ($headerLc as $i => $name) $colIndex[$name] = $i;

    // Required columns
    foreach (['id', 'name', 'ra', 'dec'] as $required) {
        if (!isset($colIndex[strtolower($required)])) {
            errorResponse("Missing required column: $required", 400);
        }
    }

    // Preload built-in catalog + user catalog for matching
    $builtIn = Catalog::getAll();
    $userRows = DB::all('SELECT * FROM user_catalog');

    // Build ID lookup (case-insensitive) and coordinate list
    $byIdLc = [];
    $existing = [];
    foreach ($builtIn as $e) {
        $byIdLc[strtolower($e['id'])] = ['source' => 'builtin', 'obj' => $e];
        $existing[] = ['source' => 'builtin', 'id' => $e['id'], 'name' => $e['name'],
                       'ra' => (float)$e['ra'], 'dec' => (float)$e['dec']];
    }
    foreach ($userRows as $r) {
        $obj = userRowToObj($r);
        $byIdLc[strtolower($obj['id'])] = ['source' => 'user', 'obj' => $obj];
        $existing[] = ['source' => 'user', 'id' => $obj['id'], 'name' => $obj['name'],
                       'ra' => (float)$obj['ra'], 'dec' => (float)$obj['dec']];
    }

    // Process each row
    $result = [];
    foreach ($rows as $rowIdx => $row) {
        // Skip fully empty rows
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

        $parsed = mapRowToObj($row, $colIndex);
        $issues = validateRow($parsed);

        $analysis = ['status' => 'new', 'matches' => []];
        if (!empty($issues)) {
            $analysis['status'] = 'invalid';
        } else {
            // Exact ID match?
            $lc = strtolower($parsed['id']);
            if (isset($byIdLc[$lc])) {
                $match = $byIdLc[$lc];
                $analysis['status'] = 'duplicate';
                $analysis['matches'][] = [
                    'source' => $match['source'],
                    'id'     => $match['obj']['id'],
                    'name'   => $match['obj']['name'],
                    'reason' => 'Exact ID match',
                ];
            } else {
                // Coordinate proximity check (within 2 arcminutes)
                foreach ($existing as $e) {
                    $sep = angularSeparation($parsed['ra'], $parsed['dec'], $e['ra'], $e['dec']);
                    if ($sep <= 2/60) {  // 2 arcminutes in degrees
                        $analysis['status'] = 'possible_duplicate';
                        $analysis['matches'][] = [
                            'source' => $e['source'],
                            'id'     => $e['id'],
                            'name'   => $e['name'],
                            'reason' => sprintf('Coordinates within %.1f″ of existing object',
                                                $sep * 3600),
                        ];
                        if (count($analysis['matches']) >= 3) break;  // cap at 3
                    }
                }
            }
        }

        $result[] = [
            'rowNum'   => $rowIdx + 2,  // +2 because 1-indexed + header row
            'data'     => $parsed,
            'analysis' => $analysis,
            'issues'   => $issues,
        ];
    }

    // Summary
    $summary = [
        'total'               => count($result),
        'new'                 => 0,
        'duplicate'           => 0,
        'possible_duplicate'  => 0,
        'invalid'             => 0,
    ];
    foreach ($result as $r) $summary[$r['analysis']['status']]++;

    jsonResponse(['rows' => $result, 'summary' => $summary]);
}

// ═══════════════════════════════════════════════════════════════════════════
// COMMIT
// ═══════════════════════════════════════════════════════════════════════════

function handleCommit(): void {
    if (method() !== 'POST') errorResponse('Method not allowed', 405);

    $body = requestBody();
    $plan = $body['plan'] ?? null;
    if (!is_array($plan)) errorResponse('Missing import plan', 400);

    $stats = ['imported' => 0, 'merged' => 0, 'replaced' => 0, 'skipped' => 0, 'errors' => []];

    // Wrap in a transaction — if anything fails we want to roll back cleanly
    $pdo = DB::get();
    $pdo->beginTransaction();

    try {
        foreach ($plan as $item) {
            $action = $item['action'] ?? 'skip';
            $data   = $item['data']   ?? [];
            $rowNum = $item['rowNum'] ?? '?';

            if ($action === 'skip') {
                $stats['skipped']++;
                continue;
            }

            $issues = validateRow($data);
            if (!empty($issues)) {
                $stats['errors'][] = "Row $rowNum: " . implode('; ', $issues);
                continue;
            }

            try {
                switch ($action) {
                    case 'import':
                        // Insert new; fail if ID exists
                        if (userOrBuiltinHasId($data['id'])) {
                            throw new RuntimeException("ID {$data['id']} already exists");
                        }
                        insertUserCatalog($data);
                        $stats['imported']++;
                        break;

                    case 'keep_both':
                        // Rename the incoming and insert
                        $newId = $item['altId'] ?? '';
                        if (!$newId) throw new RuntimeException("keep_both requires altId");
                        if (userOrBuiltinHasId($newId)) {
                            throw new RuntimeException("Alternate ID $newId already exists");
                        }
                        $data['id'] = $newId;
                        insertUserCatalog($data);
                        $stats['imported']++;
                        break;

                    case 'merge':
                        // Apply non-empty imported fields onto existing user_catalog entry.
                        // If the matched object is in built-in catalog, merge isn't possible
                        // (we can't mutate built-in entries). We'll skip and log.
                        $targetId = $item['targetId'] ?? $data['id'];
                        if (Catalog::getById($targetId) !== null) {
                            throw new RuntimeException("Cannot merge into built-in catalog entry $targetId — use override instead");
                        }
                        $existing = DB::one('SELECT * FROM user_catalog WHERE id = ?', [$targetId]);
                        if (!$existing) throw new RuntimeException("Target $targetId not found");
                        $merged = mergeFields(userRowToObj($existing), $data);
                        updateUserCatalog($targetId, $merged);
                        $stats['merged']++;
                        break;

                    case 'replace':
                        $targetId = $item['targetId'] ?? $data['id'];
                        if (Catalog::getById($targetId) !== null) {
                            throw new RuntimeException("Cannot replace built-in catalog entry $targetId — use override instead");
                        }
                        $existing = DB::one('SELECT id FROM user_catalog WHERE id = ?', [$targetId]);
                        if (!$existing) {
                            // If target doesn't exist, just insert
                            insertUserCatalog($data);
                        } else {
                            // If IDs differ (e.g. target is 'MyObj' but data.id is 'OtherName'),
                            // delete the old and insert new. Otherwise update in place.
                            if ($data['id'] !== $targetId) {
                                DB::exec('DELETE FROM user_catalog WHERE id = ?', [$targetId]);
                                insertUserCatalog($data);
                            } else {
                                updateUserCatalog($targetId, $data);
                            }
                        }
                        $stats['replaced']++;
                        break;

                    default:
                        throw new RuntimeException("Unknown action: $action");
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "Row $rowNum: " . $e->getMessage();
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Import failed: ' . $e->getMessage(), 500);
    }

    jsonResponse($stats);
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Parse CSV text to an array of rows (each row is an array of cell strings).
 * Uses fgetcsv on an in-memory stream for proper RFC 4180 quote handling,
 * including quoted cells that contain embedded newlines.
 */
function parseCSV(string $text): array {
    // Normalize line endings (Windows \r\n, classic Mac \r)
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $fp = fopen('php://memory', 'r+');
    fwrite($fp, $text);
    rewind($fp);
    $rows = [];
    // Pass empty string for escape char to match RFC 4180 (avoids PHP 8.4 deprecation)
    while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        // fgetcsv returns [null] for blank lines; skip those
        if (count($row) === 1 && ($row[0] === null || $row[0] === '')) continue;
        $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}

/**
 * Map a raw CSV row array to a user_catalog object using the column index map.
 */
function mapRowToObj(array $row, array $colIndex): array {
    $get = function (string $col) use ($row, $colIndex) {
        $i = $colIndex[strtolower($col)] ?? null;
        if ($i === null || !isset($row[$i])) return '';
        return trim((string)$row[$i]);
    };
    $toFloat = fn($v) => $v === '' ? null : (float)$v;

    $filtersStr = $get('filters');
    $filters = [];
    if ($filtersStr !== '') {
        $filters = array_values(array_filter(
            array_map('trim', explode('|', $filtersStr)),
            fn($f) => $f !== ''
        ));
    }

    return [
        'id'            => $get('id'),
        'name'          => $get('name'),
        'type'          => $get('type') ?: 'Galaxy',
        'constellation' => $get('constellation'),
        'ra'            => $toFloat($get('ra')),
        'dec'           => $toFloat($get('dec')),
        'mag'           => $toFloat($get('mag')),
        'sizeX'         => $toFloat($get('sizex')),
        'sizeY'         => $toFloat($get('sizey')),
        'filters'       => $filters,
        'otherNames'    => $get('othernames'),
        'description'   => $get('description'),
    ];
}

/**
 * Return a list of validation issues (empty array = valid).
 */
function validateRow(array $obj): array {
    $issues = [];
    if (!$obj['id'])   $issues[] = 'id is required';
    if (!$obj['name']) $issues[] = 'name is required';
    if ($obj['ra']  === null) $issues[] = 'ra must be a number';
    if ($obj['dec'] === null) $issues[] = 'dec must be a number';
    // Range checks
    if ($obj['ra']  !== null && ($obj['ra']  < 0 || $obj['ra']  > 24))   $issues[] = 'ra must be 0-24 hours';
    if ($obj['dec'] !== null && ($obj['dec'] < -90 || $obj['dec'] > 90)) $issues[] = 'dec must be -90 to +90 degrees';
    return $issues;
}

/**
 * Angular separation in degrees between two points (RA in hours, Dec in degrees).
 * Uses the haversine-style formula from astro.php.
 */
function angularSeparation(float $ra1, float $dec1, float $ra2, float $dec2): float {
    $deg2rad = M_PI / 180;
    $ra1r = $ra1 * 15 * $deg2rad;
    $ra2r = $ra2 * 15 * $deg2rad;
    $dec1r = $dec1 * $deg2rad;
    $dec2r = $dec2 * $deg2rad;
    $cosSep = sin($dec1r) * sin($dec2r) + cos($dec1r) * cos($dec2r) * cos($ra1r - $ra2r);
    $cosSep = max(-1, min(1, $cosSep));
    return acos($cosSep) / $deg2rad;
}

/**
 * Convert a user_catalog DB row to a frontend-shaped object.
 */
function userRowToObj(array $r): array {
    return [
        'id'            => $r['id'],
        'name'          => $r['name'],
        'type'          => $r['type'],
        'constellation' => $r['constellation'] ?? '',
        'ra'            => (float)$r['ra'],
        'dec'           => (float)$r['dec'],
        'mag'           => $r['mag']    !== null ? (float)$r['mag']    : null,
        'sizeX'         => $r['size_x'] !== null ? (float)$r['size_x'] : null,
        'sizeY'         => $r['size_y'] !== null ? (float)$r['size_y'] : null,
        'filters'       => json_decode($r['filters'] ?? '[]', true) ?: [],
        'otherNames'    => $r['other_names'] ?? '',
        'description'   => $r['description'] ?? '',
    ];
}

/**
 * Merge imported non-empty fields onto an existing entry. Existing fields are
 * preserved where the imported value is empty/null.
 */
function mergeFields(array $existing, array $imported): array {
    $out = $existing;
    $nonEmpty = fn($v) => $v !== null && $v !== '' && $v !== [];

    foreach (['name', 'type', 'constellation', 'otherNames', 'description'] as $f) {
        if ($nonEmpty($imported[$f] ?? null)) $out[$f] = $imported[$f];
    }
    foreach (['ra', 'dec', 'mag', 'sizeX', 'sizeY'] as $f) {
        if ($imported[$f] !== null) $out[$f] = $imported[$f];
    }
    if (!empty($imported['filters'])) $out['filters'] = $imported['filters'];
    // ID stays the target's id (never overwrite on merge)
    $out['id'] = $existing['id'];
    return $out;
}

function userOrBuiltinHasId(string $id): bool {
    if (Catalog::getById($id) !== null) return true;
    if (DB::one('SELECT id FROM user_catalog WHERE id = ?', [$id]) !== null) return true;
    return false;
}

function insertUserCatalog(array $obj): void {
    DB::exec(
        'INSERT INTO user_catalog
         (id, name, type, constellation, ra, dec, mag, size_x, size_y,
          filters, other_names, description, star_rating)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
        [
            $obj['id'], $obj['name'], $obj['type'] ?? 'Galaxy',
            $obj['constellation'] ?? '',
            (float)$obj['ra'], (float)$obj['dec'],
            $obj['mag']   !== null ? (float)$obj['mag']   : null,
            $obj['sizeX'] !== null ? (float)$obj['sizeX'] : null,
            $obj['sizeY'] !== null ? (float)$obj['sizeY'] : null,
            json_encode($obj['filters'] ?? []),
            $obj['otherNames']  ?? '',
            $obj['description'] ?? '',
        ]
    );
}

function updateUserCatalog(string $id, array $obj): void {
    DB::exec(
        'UPDATE user_catalog SET
           name=?, type=?, constellation=?, ra=?, dec=?, mag=?, size_x=?, size_y=?,
           filters=?, other_names=?, description=?
         WHERE id=?',
        [
            $obj['name'] ?? '',
            $obj['type'] ?? 'Galaxy',
            $obj['constellation'] ?? '',
            (float)($obj['ra']  ?? 0),
            (float)($obj['dec'] ?? 0),
            $obj['mag']   !== null ? (float)$obj['mag']   : null,
            $obj['sizeX'] !== null ? (float)$obj['sizeX'] : null,
            $obj['sizeY'] !== null ? (float)$obj['sizeY'] : null,
            json_encode($obj['filters'] ?? []),
            $obj['otherNames']  ?? '',
            $obj['description'] ?? '',
            $id,
        ]
    );
}
