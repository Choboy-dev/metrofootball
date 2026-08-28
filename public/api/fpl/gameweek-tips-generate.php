<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Env;
use App\GameweekTipsService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

@set_time_limit(300);
ignore_user_abort(true);

$body = mf_read_json();
$force = !empty($body['force']) || (isset($_GET['force']) && $_GET['force'] !== '0');
$forceResearch = !empty($body['force_research']) || (isset($_GET['force_research']) && $_GET['force_research'] !== '0');
$researchOnly = !empty($body['research_only']);
$writeOnly = !empty($body['write_only']);

$secret = Env::get('GAMEWEEK_TIPS_GENERATE_SECRET');
if (!is_string($secret) || $secret === '') {
    $secret = Env::get('INJURY_NEWS_GENERATE_SECRET');
}
if (!is_string($secret) || $secret === '') {
    Response::json([
        'ok' => false,
        'error' => 'Generate is locked. Set GAMEWEEK_TIPS_GENERATE_SECRET in config/.env, or run scripts/publish-gameweek-tips.php',
    ], 403);
}

$provided = (string) ($body['secret'] ?? ($_SERVER['HTTP_X_GW_TIPS_SECRET'] ?? $_SERVER['HTTP_X_INJURY_SECRET'] ?? ''));
if (!hash_equals($secret, $provided)) {
    Response::json(['ok' => false, 'error' => 'Generate secret is required.'], 403);
}

try {
    if ($researchOnly) {
        $research = GameweekTipsService::research($forceResearch);
        Response::json([
            'ok' => true,
            'research' => $research,
            'tavily_calls' => $research['tavily_calls'] ?? 0,
        ]);
    }

    if ($writeOnly) {
        $post = GameweekTipsService::publishFromResearch($force);
        Response::json([
            'ok' => true,
            'needs_generate' => false,
            'post' => $post,
            'openai_only' => true,
        ]);
    }

    $post = GameweekTipsService::generate($force, $forceResearch);
    Response::json([
        'ok' => true,
        'needs_generate' => false,
        'post' => $post,
        'tavily_calls' => $post['cost_controls']['tavily_calls'] ?? 0,
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
