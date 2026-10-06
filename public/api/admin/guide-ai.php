<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-admin.php';

use App\AdminService;
use App\GuideAiAssistService;
use App\OpenAiClient;
use App\Response;
use App\Session;
use App\TavilyClient;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    AdminService::requireAdmin();

    if (!OpenAiClient::configured()) {
        Response::json([
            'ok' => false,
            'error' => 'OpenAI is not configured. Add OPENAI_API_KEY in config/.env',
        ], 503);
    }

    $body = mf_read_json();
    $instruction = trim((string) ($body['instruction'] ?? ''));
    $images = is_array($body['images'] ?? null) ? $body['images'] : [];
    $texts = is_array($body['texts'] ?? null) ? $body['texts'] : [];
    $research = !empty($body['research']);

    if ($research && !TavilyClient::configured()) {
        Response::json([
            'ok' => false,
            'error' => 'Web research needs TAVILY_API_KEY in config/.env',
        ], 503);
    }

    $result = GuideAiAssistService::generate(
        $instruction,
        $images,
        $texts,
        $research,
        Session::userId() > 0 ? Session::userId() : null
    );

    $sources = $result['research']['sources'] ?? [];
    $sourceCount = is_array($sources) ? count($sources) : 0;

    Response::json([
        'ok' => true,
        'blocks' => $result['blocks'],
        'count' => count($result['blocks']),
        'research' => [
            'enabled' => !empty($result['research']['enabled']),
            'queries' => $result['research']['queries'] ?? [],
            'source_count' => $sourceCount,
            'tavily_calls' => (int) ($result['research']['tavily_calls'] ?? 0),
            'sources' => array_values(array_filter(array_map(static function ($row) {
                if (!is_array($row)) {
                    return null;
                }
                return [
                    'title' => (string) ($row['title'] ?? ''),
                    'url' => (string) ($row['url'] ?? ''),
                    'outlet' => (string) ($row['outlet'] ?? ''),
                    'tier' => (string) ($row['tier'] ?? ''),
                ];
            }, is_array($sources) ? array_slice($sources, 0, 8) : []))),
        ],
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
}
