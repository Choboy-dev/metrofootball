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
        self::ensureColumn($pdo, 'users', 'subscription_reminder_7d_at', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'subscription_reminder_1d_at', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'subscription_expired_email_at', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'fpl_team_id', 'INTEGER NULL');
        self::ensureColumn($pdo, 'users', 'fpl_team_name', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'fpl_manager_name', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'fpl_linked_at', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'is_admin', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'users', 'is_restricted', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'users', 'restricted_reason', 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'admin_note', 'TEXT NULL');
        self::ensureColumn($pdo, 'payments', 'charge_amount', 'REAL NULL');
        self::ensureColumn($pdo, 'payments', 'charge_currency', 'TEXT NULL');
        self::backfillPaymentChargeColumns($pdo);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ai_usage_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                service TEXT NOT NULL,
                provider TEXT NOT NULL,
                model TEXT NULL,
                tokens_in INTEGER NOT NULL DEFAULT 0,
                tokens_out INTEGER NOT NULL DEFAULT 0,
                cost_usd REAL NOT NULL DEFAULT 0,
                meta_json TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_ai_usage_log_created ON ai_usage_log(created_at DESC)'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                target_user_id INTEGER NULL,
                detail_json TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

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

    private static function backfillPaymentChargeColumns(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT id, amount, currency, billing_cycle, charge_amount, charge_currency
             FROM payments
             WHERE charge_amount IS NULL OR charge_currency IS NULL OR currency = 'NGN'"
        );
        if ($stmt === false) {
            return;
        }

        $update = $pdo->prepare(
            'UPDATE payments
             SET amount = ?, currency = ?, charge_amount = ?, charge_currency = ?
             WHERE id = ?'
        );

        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }

            $storedCurrency = strtoupper((string) ($row['currency'] ?? 'GBP'));
            $storedAmount = (float) ($row['amount'] ?? 0);
            $cycle = strtolower((string) ($row['billing_cycle'] ?? 'monthly')) === 'yearly' ? 'yearly' : 'monthly';
            $gbpAmount = $cycle === 'yearly' ? 38.5 : 4.0;

            if ($storedCurrency === 'NGN') {
                $update->execute([$gbpAmount, 'GBP', $storedAmount, 'NGN', (int) $row['id']]);
                continue;
            }

            if ($row['charge_amount'] === null || $row['charge_currency'] === null) {
                $update->execute([$storedAmount, 'GBP', $storedAmount, 'GBP', (int) $row['id']]);
            }
        }
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
