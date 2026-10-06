<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AiUsageLogger
{
    /** OpenAI gpt-4o-mini list pricing (USD per 1M tokens). */
    private const OPENAI_INPUT_PER_M = 0.15;
    private const OPENAI_OUTPUT_PER_M = 0.60;

    /** Tavily search estimate per call (USD). */
    private const TAVILY_PER_CALL = 0.008;

    public static function logOpenAi(
        string $service,
        ?int $userId,
        string $model,
        int $tokensIn,
        int $tokensOut,
        array $meta = []
    ): void {
        $cost = self::estimateOpenAiCost($model, $tokensIn, $tokensOut);
        self::insert('openai', $service, $model, $userId, $tokensIn, $tokensOut, $cost, $meta);
    }

    public static function logFromOpenAiResponse(
        string $service,
        ?int $userId,
        string $model,
        array $response,
        array $meta = []
    ): void {
        $usage = $response['usage'] ?? [];
        if (!is_array($usage)) {
            return;
        }

        $tokensIn = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensOut = (int) ($usage['completion_tokens'] ?? 0);
        if ($tokensIn === 0 && $tokensOut === 0) {
            return;
        }

        self::logOpenAi($service, $userId, $model, $tokensIn, $tokensOut, $meta);
    }

    public static function logTavily(string $service, ?int $userId, array $meta = []): void
    {
        self::insert('tavily', $service, 'tavily-search', $userId, 0, 0, self::TAVILY_PER_CALL, $meta);
    }

    public static function estimateOpenAiCost(string $model, int $tokensIn, int $tokensOut): float
    {
        $model = strtolower(trim($model));
        $inRate = self::OPENAI_INPUT_PER_M;
        $outRate = self::OPENAI_OUTPUT_PER_M;

        if (str_contains($model, 'gpt-4o') && !str_contains($model, 'mini')) {
            $inRate = 2.50;
            $outRate = 10.00;
        }

        return round(
            ($tokensIn / 1_000_000) * $inRate + ($tokensOut / 1_000_000) * $outRate,
            6
        );
    }

    private static function insert(
        string $provider,
        string $service,
        ?string $model,
        ?int $userId,
        int $tokensIn,
        int $tokensOut,
        float $costUsd,
        array $meta
    ): void {
        try {
            Database::pdo()->prepare(
                'INSERT INTO ai_usage_log (user_id, service, provider, model, tokens_in, tokens_out, cost_usd, meta_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                $service,
                $provider,
                $model,
                $tokensIn,
                $tokensOut,
                $costUsd,
                $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                gmdate('c'),
            ]);
        } catch (\Throwable) {
            // Never break product flows because logging failed.
        }
    }
}
