<?php

declare(strict_types=1);

namespace App;

final class GoogleOAuth
{
    public static function isConfigured(): bool
    {
        return Env::get('GOOGLE_CLIENT_ID') !== null
            && Env::get('GOOGLE_CLIENT_ID') !== ''
            && Env::get('GOOGLE_CLIENT_SECRET') !== null
            && Env::get('GOOGLE_CLIENT_SECRET') !== '';
    }

    public static function redirectUri(): string
    {
        $configured = Env::get('GOOGLE_REDIRECT_URI');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return AppUrl::to('api/auth/google/callback');
    }

    public static function authorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => Env::require('GOOGLE_CLIENT_ID'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    /** @return array{id:string,email:string,name:?string} */
    public static function exchangeCode(string $code): array
    {
        $tokenPayload = http_build_query([
            'code' => $code,
            'client_id' => Env::require('GOOGLE_CLIENT_ID'),
            'client_secret' => Env::require('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        $tokenResponse = self::httpPost('https://oauth2.googleapis.com/token', $tokenPayload, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $tokenJson = json_decode($tokenResponse, true);
        if (!is_array($tokenJson) || empty($tokenJson['access_token'])) {
            throw new \RuntimeException('Google sign in failed at token exchange.');
        }

        $accessToken = (string) $tokenJson['access_token'];
        $profileRaw = self::httpGet('https://openidconnect.googleapis.com/v1/userinfo', [
            'Authorization: Bearer ' . $accessToken,
        ]);
        $profile = json_decode($profileRaw, true);

        if (!is_array($profile) || empty($profile['email']) || empty($profile['sub'])) {
            throw new \RuntimeException('Google did not return an email address.');
        }

        return [
            'id' => (string) $profile['sub'],
            'email' => (string) $profile['email'],
            'name' => isset($profile['name']) ? (string) $profile['name'] : null,
        ];
    }

    private static function httpPost(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Could not reach Google: ' . $error);
        }

        return $response;
    }

    private static function httpGet(string $url, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Could not reach Google: ' . $error);
        }

        return $response;
    }
}
