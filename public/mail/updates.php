<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;
use App\Mail\MailSession;

mail_send_security_headers();
header('Content-Type: application/json; charset=utf-8');

$user = MailService::requireUser();
MailSession::release();

try {
    $state = MailService::inboxState(mail_user_id($user));
    echo json_encode([
        'ok' => true,
        'latest_id' => (int) ($state['latest_id'] ?? 0),
        'unread' => (int) ($state['unread'] ?? 0),
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
