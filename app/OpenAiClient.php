<?php

declare(strict_types=1);

namespace App;

final class OpenAiClient
{
    private const CHAT_URL = 'https://api.openai.com/v1/chat/completions';

    public static function configured(): bool
    {
        $key = Env::get('OPENAI_API_KEY');
        return is_string($key) && $key !== '';
    }

    public static function model(): string
    {
        return Env::get('OPENAI_MODEL', 'gpt-4o-mini') ?: 'gpt-4o-mini';
    }

    /**
     * Chat Completions with strict JSON schema output.
     *
     * @param list<array{role:string,content:string}> $messages
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function chatJson(
        array $messages,
        string $schemaName,
        array $schema,
        int $timeoutSeconds = 45
    ): array
    {
        if (!self::configured()) {
            throw new \RuntimeException(
                'OpenAI is not configured. Add OPENAI_API_KEY in config/.env'
            );
        }

        $payload = [
            'model' => self::model(),
            'temperature' => 0.2,
            'messages' => $messages,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timeout = max(20, min(180, $timeoutSeconds));

        $ch = curl_init(self::CHAT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . Env::require('OPENAI_API_KEY'),
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Could not reach OpenAI: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenAI returned invalid JSON.');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error']['message'] ?? 'OpenAI request failed.');
            throw new \RuntimeException($message);
        }

        $message = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \RuntimeException('OpenAI returned an empty response.');
        }

        if (!empty($message['refusal'])) {
            throw new \RuntimeException('OpenAI refused to analyse this squad.');
        }

        $content = (string) ($message['content'] ?? '');
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException('OpenAI returned a response that was not valid JSON.');
        }

        return $parsed;
    }
}
