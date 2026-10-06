<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/app/Env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/EmailTemplate.php';
require_once $root . '/app/ResendMailer.php';
require_once $root . '/app/AdminNotificationService.php';
require_once $root . '/app/Response.php';
require_once $root . '/app/AppUrl.php';
require_once $root . '/app/Session.php';
require_once $root . '/app/AuthService.php';
require_once $root . '/app/GoogleOAuth.php';
require_once $root . '/app/Flutterwave.php';
require_once $root . '/app/VisitorCountry.php';
require_once $root . '/app/BillingService.php';
require_once $root . '/app/FplClient.php';
require_once $root . '/app/FplCache.php';
require_once $root . '/app/TeamLinkService.php';
require_once $root . '/app/LiveTeamService.php';
require_once $root . '/app/PickTeamService.php';
require_once $root . '/app/FplEntryPicksService.php';
require_once $root . '/app/PickDraftService.php';
require_once $root . '/app/OpenAiClient.php';
require_once $root . '/app/AiUsageLogger.php';
require_once $root . '/app/PlayerAiScoreStore.php';
require_once $root . '/app/PlayerProjectionEngine.php';
require_once $root . '/app/PlayerAiScout.php';
require_once $root . '/app/TeamScoreComposer.php';
require_once $root . '/app/TransferSuggestService.php';
require_once $root . '/app/TeamAiService.php';
require_once $root . '/app/AiTipsService.php';
require_once $root . '/app/AiTipsExtrasService.php';
require_once $root . '/app/FplGuides/ArticleLoader.php';
require_once $root . '/app/FplGuides/ArticleToc.php';
require_once $root . '/app/FplGuides/ArticleLinker.php';
require_once $root . '/app/FplGuides/GuideBlockRenderer.php';
require_once $root . '/app/GuidePostService.php';
require_once $root . '/app/GuideAuthor.php';
require_once $root . '/app/FplGuidesService.php';
require_once $root . '/app/FixtureDifficultyService.php';
require_once $root . '/app/LeagueTableService.php';
require_once $root . '/app/AiTeamsService.php';
require_once $root . '/app/AiSeasonService.php';
require_once $root . '/app/ChipStrategyService.php';
require_once $root . '/app/TrackedLeagueService.php';
require_once $root . '/app/RivalProfileService.php';
require_once $root . '/app/MiniLeagueService.php';
require_once $root . '/app/GameweekRecapService.php';
require_once $root . '/app/PriceChangeService.php';
require_once $root . '/app/PlayersService.php';
require_once $root . '/app/PlayerProfileService.php';
require_once $root . '/app/PlayerScoutReport.php';
require_once $root . '/app/OptaPlayerLinks.php';
require_once $root . '/app/TavilyClient.php';
require_once $root . '/app/InjuryNewsService.php';
require_once $root . '/app/GameweekTipsService.php';
require_once $root . '/app/PickPageService.php';

use App\Env;

Env::load($root . '/config/.env');

header('X-Content-Type-Options: nosniff');

function mf_read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST ?: [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
