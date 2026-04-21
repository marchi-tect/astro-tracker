# AstroTracker

A self-hosted astrophotography planning tool. See what's visible tonight from
your location, plan imaging sessions, track your progress through wishlist →
planned → captured → complete, and log details of every session you shoot. The information is at your fingertips.

Runs locally in your browser via any PHP-capable web server. **No accounts, no
cloud, no internet required** after installation (except for the optional
weather widget). Your data stays on your machine.

---

## Quick Start — XAMPP (Windows / Mac / Linux)

The easiest way to run AstroTracker on a personal computer.

1. **Download and install XAMPP** from <https://www.apachefriends.org/>
2. **Start XAMPP**, click **Start** next to "Apache" in the control panel
3. **Unzip AstroTracker** into the XAMPP `htdocs` folder:
   - Windows: `C:\xampp\htdocs\astro-tracker\`
   - Mac: `/Applications/XAMPP/htdocs/astro-tracker/`
   - Linux: `/opt/lampp/htdocs/astro-tracker/`
4. **Open your browser** and go to <http://localhost/astro-tracker/>

That's it. No commands to run, no config to edit. The app creates its database
automatically the first time you open it.

### If you have an older version of AstroTracker

Back up your `data/` folder before replacing files — your targets, sessions,
and custom objects live there.

---

## Alternative — MAMP (Mac)

1. Install [MAMP](https://www.mamp.info/) (the free version is fine)
2. Start MAMP and click "Start Servers"
3. Unzip AstroTracker into `/Applications/MAMP/htdocs/astro-tracker/`
4. Open <http://localhost:8888/astro-tracker/> (MAMP's default port is 8888, not 80)

---

## Alternative — Laragon (Windows)

Even simpler than XAMPP, and auto-detects apps you drop in `www/`.

1. Install [Laragon](https://laragon.org/)
2. Start Laragon
3. Unzip AstroTracker into `C:\laragon\www\astro-tracker\`
4. Open <http://astro-tracker.test/> (Laragon auto-creates nice hostnames)

---

## Requirements

- **PHP 8.1 or higher** with PDO + SQLite support (XAMPP 8+ has this by default)
- **Apache** with `mod_rewrite` enabled (XAMPP enables this by default)
- Outbound HTTPS to `api.open-meteo.com` for the weather widget (optional)

If you get a PHP version error on startup, update XAMPP — older XAMPP versions
ship PHP 7.x, which AstroTracker doesn't support due to language features used.

---

## First-Time Setup

When you first open AstroTracker, the catalog is loaded but nothing is tracked
and you have no observing locations yet. Set these up:

### 1. Add an observing location

1. Click **Settings** in the sidebar → **📍 Locations** tab
2. Click **+ Add Location**
3. Fill in:
   - **Name**: "Backyard", "Cherry Springs SP", etc.
   - **Latitude / Longitude**: decimal degrees (negative for south/west).
     Google Maps → right-click location → coordinates are shown.
   - **Minimum Altitude**: usually 20° (horizon obstructions/atmosphere)
   - **Bortle**: your sky darkness class, 1 (pristine) to 9 (inner city)
   - Check **Set as default** if this is your main site

### 2. Add your equipment (optional but recommended)

In **Settings → 🔭 Equipment**, add your telescope(s) and camera(s). The
important fields for Field-of-View calculations are:

- **Focal length** (mm) — of the telescope
- **Sensor width / height** (mm) — of the camera
- **Pixel size** (µm) — for resolution calculation

Once saved, equipment appears as a dropdown on Tonight's Sky, and objects will
show whether they fit in a single frame or need a mosaic.

### 3. Plan tonight's sky

Go to **Tonight's Sky**, select your location, and click **Load Sky**. You'll
see every catalog object visible from your location tonight, sorted by peak
altitude. Click any column header to re-sort. Click the `⋮⋮ Columns` button to
show, hide, or reorder columns (your preferences are saved).

### 4. Track targets

Anywhere you see an object:
- **♡** — adds to your wishlist instantly
- **Click the row** — opens the full detail popup where you can set status,
  notes, and star rating

Objects progress through: Wishlist → Planned → Captured → Processing → Complete.
View them all in the **Pipeline** page.

### 5. Log sessions

After an imaging night, go to **Sessions** → **+ New Session**. Log the date,
target, location, integration time, frame count, filters used, and notes.
Sessions automatically update the target's status to "Captured".

---

## Custom Objects

The built-in catalog covers the major standard objects (Messier, Caldwell,
Sharpless 2, famous NGC/IC). For anything else, click **+ Add Object** on the
Catalog page.

Required fields: ID (your designation), Name, RA (decimal hours), Dec (decimal
degrees). Optional: magnitude, size, filters, description.

**Coordinate format tip**: SIMBAD, NED, and most astronomy databases provide
coordinates in decimal degrees. RA in decimal hours is `total_degrees / 15`.

---

## Data & Backups

Everything you enter is in the `data/` folder:

- `data/tracker.db` — SQLite database with targets, sessions, custom objects,
  ratings, notes, preferences, catalog overrides
- `data/locations.json` — your observing sites
- `data/equipment.json` — your telescopes/cameras

**To back up**: copy the entire `data/` folder somewhere safe.
**To move installations**: copy `data/` to the new AstroTracker folder.
**To start fresh**: delete `data/tracker.db` — it'll be recreated empty.

---

## Data Sources & Attribution

The built-in catalog (319 objects) is assembled from public/free astronomical
data sources. All entries are free to redistribute for personal non-commercial
use.

### Catalog contents

| Source | Approx. count | License |

| **Messier Catalog** (Charles Messier, 1781) | 110 | Public domain |
| **New General Catalogue** (J. L. E. Dreyer, 1888) | ~120 | Public domain |
| **Index Catalogue** (Dreyer, 1895 & 1908) | ~20 | Public domain |
| **Sharpless 2 Catalog** (Stewart Sharpless / U.S. Naval Observatory, 1959) | 60 | Public domain (U.S. gov work) |
| **Melotte Catalog** (P. J. Melotte, 1915) | 1 | Public domain |
| **Caldwell Catalog** (Sir Patrick Moore, 1995) | — | Cross-references in "Other Names" |

**About the Caldwell Catalog:** Moore's 1995 catalog primarily cross-references
objects that already have NGC/IC designations. For clarity and better
compatibility with external databases (like AstroBin), AstroTracker uses the
original NGC/IC/Mel designations as the primary ID and includes the Caldwell
number (e.g., `C80` for Omega Centauri) as an alternate name. One entry —
C99, the Coalsack Nebula — has no other catalog designation and retains the
Caldwell ID.

Object coordinates are J2000 epoch, sourced from:

- **SIMBAD** (Strasbourg astronomical Data Center, CDS) — <http://simbad.u-strasbg.fr/>
- **NED** (NASA/IPAC Extragalactic Database) — <https://ned.ipac.caltech.edu/>

### Object descriptions

Short factual descriptions are included for approximately 120 of the most
well-known objects. Descriptions are compiled from:

- **NASA, ESA, and NOIRLab image captions and fact sheets** — public domain
- **Wikipedia astronomy articles** — CC-BY-SA 4.0
- **General reference astronomy** — public knowledge

Descriptions are intentionally brief (1-2 sentences) and factual, without
subjective imaging advice. Objects not in the description list simply have
blank description fields — you can add your own notes via the object's
detail modal.

### Weather data

Cloud cover, temperature, humidity, and precipitation forecasts come from
**Open-Meteo** (<https://open-meteo.com/>), a free weather API that requires
no API key or registration for personal use.

### Planet/moon positions

Computed from J2000-epoch orbital elements using standard Keplerian
approximations. Accurate to ~arc-minute precision over the 1950–2050 range.

### Coordinate conversions and ephemeris math

Ported from well-known astronomical algorithms (Meeus' "Astronomical
Algorithms") with validation against standard reference outputs.

---

## Known Limitations

- **Single-user local**: there's no authentication. Treat AstroTracker like a
  spreadsheet, not a shared web service.
- **Planet positions approximate**: accurate to ~1 arcminute, good enough for
  imaging planning but not for precise astrometry.
- **No atmospheric refraction**: horizon rise/set times are to geometric
  horizon, not observed. Real sunrise/sunset is ~34 arcminutes earlier.
- **Weather widget needs internet**: everything else works offline.

---

## Troubleshooting

**"PHP 8.1 or higher required"**
Update XAMPP to a recent version (8.0+), or install PHP 8.1+.

**"The database is locked" or write errors**
Make sure the `data/` folder is writable by the web server. On Linux:
`chmod 755 data/` and ensure the `www-data` user can write.

**URLs give 404**
Check that Apache's `mod_rewrite` is enabled. In XAMPP it's on by default.
If not, you can edit `public/index.html` to use `.php` extensions in fetch
URLs, or see the `.htaccess` file for alternative routing.

**Weather widget shows "unavailable"**
Check firewall/proxy settings — your PHP needs outbound HTTPS to
`api.open-meteo.com`. If you're on a restricted network, the rest of the app
works fine without weather.

**Tonight's Sky empty / slow to load**
The bulk visibility calculation runs through 319+ objects, which takes about
0.5–2 seconds in PHP. If it seems broken, check the browser console (F12) for
any errors. If you see PHP errors, they'll be returned as JSON in the failed
network request.

---

## License

AstroTracker code is released under the MIT License. Catalog and astronomical
data retain their original licenses (see "Data Sources" above).
