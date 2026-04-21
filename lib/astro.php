<?php
/**
 * AstroTracker — Astronomy Calculation Engine (PHP port of astro.js)
 *
 * Covers: sun position, twilight/solar times, moon phase + position,
 * planet positions (Keplerian approximation), object visibility profiles,
 * angular separation, rise/transit/set.
 *
 * All times returned are ISO 8601 strings in UTC (matching the JS version's
 * `new Date().toISOString()` format). All input dates accept either ISO strings
 * or Unix timestamps (int seconds). Internally we work in Unix seconds.
 *
 * Coordinates: RA in decimal hours, Dec in decimal degrees.
 */

class Astro {

    // ─── CONSTANTS & HELPERS ─────────────────────────────────────────────────

    public static function toRad(float $d): float { return $d * M_PI / 180.0; }
    public static function toDeg(float $r): float { return $r * 180.0 / M_PI; }

    public static function norm360(float $x): float {
        $m = fmod($x, 360.0);
        return $m < 0 ? $m + 360.0 : $m;
    }

    public static function normH(float $h): float {
        $m = fmod($h, 24.0);
        return $m < 0 ? $m + 24.0 : $m;
    }

    /**
     * Convert a date input into a Unix timestamp in seconds (float).
     * Accepts: float/int (Unix seconds), ISO 8601 string, or DateTime object.
     */
    public static function toUnix($date): float {
        if (is_numeric($date)) return (float)$date;
        if (is_string($date)) {
            $ts = strtotime($date);
            if ($ts === false) throw new InvalidArgumentException("Bad date string: $date");
            return (float)$ts;
        }
        if ($date instanceof DateTimeInterface) return (float)$date->getTimestamp();
        throw new InvalidArgumentException('Unsupported date type');
    }

    /**
     * Convert a Unix timestamp (seconds, float) back to an ISO 8601 string in UTC.
     * Matches JS Date.toISOString() format: "2026-07-10T03:01:41.455Z"
     */
    public static function toIso(float $unix): string {
        $secs = (int)floor($unix);
        $ms = (int)round(($unix - $secs) * 1000);
        if ($ms === 1000) { $ms = 0; $secs++; }
        $dt = gmdate('Y-m-d\TH:i:s', $secs);
        return sprintf('%s.%03dZ', $dt, $ms);
    }

    public static function julianDate($date): float {
        $unix = self::toUnix($date);
        return $unix / 86400.0 + 2440587.5;
    }

    // ─── SIDEREAL TIME & ALT/AZ ──────────────────────────────────────────────

    public static function localSiderealTime(float $lon, $date): float {
        $jd = self::julianDate($date);
        $T = ($jd - 2451545.0) / 36525.0;
        $gst = 280.46061837 + 360.98564736629 * ($jd - 2451545.0)
             + 0.000387933 * $T * $T - ($T * $T * $T) / 38710000.0;
        $gst = self::norm360($gst);
        $lst = fmod($gst + $lon, 360.0);
        if ($lst < 0) $lst += 360.0;
        return $lst / 15.0;
    }

    /**
     * Compute altitude/azimuth of an equatorial coord at a given location/time.
     * Returns ['altitude'=>deg, 'azimuth'=>deg].
     */
    public static function getAltAz(float $ra, float $dec, float $lat, float $lon, $date): array {
        $lst = self::localSiderealTime($lon, $date);
        $ha = self::norm360(($lst - $ra) * 15.0);
        $haRad = self::toRad($ha);
        $decRad = self::toRad($dec);
        $latRad = self::toRad($lat);

        $sinAlt = sin($decRad) * sin($latRad) + cos($decRad) * cos($latRad) * cos($haRad);
        $sinAlt = max(-1.0, min(1.0, $sinAlt));
        $altitude = self::toDeg(asin($sinAlt));

        $denom = cos($latRad) * cos(self::toRad($altitude));
        $cosAz = $denom == 0 ? 0 : (sin($decRad) - sin($latRad) * $sinAlt) / $denom;
        $cosAz = max(-1.0, min(1.0, $cosAz));
        $azimuth = self::toDeg(acos($cosAz));
        if (sin($haRad) > 0) $azimuth = 360.0 - $azimuth;

        return ['altitude' => $altitude, 'azimuth' => $azimuth];
    }

