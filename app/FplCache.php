<?php

declare(strict_types=1);

namespace App;

final class FplCache
{
    public static function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $hit = self::inspect($key, $ttlSeconds);
        if ($hit !== null) {
            return $hit['value'];
        }

        $value = $producer();
        self::put($key, $value);
        return $value;
    }

    /**
     * Prefer fresh cache. On producer failure, serve stale up to $staleSeconds.
     */
    public static function rememberFreshOrStale(
        string $key,
        int $freshSeconds,
        int $staleSeconds,
        callable $producer
    ): mixed {
        $hit = self::inspect($key, $freshSeconds, $staleSeconds);
        if ($hit !== null && $hit['fresh']) {
            return $hit['value'];
        }

        try {
            $value = $producer();
            self::put($key, $value);
            return $value;
        } catch (\Throwable $e) {
            if ($hit !== null) {
                return $hit['value'];
            }
            throw $e;
        }
    }

    /**
     * @return null|array{value:mixed,age:int,fresh:bool}
     */
    public static function inspect(string $key, int $freshSeconds, ?int $maxAgeSeconds = null): ?array
    {
        $path = self::path($key);
        if (!is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);
        if ($mtime === false) {
            return null;
        }

        $age = time() - $mtime;
        $limit = $maxAgeSeconds ?? $freshSeconds;
        if ($age >= $limit) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('value', $decoded)) {
            return null;
        }

        return [
            'value' => $decoded['value'],
            'age' => $age,
            'fresh' => $age < $freshSeconds,
        ];
    }

    public static function get(string $key, int $ttlSeconds): mixed
    {
        $hit = self::inspect($key, $ttlSeconds);
        return $hit['value'] ?? null;
    }

    public static function put(string $key, mixed $value): void
    {
        $dir = dirname(self::path($key));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $encoded = json_encode(['saved_at' => gmdate('c'), 'value' => $value], $flags);
        if ($encoded === false) {
            throw new \RuntimeException('Could not encode cache payload for ' . $key . ': ' . json_last_error_msg());
        }

        $path = self::path($key);
        $tmp = $path . '.tmp.' . getmypid();
        $written = file_put_contents($tmp, $encoded, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write cache file for ' . $key);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            // Fallback for hosts where rename across mounts fails.
            if (file_put_contents($path, $encoded, LOCK_EX) === false) {
                throw new \RuntimeException('Could not replace cache file for ' . $key);
            }
        }
    }

    public static function forget(string $key): void
    {
        $path = self::path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function path(string $key): string
    {
        $root = dirname(__DIR__);
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $key);
        return $root . '/storage/cache/' . $safe . '.json';
    }
}
