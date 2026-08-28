<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = dirname(__DIR__) . '/database/auth.sqlite';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        self::$pdo->exec('PRAGMA foreign_keys=ON');
        self::migrate(self::$pdo);

        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NULL,
                google_id TEXT NULL UNIQUE,
                name TEXT NULL,
                plan TEXT NOT NULL DEFAULT \'free\',
                subscription_status TEXT NOT NULL DEFAULT \'none\',
                email_verified_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pending_signups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                plan TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS otp_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL COLLATE NOCASE,
                code_hash TEXT NOT NULL,
                purpose TEXT NOT NULL DEFAULT \'signup\',
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_confirmations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                tx_ref TEXT NOT NULL UNIQUE,
                flw_tx_id TEXT NULL,
                amount REAL NOT NULL,
                currency TEXT NOT NULL DEFAULT \'GBP\',
                billing_cycle TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                paid_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        self::ensureColumn($pdo, 'users', 'billing_cycle', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'subscription_expires_at', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'fpl_team_id', 'INTEGER NULL');
        self::ensureColumn($pdo, 'users', 'fpl_team_name', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'fpl_manager_name', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'fpl_linked_at', 'TEXT NULL');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tracked_leagues (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                fpl_league_id INTEGER NOT NULL,
                league_name TEXT NOT NULL DEFAULT \'\',
                alerts_enabled INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(user_id, fpl_league_id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pick_drafts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                fpl_entry_id INTEGER NOT NULL,
                gameweek_id INTEGER NOT NULL,
                squad_json TEXT NOT NULL,
                bank REAL NOT NULL DEFAULT 0,
                strength_json TEXT NULL,
                predicted_points_json TEXT NULL,
                saved_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(user_id, fpl_entry_id, gameweek_id)
            )'
        );
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $existing = [];
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $row) {
            $existing[] = (string) $row['name'];
        }

        if (!in_array($column, $existing, true)) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