    // ─── SUN POSITION & TWILIGHTS ────────────────────────────────────────────

    public static function sunPosition($date): array {
        $jd = self::julianDate($date);
        $n = $jd - 2451545.0;
        $L = self::norm360(280.460 + 0.9856474 * $n);
        $g = self::toRad(self::norm360(357.528 + 0.9856003 * $n));
        $lambda = self::toRad($L + 1.915 * sin($g) + 0.020 * sin(2 * $g));
        $epsilon = self::toRad(23.439 - 0.0000004 * $n);
        $ra  = self::toDeg(atan2(cos($epsilon) * sin($lambda), cos($lambda)));
        $dec = self::toDeg(asin(sin($epsilon) * sin($lambda)));
        return ['ra' => self::normH($ra / 15.0), 'dec' => $dec];
    }

    /**
     * Find the time the sun crosses a given altitude on a given date.
     * @param string $riseOrSet 'rise' or 'set'
     * @param float  $targetAlt altitude in degrees (negative for twilight)
     * @return float|null Unix timestamp (seconds) or null if no crossing found.
     */
    public static function findSunAltitudeTime(float $lat, float $lon, $date, float $targetAlt, string $riseOrSet): ?float {
        // Anchor at local noon on the given day
        $baseUnix = self::toUnix($date);
        // Compute local noon: 12:00 UTC offset by -round(lon/15) hours
        $offsetHours = (int)round($lon / 15.0);
        // Start from the UTC-midnight of baseUnix's UTC day, then add noon (in local time)
        $utcMidnight = (int)floor($baseUnix / 86400) * 86400;
        $noon = $utcMidnight + (12 - $offsetHours) * 3600;

        $windowSec = 15 * 3600;
        $steps = 600;
        $interval = (2 * $windowSec) / $steps;

        $prevAlt = null; $prevT = null;

        for ($i = 0; $i <= $steps; $i++) {
            $t = $noon - $windowSec + $i * $interval;
            $sun = self::sunPosition($t);
            $aa = self::getAltAz($sun['ra'], $sun['dec'], $lat, $lon, $t);
            $alt = $aa['altitude'];

            if ($prevAlt !== null) {
                if ($riseOrSet === 'rise' && $prevAlt < $targetAlt && $alt >= $targetAlt) {
                    $frac = ($targetAlt - $prevAlt) / ($alt - $prevAlt);
                    return $prevT + $frac * $interval;
                }
                if ($riseOrSet === 'set' && $prevAlt >= $targetAlt && $alt < $targetAlt) {
                    $frac = ($targetAlt - $prevAlt) / ($alt - $prevAlt);
                    return $prevT + $frac * $interval;
                }
            }
            $prevAlt = $alt; $prevT = $t;
        }
        return null;
    }

    /**
     * All eight twilight/solar times for a single calendar day.
     * Returns associative array with ISO strings (or null).
     */
    public static function getSolarTimes(float $lat, float $lon, $date): array {
        $events = [
            'sunrise'      => [-0.833, 'rise'],
            'sunset'       => [-0.833, 'set'],
            'civilDawn'    => [-6,     'rise'],
            'civilDusk'    => [-6,     'set'],
            'nauticalDawn' => [-12,    'rise'],
            'nauticalDusk' => [-12,    'set'],
            'astroDawn'    => [-18,    'rise'],
            'astroDusk'    => [-18,    'set'],
        ];
        $out = [];
        foreach ($events as $key => [$alt, $dir]) {
            $t = self::findSunAltitudeTime($lat, $lon, $date, $alt, $dir);
            $out[$key] = $t === null ? null : self::toIso($t);
        }
        return $out;
    }

