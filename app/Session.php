<?php

declare(strict_types=1);

namespace App;

final class Session
{
    private static bool $enforcingIdle = false;

    /** Stripe-style idle timeout. Override with SESSION_IDLE_SECONDS in .env */
    public static function idleSeconds(): int
    {
        $raw = Env::get('SESSION_IDLE_SECONDS', '3600');
        $seconds = is_string($raw) ? (int) $raw : 3600;
        return $seconds > 60 ? $seconds : 3600;
    }

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $idle = self::idleSeconds();
            session_name('mf_session');
            session_set_cookie_params([
                'lifetime' => $idle,
                'path' => '/',
                'secure' => self::cookieSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        self::enforceIdle();
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_email'] = (string) $user['email'];
        $_SESSION['user_plan'] = (string) $user['plan'];
        $_SESSION['subscription_status'] = (string) $user['subscription_status'];
        $_SESSION['last_activity'] = time();
        self::refreshSessionCookie();
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (session_status() === PHP_SESSION_NONE) {
                session_name('mf_session');
                @session_start();
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        self::start();
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }

        $out = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return is_string($out) ? $out : null;
    }

    /**
     * End the session when idle past the limit. Sliding last_activity on use.
     */
    public static function enforceIdle(): void
    {
        if (self::$enforcingIdle) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $now = time();
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        $idle = self::idleSeconds();

        if ($last > 0 && ($now - $last) > $idle) {
            self::$enforcingIdle = true;
            self::markIdleExpiredCookie();
            self::logout();
            self::$enforcingIdle = false;
            return;
        }

        $_SESSION['last_activity'] = $now;
        self::refreshSessionCookie();
    }

    public static function consumeIdleExpiredFlag(): bool
    {
        if (empty($_COOKIE['mf_idle'])) {
            return false;
        }
        setcookie('mf_idle', '', time() - 42000, '/', '', self::cookieSecure(), true);
        unset($_COOKIE['mf_idle']);
        return true;
    }

    private static function markIdleExpiredCookie(): void
    {
        setcookie('mf_idle', '1', [
            'expires' => time() + 300,
            'path' => '/',
            'secure' => self::cookieSecure(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private static function refreshSessionCookie(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $idle = self::idleSeconds();
        setcookie(session_name(), session_id(), [
            'expires' => time() + $idle,
            'path' => '/',
            'secure' => self::cookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function cookieSecure(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /** Release the session write lock so other requests can proceed. */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
