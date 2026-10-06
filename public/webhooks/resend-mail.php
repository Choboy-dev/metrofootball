<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/app/Env.php';
require_once $root . '/app/AppUrl.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/Mail/MailService.php';
require_once $root . '/app/Mail/ResendMailClient.php';

use App\Env;
use App\Mail\MailService;
use App\Mail\ResendMailClient;

Env::load($root . '/config/.env');
MailService::ensureStorage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty payload']);
    exit;
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$headers = [
    'svix-id' => (string) ($_SERVER['HTTP_SVIX_ID'] ?? ''),
    'svix-timestamp' => (string) ($_SERVER['HTTP_SVIX_TIMESTAMP'] ?? ''),
    'svix-signature' => (string) ($_SERVER['HTTP_SVIX_SIGNATURE'] ?? ''),
];

try {
    if (!ResendMailClient::verifyWebhook($raw, $headers)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
        exit;
    }

    $eventType = (string) ($payload['type'] ?? '');
    if ($eventType !== 'email.received') {
        echo json_encode(['ok' => true, 'skipped' => true, 'type' => $eventType]);
        exit;
    }

    $result = MailService::processInboundWebhook($payload);

    echo json_encode([
        'ok' => true,
        'processed' => !empty($result['message_id']),
        'duplicate' => (bool) ($result['duplicate'] ?? false),
        'reason' => $result['reason'] ?? null,
        'category' => $result['category'] ?? null,
        'message_id' => $result['message_id'] ?? null,
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    error_log('[resend-mail webhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Webhook processing failed']);
}