    /**
     * Night-bridging solar times: dusk events on the evening of `dateStr`,
     * dawn events on the next morning. Handles summer-latitude case where
     * astro dusk falls after local midnight.
     *
     * @param string $dateStr "YYYY-MM-DD"
     */
    public static function getNightSolarTimes(float $lat, float $lon, string $dateStr): array {
        $evening = $dateStr . 'T12:00:00Z';
        $eveningUnix = self::toUnix($evening);
        $morningUnix = $eveningUnix + 86400;

        $ev = self::getSolarTimes($lat, $lon, $eveningUnix);
        $mo = self::getSolarTimes($lat, $lon, $morningUnix);

        $sunset = $ev['sunset'];
        $sunsetMs = $sunset ? self::toUnix($sunset) : null;

        // Pick dusk occurrence AFTER sunset.
        $pickDusk = function(string $evKey) use ($ev, $mo, $sunsetMs) {
            $evVal = $ev[$evKey]; $moVal = $mo[$evKey];
            $evMs = $evVal ? self::toUnix($evVal) : null;
            $moMs = $moVal ? self::toUnix($moVal) : null;
            if ($sunsetMs !== null && $evMs !== null && $evMs >= $sunsetMs) return $evVal;
            if ($sunsetMs !== null && $moMs !== null && $moMs >= $sunsetMs) return $moVal;
            return $evVal ?? $moVal;
        };

        return [
            'sunset'       => $ev['sunset'],
            'civilDusk'    => $pickDusk('civilDusk'),
            'nauticalDusk' => $pickDusk('nauticalDusk'),
            'astroDusk'    => $pickDusk('astroDusk'),
            'astroDawn'    => $mo['astroDawn'],
            'nauticalDawn' => $mo['nauticalDawn'],
            'civilDawn'    => $mo['civilDawn'],
            'sunrise'      => $mo['sunrise'],
        ];
    }

    // ─── MOON ────────────────────────────────────────────────────────────────

    public static function moonPhase($date): float {
        $jd = self::julianDate($date);
        $cycle = 29.53058867;
        $knownNew = 2451549.5;
        $p = fmod($jd - $knownNew, $cycle);
        if ($p < 0) $p += $cycle;
        return $p / $cycle;
    }

    public static function moonInfo($date): array {
        $phase = self::moonPhase($date);
        $illum = (1 - cos(2 * M_PI * $phase)) / 2;
        if      ($phase < 0.03 || $phase > 0.97) $name = 'New Moon';
        else if ($phase < 0.22) $name = 'Waxing Crescent';
        else if ($phase < 0.28) $name = 'First Quarter';
        else if ($phase < 0.47) $name = 'Waxing Gibbous';
        else if ($phase < 0.53) $name = 'Full Moon';
        else if ($phase < 0.72) $name = 'Waning Gibbous';
        else if ($phase < 0.78) $name = 'Last Quarter';
        else                    $name = 'Waning Crescent';
        return ['phase' => $phase, 'illumination' => $illum, 'name' => $name];
    }

    public static function moonPosition($date): array {
        $jd = self::julianDate($date);
        $n = $jd - 2451545.0;
        $L = fmod(218.316 + 13.176396 * $n, 360.0);
        $M = self::toRad(fmod(134.963 + 13.064993 * $n, 360.0));
        $F = self::toRad(fmod(93.272 + 13.229350 * $n, 360.0));
        $lon = self::toRad($L + 6.289 * sin($M));
        $lat = self::toRad(5.128 * sin($F));
        $obliquity = self::toRad(23.439);
        $ra  = self::toDeg(atan2(sin($lon) * cos($obliquity) - tan($lat) * sin($obliquity), cos($lon)));
        $dec = self::toDeg(asin(sin($lat) * cos($obliquity) + cos($lat) * sin($obliquity) * sin($lon)));
        return ['ra' => self::normH($ra / 15.0), 'dec' => $dec];
    }

    // ─── PLANETS (Keplerian approximation, J2000 epoch) ──────────────────────

