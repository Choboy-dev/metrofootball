<?php

declare(strict_types=1);

namespace App;

final class AppUrl
{
    public static function base(): string
    {
        $configured = Env::get('APP_URL');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $marker = '/public/';
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            $path = substr($script, 0, $pos);
            return rtrim($scheme . '://' . $host . $path, '/');
        }

        return rtrim($scheme . '://' . $host, '/');
    }

    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}
