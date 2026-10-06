<?php

declare(strict_types=1);

namespace App\Mail;

final class MailCsrf
{
    private const SESSION_KEY = '_mail_csrf_token';

    public static function token(): string
    {
        MailSession::start();
        $existing = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        return $token;
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    public static function validate(?string $token): bool
    {
        MailSession::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function require(?string $token): void
    {
        if (!self::validate($token)) {
            http_response_code(419);
            throw new \RuntimeException('Invalid CSRF token.');
        }
    }

    public static function rotate(): string
    {
        MailSession::start();
        unset($_SESSION[self::SESSION_KEY]);
        return self::token();
    }
}