    public const PLANET_ELEMENTS = [
        'Mercury' => ['L0'=>252.2503, 'dL'=>4.09235779,  'a'=>0.38710, 'e'=>0.205631, 'i'=>7.0049, 'w'=>77.4561,  'Om'=>48.3310],
        'Venus'   => ['L0'=>181.9798, 'dL'=>1.60217648,  'a'=>0.72333, 'e'=>0.006773, 'i'=>3.3947, 'w'=>131.5637, 'Om'=>76.6799],
        'Mars'    => ['L0'=>355.4330, 'dL'=>0.52402068,  'a'=>1.52366, 'e'=>0.093405, 'i'=>1.8497, 'w'=>336.0590, 'Om'=>49.5581],
        'Jupiter' => ['L0'=>34.3515,  'dL'=>0.08309256,  'a'=>5.20260, 'e'=>0.048498, 'i'=>1.3030, 'w'=>14.7539,  'Om'=>100.4644],
        'Saturn'  => ['L0'=>50.0774,  'dL'=>0.03344422,  'a'=>9.55491, 'e'=>0.055546, 'i'=>2.4886, 'w'=>92.4320,  'Om'=>113.6655],
        'Uranus'  => ['L0'=>314.0550, 'dL'=>0.01172834,  'a'=>19.2184, 'e'=>0.046381, 'i'=>0.7732, 'w'=>170.9643, 'Om'=>73.9777],
        'Neptune' => ['L0'=>304.3487, 'dL'=>0.00598103,  'a'=>30.1104, 'e'=>0.009456, 'i'=>1.7700, 'w'=>44.9710,  'Om'=>131.7930],
    ];

    public static function planetPosition(string $name, $date): ?array {
        if (!isset(self::PLANET_ELEMENTS[$name])) return null;
        $el = self::PLANET_ELEMENTS[$name];

        $jd = self::julianDate($date);
        $n = $jd - 2451545.0;

        $L = self::norm360($el['L0'] + $el['dL'] * $n);
        $M = self::toRad(self::norm360($L - $el['w']));

        $E = $M;
        for ($i = 0; $i < 6; $i++) $E = $M + $el['e'] * sin($E);

        $v = 2 * atan2(sqrt(1 + $el['e']) * sin($E / 2), sqrt(1 - $el['e']) * cos($E / 2));
        $r = $el['a'] * (1 - $el['e'] * cos($E));

        $wRad  = self::toRad($el['w']);
        $OmRad = self::toRad($el['Om']);
        $iRad  = self::toRad($el['i']);
        $u = $v + $wRad - $OmRad;

        $xh = $r * (cos($OmRad) * cos($u) - sin($OmRad) * sin($u) * cos($iRad));
        $yh = $r * (sin($OmRad) * cos($u) + cos($OmRad) * sin($u) * cos($iRad));
        $zh = $r * sin($u) * sin($iRad);

        // Earth
        $elE = ['L0'=>100.4644, 'dL'=>0.98564736, 'a'=>1.0, 'e'=>0.016709, 'w'=>102.9373];
        $LE = self::norm360($elE['L0'] + $elE['dL'] * $n);
        $ME = self::toRad(self::norm360($LE - $elE['w']));
        $EE = $ME;
        for ($i = 0; $i < 6; $i++) $EE = $ME + $elE['e'] * sin($EE);
        $vE = 2 * atan2(sqrt(1 + $elE['e']) * sin($EE / 2), sqrt(1 - $elE['e']) * cos($EE / 2));
        $rE = $elE['a'] * (1 - $elE['e'] * cos($EE));
        $wERad = self::toRad($elE['w']);
        $xe = $rE * cos($vE + $wERad);
        $ye = $rE * sin($vE + $wERad);
        $ze = 0.0;

        $dx = $xh - $xe; $dy = $yh - $ye; $dz = $zh - $ze;
        $eclLon = self::toDeg(atan2($dy, $dx));
        $eclLat = self::toDeg(atan2($dz, sqrt($dx * $dx + $dy * $dy)));

        $epsilon = self::toRad(23.439 - 0.0000004 * $n);
        $eclLonR = self::toRad($eclLon);
        $eclLatR = self::toRad($eclLat);

        $ra  = self::toDeg(atan2(sin($eclLonR) * cos($epsilon) - tan($eclLatR) * sin($epsilon), cos($eclLonR)));
        $dec = self::toDeg(asin(sin($eclLatR) * cos($epsilon) + cos($eclLatR) * sin($epsilon) * sin($eclLonR)));

        return ['ra' => self::normH($ra / 15.0), 'dec' => $dec];
    }

