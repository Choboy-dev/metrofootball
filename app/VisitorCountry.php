<?php

declare(strict_types=1);

namespace App;

final class VisitorCountry
{
    private const NIGERIA = 'NG';

    /** @var array<string, string|null> */
    private static array $resolved = [];

    public static function isNigeria(?string $ip = null): bool
    {
        return self::code($ip) === self::NIGERIA;
    }

    public static function paymentOptions(?string $ip = null): string
    {
        return self::isNigeria($ip)
            ? 'card,banktransfer,ussd,account,nqr,opay'
            : 'card';
    }

    public static function code(?string $ip = null): ?string
    {
        $ip = $ip ?? self::clientIp();
        $cacheKey = $ip ?? '_none';

        if (array_key_exists($cacheKey, self::$resolved)) {
            return self::$resolved[$cacheKey];
        }

        $code = self::fromHeaders();
        if ($code === null && $ip !== null) {
            $code = self::lookupIp($ip);
        }

        self::$resolved[$cacheKey] = $code;

        return $code;
    }

    public static function clientIp(): ?string
    {
        $candidates = [];

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
                $candidates[] = trim($part);
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $candidates[] = trim((string) $_SERVER['HTTP_X_REAL_IP']);
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = trim((string) $_SERVER['REMOTE_ADDR']);
        }

        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return null;
    }

    private static function fromHeaders(): ?string
    {
        $keys = [
            'HTTP_CF_IPCOUNTRY',
            'HTTP_X_COUNTRY_CODE',
            'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
            'HTTP_X_APPENGINE_COUNTRY',
        ];

        foreach ($keys as $key) {
            $code = strtoupper(trim((string) ($_SERVER[$key] ?? '')));
            if (self::isValidCode($code)) {
                return $code;
            }
        }

        return null;
    }

    private static function isValidCode(string $code): bool
    {
        return strlen($code) === 2
            && ctype_alpha($code)
            && !in_array($code, ['XX', 'T1', 'A1', 'A2'], true);
    }

    private static function lookupIp(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $ch = curl_init('https://ipwho.is/' . rawurlencode($ip));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            return null;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || empty($data['success'])) {
            return null;
        }

        $code = strtoupper(trim((string) ($data['country_code'] ?? '')));

        return self::isValidCode($code) ? $code : null;
    }
}
