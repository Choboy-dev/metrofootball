<?php

declare(strict_types=1);

namespace App\Mail;

use App\Database;
use App\Env;
use PDO;

final class MailSession
{
    private const SESSION_NAME = 'mf_mail_session';
    private const MAX_ATTEMPTS = 8;
    private const WINDOW_SECONDS = 900;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && session_name() !== self::SESSION_NAME) {
            session_write_close();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name(self::SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => self::cookieSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function login(int $userId, string $email): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['mail_user_id'] = $userId;
        $_SESSION['mail_user_email'] = strtolower(trim($email));
        self::clearLoginAttempts(self::clientIp());
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (session_status() === PHP_SESSION_NONE) {
                session_name(self::SESSION_NAME);
                @session_start();
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['mail_user_id']) ? (int) $_SESSION['mail_user_id'] : null;
    }

    public static function userEmail(): ?string
    {
        self::start();
        $email = $_SESSION['mail_user_email'] ?? null;
        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        self::start();
        if ($value !== null) {
            $_SESSION['_mail_flash'][$key] = $value;
            return null;
        }

        $out = $_SESSION['_mail_flash'][$key] ?? null;
        unset($_SESSION['_mail_flash'][$key]);
        return is_string($out) ? $out : null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function isLoginRateLimited(): bool
    {
        return self::loginAttemptCount(self::clientIp()) >= self::MAX_ATTEMPTS;
    }

    public static function recordFailedLogin(): void
    {
        $ip = self::clientIp();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('INSERT INTO mail_login_attempts (ip_address, attempted_at) VALUES (?, ?)');
        $stmt->execute([$ip, gmdate('c')]);
        self::pruneLoginAttempts($ip);
        usleep(random_int(200000, 600000));
    }

    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private static function loginAttemptCount(string $ip): int
    {
        self::pruneLoginAttempts($ip);
        $pdo = Database::pdo();
        $cutoff = gmdate('c', time() - self::WINDOW_SECONDS);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM mail_login_attempts WHERE ip_address = ? AND attempted_at >= ?'
        );
        $stmt->execute([$ip, $cutoff]);
        return (int) $stmt->fetchColumn();
    }

    private static function pruneLoginAttempts(string $ip): void
    {
        $pdo = Database::pdo();
        $cutoff = gmdate('c', time() - self::WINDOW_SECONDS);
        $stmt = $pdo->prepare('DELETE FROM mail_login_attempts WHERE ip_address = ? AND attempted_at < ?');
        $stmt->execute([$ip, $cutoff]);
    }

    private static function clearLoginAttempts(string $ip): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM mail_login_attempts WHERE ip_address = ?');
        $stmt->execute([$ip]);
    }

    private static function clientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (is_string($ip) && str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip, 2)[0]);
        }
        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }

    private static function cookieSecure(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}