    // ─── ANGULAR SEPARATION ──────────────────────────────────────────────────

    public static function angularSeparation(float $ra1, float $dec1, float $ra2, float $dec2): float {
        $r1 = self::toRad($ra1 * 15); $d1 = self::toRad($dec1);
        $r2 = self::toRad($ra2 * 15); $d2 = self::toRad($dec2);
        $c = sin($d1) * sin($d2) + cos($d1) * cos($d2) * cos($r1 - $r2);
        $c = max(-1.0, min(1.0, $c));
        return self::toDeg(acos($c));
    }

    // ─── VISIBILITY PROFILES ─────────────────────────────────────────────────

    /**
     * Sample altitude/azimuth from sunset to next sunrise at 5-minute intervals.
     * @param array $solarTimes result of getNightSolarTimes or similar (ISO strings)
     * @return array of ['time'=>iso_string, 'altitude'=>float, 'azimuth'=>int]
     */
    public static function getVisibilityProfile(float $ra, float $dec, float $lat, float $lon, $date, array $solarTimes): array {
        $sunsetUnix = $solarTimes['sunset'] ? self::toUnix($solarTimes['sunset'])
            : self::toUnix($date);  // fallback — caller should pass real solar times
        $sunriseUnix = $solarTimes['sunrise'] ? self::toUnix($solarTimes['sunrise'])
            : $sunsetUnix + 12 * 3600;

        if ($sunriseUnix <= $sunsetUnix) $sunriseUnix += 86400;

        $intervalSec = 5 * 60;
        $profile = [];
        for ($t = $sunsetUnix; $t <= $sunriseUnix; $t += $intervalSec) {
            $aa = self::getAltAz($ra, $dec, $lat, $lon, $t);
            $profile[] = [
                'time' => self::toIso($t),
                'altitude' => round($aa['altitude'] * 10) / 10,
                'azimuth' => (int)round($aa['azimuth']),
            ];
        }
        return $profile;
    }

    /**
     * Compute rise, transit, set and max altitude over a 24-hour window centered on the given date.
     */
    public static function getRiseTransitSet(float $ra, float $dec, float $lat, float $lon, $date): array {
        $baseUnix = self::toUnix($date);
        $utcMidnight = (int)floor($baseUnix / 86400) * 86400;
        $noon = $utcMidnight + 12 * 3600;
        $steps = 288;
        $interval = 86400 / $steps;
        $riseTime = null; $setTime = null; $transitTime = null;
        $maxAlt = -90.0; $prevAlt = null;
        for ($i = 0; $i <= $steps; $i++) {
            $t = $noon + $i * $interval - 12 * 3600;
            $aa = self::getAltAz($ra, $dec, $lat, $lon, $t);
            $alt = $aa['altitude'];
            if ($prevAlt !== null) {
                if ($prevAlt < 0 && $alt >= 0 && $riseTime === null) $riseTime = $t;
                if ($prevAlt >= 0 && $alt < 0 && $setTime === null) $setTime = $t;
            }
            if ($alt > $maxAlt) { $maxAlt = $alt; $transitTime = $t; }
            $prevAlt = $alt;
        }
        return [
            'riseTime'    => $riseTime    === null ? null : self::toIso($riseTime),
            'transitTime' => $transitTime === null ? null : self::toIso($transitTime),
            'setTime'     => $setTime     === null ? null : self::toIso($setTime),
            'maxAltitude' => $maxAlt,
        ];
    }
}
