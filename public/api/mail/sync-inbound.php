<?php

declare(strict_types=1);

/**
 * Backfill inbound mail from Resend when webhooks were missed.
 * POST /api/mail/sync-inbound.php
 * Header: X-Mail-Sync-Token: hash from APP_KEY (see scripts/sync-mail-inbound.php)
 */

$root = dirname(__DIR__, 3);

require_once $root . '/app/Env.php';
require_once $root . '/app/AppUrl.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/Mail/MailSession.php';
require_once $root . '/app/Mail/MailCsrf.php';
require_once $root . '/app/Mail/MailHtml.php';
require_once $root . '/app/Mail/ResendMailClient.php';
require_once $root . '/app/Mail/MailService.php';

use App\Env;
use App\Mail\MailService;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

Env::load($root . '/config/.env');
MailService::ensureStorage();

$appKey = (string) Env::get('APP_KEY', '');
$setupToken = trim((string) Env::get('MAIL_SETUP_TOKEN', ''));
$provided = trim((string) ($_SERVER['HTTP_X_MAIL_SYNC_TOKEN'] ?? $_SERVER['HTTP_X_MAIL_SETUP_TOKEN'] ?? ''));

$expectedSync = $appKey !== '' ? hash_hmac('sha256', 'metro-mail-sync-inbound', $appKey) : '';
$allowed = ($setupToken !== '' && hash_equals($setupToken, $provided))
    || ($expectedSync !== '' && hash_equals($expectedSync, $provided));

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $pages = max(1, min(20, (int) ($_GET['pages'] ?? 5)));
    $result = MailService::syncInboundFromResend($pages);
    $resanitized = MailService::resanitizeStoredInboundBodies();
    echo json_encode([
        ...$result,
        'resanitized' => (int) ($resanitized['updated'] ?? 0),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
