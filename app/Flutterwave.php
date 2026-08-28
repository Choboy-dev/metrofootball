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
