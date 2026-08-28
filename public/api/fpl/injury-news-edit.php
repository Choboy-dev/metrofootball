<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Env;
use App\InjuryNewsService;
use App\Response;

/**
 * Edit published injury news using stored research only. No Tavily.
 * POST body: { "secret": "...", "instructions": "...", "element_id": 480 }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

@set_time_limit(180);

$body = mf_read_json();
$secret = Env::get('INJURY_NEWS_GENERATE_SECRET');
if (!is_string($secret) || $secret === '') {
    Response::json([
        'ok' => false,
        'error' => 'Edit is locked. Set INJURY_NEWS_GENERATE_SECRET or use scripts/publish-injury-news.php --edit',
    ], 403);
}

$provided = (string) ($body['secret'] ?? ($_SERVER['HTTP_X_INJURY_SECRET'] ?? ''));
if (!hash_equals($secret, $provided)) {
    Response::json(['ok' => false, 'error' => 'Generate secret is required.'], 403);
}

$instructions = trim((string) ($body['instructions'] ?? ''));
$elementId = isset($body['element_id']) ? (int) $body['element_id'] : null;
if ($elementId !== null && $elementId < 1) {
    $elementId = null;
}

try {
    $post = InjuryNewsService::edit($instructions, $elementId);
    Response::json([
        'ok' => true,
        'post' => $post,
        'openai_only' => true,
        'used_stored_research' => true,
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
