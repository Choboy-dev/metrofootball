<?php

declare(strict_types=1);

namespace App;

final class Flutterwave
{
    public static function isConfigured(): bool
    {
        $secret = Env::get('FLW_SECRET_KEY');
        return is_string($secret) && $secret !== '';
    }

    public static function isTestKey(?string $key = null): bool
    {
        $key = strtoupper(trim((string) ($key ?? Env::get('FLW_SECRET_KEY') ?? '')));
        if ($key === '') {
            return false;
        }

        return str_contains($key, 'TEST');
    }

    /** Block Flutterwave sandbox keys on production. */
    public static function assertLiveKeysForProduction(): void
    {
        $env = strtolower(trim((string) Env::get('APP_ENV', 'production')));
        if ($env !== 'production' || !self::isTestKey()) {
            return;
        }

        throw new \RuntimeException(
            'Flutterwave test keys are blocked while APP_ENV=production. '
            . 'Switch to live keys (FLWPUBK- and FLWSECK-) in config/.env.'
        );
    }

    public static function secretKey(): string
    {
        return Env::require('FLW_SECRET_KEY');
    }

    public static function publicKey(): ?string
    {
        return Env::get('FLW_PUBLIC_KEY');
    }

    public static function webhookHash(): ?string
    {
        return Env::get('FLW_SECRET_HASH');
    }

    /** @return array{link:string,status:string,message?:string} */
    public static function createPayment(array $payload): array
    {
        $response = self::request('POST', 'https://api.flutterwave.com/v3/payments', $payload);

        if (($response['status'] ?? '') !== 'success' || empty($response['data']['link'])) {
            $message = (string) ($response['message'] ?? 'Could not start Flutterwave checkout.');
            throw new \RuntimeException($message);
        }

        return [
            'link' => (string) $response['data']['link'],
            'status' => (string) $response['status'],
            'message' => isset($response['message']) ? (string) $response['message'] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function verifyTransaction(int|string $transactionId): array
    {
        $response = self::request(
            'GET',
            'https://api.flutterwave.com/v3/transactions/' . rawurlencode((string) $transactionId) . '/verify'
        );

        if (($response['status'] ?? '') !== 'success' || empty($response['data'])) {
            $message = (string) ($response['message'] ?? 'Could not verify Flutterwave payment.');
            throw new \RuntimeException($message);
        }

        return $response['data'];
    }

    /**
     * Live GBP → NGN conversion via Flutterwave /v3/rates (no hardcoded fallback).
     *
     * @return array{ngn:int,ngn_per_gbp:float,fetched_at:string,source:string}
     */
    public static function gbpToNgn(float $gbpAmount): array
    {
        if ($gbpAmount <= 0) {
            throw new \InvalidArgumentException('GBP amount must be positive.');
        }

        $rate = self::cachedNgnPerGbp();
        $ngn = (int) round($gbpAmount * $rate['ngn_per_gbp']);

        return [
            'ngn' => max(1, $ngn),
            'ngn_per_gbp' => $rate['ngn_per_gbp'],
            'fetched_at' => $rate['fetched_at'],
            'source' => $rate['source'],
        ];
    }

    /** @return array{ngn_per_gbp:float,fetched_at:string,source:string} */
    private static function cachedNgnPerGbp(): array
    {
        $cacheFile = dirname(__DIR__) . '/database/billing-ngn-rate.json';
        $ttl = max(300, (int) (Env::get('BILLING_RATE_CACHE_SECONDS') ?: 3600));

        if (is_file($cacheFile)) {
            $raw = file_get_contents($cacheFile);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            if (
                is_array($cached)
                && isset($cached['ngn_per_gbp'], $cached['fetched_at'])
                && is_numeric($cached['ngn_per_gbp'])
            ) {
                $age = time() - strtotime((string) $cached['fetched_at']);
                if ($age >= 0 && $age < $ttl) {
                    return [
                        'ngn_per_gbp' => (float) $cached['ngn_per_gbp'],
                        'fetched_at' => (string) $cached['fetched_at'],
                        'source' => (string) ($cached['source'] ?? 'flutterwave_cache'),
                    ];
                }
            }
        }

        $fresh = self::fetchNgnPerGbpFromApi();
        $payload = [
            'ngn_per_gbp' => $fresh['ngn_per_gbp'],
            'fetched_at' => gmdate('c'),
            'source' => 'flutterwave',
        ];
        file_put_contents($cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        return $payload;
    }

    /** @return array{ngn_per_gbp:float} */
    private static function fetchNgnPerGbpFromApi(): array
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException('Flutterwave is not configured. Add FLW_SECRET_KEY in config/.env');
        }

        $response = self::request(
            'GET',
            'https://api.flutterwave.com/v3/rates?' . http_build_query([
                'from' => 'GBP',
                'to' => 'NGN',
                'amount' => 1,
            ])
        );

        if (($response['status'] ?? '') !== 'success' || empty($response['data']) || !is_array($response['data'])) {
            $message = (string) ($response['message'] ?? 'Could not fetch Flutterwave GBP to NGN rate.');
            throw new \RuntimeException($message);
        }

        $data = $response['data'];
        $ngnPerGbp = self::ngnPerGbpFromRatesPayload($data);
        if ($ngnPerGbp === null) {
            throw new \RuntimeException('Flutterwave returned an unreadable GBP to NGN rate.');
        }

        if (!self::isPlausibleNgnPerGbp($ngnPerGbp)) {
            if (self::isTestKey()) {
                throw new \RuntimeException(
                    'Flutterwave test keys return invalid NGN exchange rates. '
                    . 'Use live Flutterwave keys (FLWPUBK- and FLWSECK-) for accurate Nigerian checkout pricing.'
                );
            }

            throw new \RuntimeException('Flutterwave returned an invalid GBP to NGN exchange rate.');
        }

        return ['ngn_per_gbp' => $ngnPerGbp];
    }

    /** @param array<string,mixed> $data */
    private static function ngnPerGbpFromRatesPayload(array $data): ?float
    {
        $to = is_array($data['to'] ?? null) ? $data['to'] : null;
        $from = is_array($data['from'] ?? null) ? $data['from'] : null;

        if ($to !== null && ($to['currency'] ?? '') === 'NGN' && is_numeric($to['amount'] ?? null)) {
            $fromAmount = is_numeric($from['amount'] ?? null) ? (float) $from['amount'] : 1.0;
            if ($fromAmount > 0) {
                return (float) $to['amount'] / $fromAmount;
            }
        }

        if (isset($data['rate']) && is_numeric($data['rate']) && (float) $data['rate'] > 0) {
            return (float) $data['rate'];
        }

        $destAmount = is_array($data['destination'] ?? null) ? (float) ($data['destination']['amount'] ?? 0) : 0.0;
        $srcAmount = is_array($data['source'] ?? null) ? (float) ($data['source']['amount'] ?? 0) : 0.0;
        if ($srcAmount > 0 && $destAmount > 0 && ($data['source']['currency'] ?? '') === 'GBP') {
            return $destAmount / $srcAmount;
        }

        return null;
    }

    private static function isPlausibleNgnPerGbp(float $rate): bool
    {
        return $rate >= 500.0 && $rate <= 5000.0;
    }

    /** @return array<string, mixed> */
    private static function request(string $method, string $url, ?array $payload = null): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . self::secretKey(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Could not reach Flutterwave: ' . $error);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Unexpected Flutterwave response (HTTP ' . $code . ').');
        }

        return $decoded;
    }
}
