<?php
/**
 * AstroTracker — Validation Harness
 *
 * Run this once on your XAMPP/MAMP install (or any PHP 8.1+) to verify the PHP
 * astronomy engine matches the Node.js reference implementation.
 *
 * Usage (from CLI):
 *   php validate.php
 *
 * Or via browser:
 *   http://localhost/astro-tracker/validation/validate.php
 *
 * Expected: all 53 tests pass (identical up to ~6 decimal places of precision).
 */

require_once __DIR__ . '/../lib/astro.php';

$refPath = __DIR__ . '/reference.json';
if (!file_exists($refPath)) {
    die("Missing reference.json — generate it with Node first.\n");
}
$ref = json_decode(file_get_contents($refPath), true);
if (!$ref || !isset($ref['tests'])) die("Bad reference.json format.\n");

$TOL_FLOAT = 1e-6;     // angular values in degrees/hours: match to ~millionth
$TOL_TIME  = 2.0;       // time values: match within 2 seconds (numerical search tolerance)

function fmtNum($v): string {
    if (is_numeric($v)) return sprintf('%.6f', (float)$v);
    return var_export($v, true);
}

function deepCompare($actual, $expected, float $tolFloat, float $tolTimeSec): array {
    // Returns ['ok'=>bool, 'diff'=>string]
    if (is_array($expected) && is_array($actual)) {
        foreach ($expected as $k => $v) {
            if (!array_key_exists($k, $actual)) return ['ok'=>false, 'diff'=>"missing key '$k'"];
            $r = deepCompare($actual[$k], $v, $tolFloat, $tolTimeSec);
            if (!$r['ok']) return ['ok'=>false, 'diff'=>"at '$k': ".$r['diff']];
        }
        return ['ok'=>true, 'diff'=>''];
    }
    if ($expected === null && $actual === null) return ['ok'=>true, 'diff'=>''];
    if ($expected === null || $actual === null) {
        return ['ok'=>false, 'diff'=>"null mismatch — expected ".var_export($expected,true).", got ".var_export($actual,true)];
    }
    if (is_string($expected) && is_string($actual)) {
        // If both look like ISO dates, compare as timestamps with tolerance.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $expected)) {
            $a = strtotime($actual); $e = strtotime($expected);
            if ($a === false || $e === false) {
                return ['ok'=>$actual === $expected, 'diff'=>"unparseable: '$actual' vs '$expected'"];
            }
            $diff = abs($a - $e);
            return ['ok'=>$diff <= $tolTimeSec, 'diff'=>"time diff {$diff}s ($actual vs $expected)"];
        }
        return ['ok'=>$actual === $expected, 'diff'=>"string '$actual' != '$expected'"];
    }
    if (is_numeric($expected) && is_numeric($actual)) {
        $diff = abs((float)$expected - (float)$actual);
        // Scale tolerance for larger numbers (Julian Dates are in the millions)
        $scale = max(1.0, abs((float)$expected));
        $relDiff = $diff / $scale;
        $ok = $relDiff <= $tolFloat;
        return ['ok'=>$ok, 'diff'=>sprintf('expected %s, got %s (Δ=%g)', fmtNum($expected), fmtNum($actual), $diff)];
    }
    return ['ok'=>$actual == $expected, 'diff'=>"type/value mismatch"];
}

function callFn(string $fn, array $args) {
    switch ($fn) {
        case 'sunPosition':        return Astro::sunPosition($args[0]);
        case 'moonPosition':       return Astro::moonPosition($args[0]);
        case 'moonInfo':           return Astro::moonInfo($args[0]);
        case 'planetPosition':     return Astro::planetPosition($args[0], $args[1]);
        case 'getAltAz':           return Astro::getAltAz($args[0], $args[1], $args[2], $args[3], $args[4]);
        case 'localSiderealTime':  return Astro::localSiderealTime($args[0], $args[1]);
        case 'julianDate':         return Astro::julianDate($args[0]);
        case 'angularSeparation':  return Astro::angularSeparation($args[0], $args[1], $args[2], $args[3]);
        case 'getNightSolarTimes': return Astro::getNightSolarTimes($args[0], $args[1], $args[2]);
        default: throw new Exception("Unknown fn: $fn");
    }
}

$cli = (PHP_SAPI === 'cli');
$pass = 0; $fail = 0; $failures = [];

if (!$cli) {
    echo "<!DOCTYPE html><html><head><title>AstroTracker PHP Validation</title>";
    echo "<style>body{font-family:monospace;background:#111;color:#eee;padding:20px;line-height:1.5}";
    echo ".pass{color:#3dd98a}.fail{color:#f25c5c}.summary{font-size:16px;font-weight:bold;margin:16px 0;padding:10px;border:1px solid #333}";
    echo ".diff{color:#888;font-size:11px;margin-left:20px}</style></head><body>";
    echo "<h1>AstroTracker PHP Validation</h1>\n";
}

foreach ($ref['tests'] as $t) {
    try {
        $actual = callFn($t['fn'], $t['args']);
    } catch (Throwable $e) {
        $actual = 'EXCEPTION: ' . $e->getMessage();
    }
    $cmp = deepCompare($actual, $t['expect'], $TOL_FLOAT, $TOL_TIME);
    if ($cmp['ok']) {
        $pass++;
        if ($cli) echo "  ✓ {$t['name']}\n";
        else echo "<div class='pass'>✓ ".htmlspecialchars($t['name'])."</div>\n";
    } else {
        $fail++;
        $failures[] = $t['name'];
        if ($cli) {
            echo "  ✗ {$t['name']}\n    {$cmp['diff']}\n";
            echo "    actual:   " . json_encode($actual) . "\n";
            echo "    expected: " . json_encode($t['expect']) . "\n";
        } else {
            echo "<div class='fail'>✗ ".htmlspecialchars($t['name'])."</div>\n";
            echo "<div class='diff'>".htmlspecialchars($cmp['diff'])."</div>\n";
            echo "<div class='diff'>actual: ".htmlspecialchars(json_encode($actual))."</div>\n";
            echo "<div class='diff'>expected: ".htmlspecialchars(json_encode($t['expect']))."</div>\n";
        }
    }
}

$total = $pass + $fail;
$summary = "=== $pass / $total passed" . ($fail ? ", $fail FAILED" : "") . " ===";
if ($cli) {
    echo "\n$summary\n";
    exit($fail ? 1 : 0);
} else {
    $cls = $fail ? 'fail' : 'pass';
    echo "<div class='summary $cls'>$summary</div>";
    if ($fail) {
        echo "<p>Failed tests:</p><ul>";
        foreach ($failures as $f) echo "<li>".htmlspecialchars($f)."</li>";
        echo "</ul>";
    }
    echo "</body></html>";
}
