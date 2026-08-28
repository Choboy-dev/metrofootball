<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Env;
use App\GameweekTipsService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

@set_time_limit(180);

$body = mf_read_json();
$instructions = trim((string) ($body['instructions'] ?? ''));
$elementId = isset($body['element_id']) ? (int) $body['element_id'] : 0;
$gameweek = isset($body['gameweek']) ? (int) $body['gameweek'] : 0;

if ($instructions === '') {
    Response::json(['ok' => false, 'error' => 'Edit instructions are required.'], 422);
}

$secret = Env::get('GAMEWEEK_TIPS_GENERATE_SECRET');
if (!is_string($secret) || $secret === '') {
    $secret = Env::get('INJURY_NEWS_GENERATE_SECRET');
}
if (!is_string($secret) || $secret === '') {
    Response::json(['ok' => false, 'error' => 'Edit is locked. Set GAMEWEEK_TIPS_GENERATE_SECRET in config/.env'], 403);
}

$provided = (string) ($body['secret'] ?? ($_SERVER['HTTP_X_GW_TIPS_SECRET'] ?? $_SERVER['HTTP_X_INJURY_SECRET'] ?? ''));
if (!hash_equals($secret, $provided)) {
    Response::json(['ok' => false, 'error' => 'Generate secret is required.'], 403);
}

try {
    $post = GameweekTipsService::edit(
        $instructions,
        $elementId > 0 ? $elementId : null,
        $gameweek > 0 ? $gameweek : null
    );
    Response::json(['ok' => true, 'post' => $post]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
