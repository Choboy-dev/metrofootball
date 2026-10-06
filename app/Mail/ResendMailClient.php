<?php

declare(strict_types=1);

namespace App\Mail;

use App\Env;

final class ResendMailClient
{
    private const API_BASE = 'https://api.resend.com';

    public static function sendEmail(array $payload): array
    {
        return self::request('POST', '/emails', $payload);
    }

    public static function cancelEmail(string $emailId): array
    {
        return self::request('POST', '/emails/' . rawurlencode($emailId) . '/cancel');
    }

    public static function getReceivedEmail(string $emailId): array
    {
        return self::request('GET', '/emails/receiving/' . rawurlencode($emailId));
    }

    public static function getReceivedAttachment(string $emailId, string $attachmentId): array
    {
        return self::request(
            'GET',
            '/emails/receiving/' . rawurlencode($emailId) . '/attachments/' . rawurlencode($attachmentId)
        );
    }

    public static function listReceivedEmails(int $limit = 50, ?string $after = null): array
    {
        $limit = max(1, min(100, $limit));
        $path = '/emails/receiving?limit=' . $limit;
        if ($after !== null && $after !== '') {
            $path .= '&after=' . rawurlencode($after);
        }

        return self::request('GET', $path);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function verifyWebhook(string $payload, array $headers): bool
    {
        $secret = Env::get('RESEND_WEBHOOK_SECRET');
        if ($secret === null || $secret === '') {
            $env = strtolower((string) Env::get('APP_ENV', 'production'));
            if ($env !== 'production') {
                return true;
            }
            error_log('[resend-mail webhook] RESEND_WEBHOOK_SECRET is not configured in production.');
            return false;
        }

        $msgId = $headers['svix-id'] ?? $headers['Svix-Id'] ?? '';
        $timestamp = $headers['svix-timestamp'] ?? $headers['Svix-Timestamp'] ?? '';
        $signature = $headers['svix-signature'] ?? $headers['Svix-Signature'] ?? '';

        if ($msgId === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        $ts = (int) $timestamp;
        if ($ts <= 0 || abs(time() - $ts) > 300) {
            return false;
        }

        $secretBytes = self::decodeWebhookSecret($secret);
        if ($secretBytes === null) {
            return false;
        }

        $signedContent = $msgId . '.' . $timestamp . '.' . $payload;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));

        foreach (preg_split('/\s+/', trim($signature)) ?: [] as $part) {
            $pieces = explode(',', $part, 2);
            if (count($pieces) === 2 && $pieces[0] === 'v1' && hash_equals($expected, $pieces[1])) {
                return true;
            }
        }

        return false;
    }

    private static function decodeWebhookSecret(string $secret): ?string
    {
        $encoded = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $bytes = base64_decode($encoded, true);
        return $bytes === false ? null : $bytes;
    }

    private static function apiKey(): string
    {
        $key = Env::get('RESEND_API_KEY');
        if ($key === null || $key === '') {
            throw new \RuntimeException('RESEND_API_KEY is not configured.');
        }
        return $key;
    }

    private static function request(string $method, string $path, ?array $payload = null): array
    {
        $url = self::API_BASE . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . self::apiKey(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Could not reach Resend: ' . $error);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $body];
        }

        if ($status < 200 || $status >= 300) {
            $message = is_string($decoded['message'] ?? null)
                ? (string) $decoded['message']
                : 'Resend request failed with status ' . $status;
            throw new \RuntimeException($message);
        }

        return $decoded;
    }
}
