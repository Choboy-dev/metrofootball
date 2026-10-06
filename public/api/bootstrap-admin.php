<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/app/Env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/Response.php';
require_once $root . '/app/Session.php';
require_once $root . '/app/AuthService.php';
require_once $root . '/app/BillingService.php';
require_once $root . '/app/Flutterwave.php';
require_once $root . '/app/OpenAiClient.php';
require_once $root . '/app/TavilyClient.php';
require_once $root . '/app/AiUsageLogger.php';
require_once $root . '/app/AdminService.php';
require_once $root . '/app/EmailTemplate.php';
require_once $root . '/app/ResendMailer.php';
require_once $root . '/app/AppUrl.php';
require_once $root . '/app/AdminCampaignService.php';
require_once $root . '/app/FplGuides/ArticleLoader.php';
require_once $root . '/app/FplGuides/GuideBlockRenderer.php';
require_once $root . '/app/GuidePostService.php';
require_once $root . '/app/FplGuidesService.php';
require_once $root . '/app/GuideAiAssistService.php';

use App\Env;
use App\Session;

Env::load($root . '/config/.env');
Session::start();

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function mf_read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST ?: [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
