<?php
/**
 * AstroTracker — Database helper (PDO + SQLite)
 *
 * Single point of DB access. First call creates tracker.db with the schema.
 * Subsequent calls reuse the same connection.
 */

class DB {
    private static ?PDO $pdo = null;
    private static string $dbPath = '';

    /**
     * Set the SQLite file path (typically data/tracker.db). Must be called
     * before any get() call — the main index.php/bootstrap handles this.
     */
    public static function setPath(string $path): void {
        self::$dbPath = $path;
    }

    public static function get(): PDO {
        if (self::$pdo === null) {
            if (!self::$dbPath) {
                throw new RuntimeException('DB path not set — call DB::setPath() first');
            }
            $dir = dirname(self::$dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$pdo = new PDO('sqlite:' . self::$dbPath);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Enable foreign keys and WAL mode for better concurrency
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::initSchema();
        }
        return self::$pdo;
    }

    /**
     * Create tables if they don't exist. Safe to call multiple times.
     */
    private static function initSchema(): void {
        $pdo = self::$pdo;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS targets (
                id TEXT PRIMARY KEY,
                status TEXT DEFAULT 'wishlist',
                notes TEXT DEFAULT '',
                rating INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                target_id TEXT NOT NULL,
                session_date TEXT NOT NULL,
                location TEXT DEFAULT '',
                telescope TEXT DEFAULT '',
                camera TEXT DEFAULT '',
                filters TEXT DEFAULT '',
                integration_time INTEGER DEFAULT 0,
                frame_count INTEGER DEFAULT 0,
                gain INTEGER DEFAULT NULL,
                bortle INTEGER DEFAULT NULL,
                seeing TEXT DEFAULT '',
                notes TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS catalog_overrides (
                id TEXT PRIMARY KEY,
                mag REAL,
                size_x REAL,
                size_y REAL,
                filters TEXT,
                ra REAL,
                dec REAL,
                notes TEXT DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS user_catalog (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'Galaxy',
                constellation TEXT DEFAULT '',
                ra REAL NOT NULL,
                dec REAL NOT NULL,
                mag REAL DEFAULT NULL,
                size_x REAL DEFAULT NULL,
                size_y REAL DEFAULT NULL,
                filters TEXT DEFAULT '[]',
                other_names TEXT DEFAULT '',
                description TEXT DEFAULT '',
                star_rating INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS user_prefs (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    /** Convenience: prepare + execute + fetchAll */
    public static function all(string $sql, array $params = []): array {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Convenience: prepare + execute + fetch one row (or null) */
    public static function one(string $sql, array $params = []): ?array {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Convenience: prepare + execute, returns number of affected rows */
    public static function exec(string $sql, array $params = []): int {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Convenience: get last insert ID */
    public static function lastId(): string {
        return self::get()->lastInsertId();
    }
}
