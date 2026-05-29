<?php
define('DB_PATH', __DIR__ . '/../data/travelling.db');

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $dataDir = dirname(DB_PATH);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
            file_put_contents($dataDir . '/.htaccess', 'Deny from all');
        }
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        initSchema($db);
    }
    return $db;
}

function initSchema(PDO $db): void {
    $db->exec("PRAGMA foreign_keys = ON");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS artists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        links TEXT NOT NULL DEFAULT '',
        about TEXT NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS merch_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        image_filename TEXT NOT NULL DEFAULT '',
        title TEXT NOT NULL,
        size TEXT NOT NULL DEFAULT '',
        price TEXT NOT NULL DEFAULT '',
        about TEXT NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS tracks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL,
        title TEXT NOT NULL,
        artist TEXT NOT NULL DEFAULT '',
        release_date TEXT NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS guest_releases (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        track_id TEXT NOT NULL,
        artist_name TEXT NOT NULL,
        artwork_filename TEXT NOT NULL DEFAULT '',
        about TEXT NOT NULL DEFAULT '',
        mp3_filename TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS soundcloud_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL DEFAULT '',
        sc_user_id TEXT NOT NULL DEFAULT '',
        last_synced DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS soundcloud_tracks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        profile_id INTEGER NOT NULL REFERENCES soundcloud_profiles(id) ON DELETE CASCADE,
        sc_track_id TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        artist TEXT NOT NULL DEFAULT '',
        artwork_url TEXT NOT NULL DEFAULT '',
        permalink_url TEXT NOT NULL,
        duration INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Insert defaults only once
    $check = $db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($check == 0) {
        $token = bin2hex(random_bytes(16));
        $hash  = password_hash('admin123', PASSWORD_DEFAULT);
        $defaults = [
            ['admin_password', $hash],
            ['admin_token',    $token],
            ['sc_client_id',   ''],
            ['site_title',     'travelling music™'],
        ];
        $ins = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
        foreach ($defaults as [$k, $v]) {
            $ins->execute([$k, $v]);
        }
    }
}

function getSetting(string $key): string {
    $stmt = getDB()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    return (string)($stmt->fetchColumn() ?: '');
}

function setSetting(string $key, string $value): void {
    $stmt = getDB()->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}
