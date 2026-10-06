<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;

mail_send_security_headers();

$user = MailService::requireUser();
$userId = mail_user_id($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mail_url());
    exit;
}

require_csrf();

$action = (string) ($_POST['action'] ?? '');
$returnTo = MailService::safeReturnUrl((string) ($_POST['return_to'] ?? mail_url()));
$ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));

$jsonActions = ['star', 'discard_draft', 'cancel_schedule'];

if (in_array($action, $jsonActions, true)) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        switch ($action) {
            case 'star':
                $messageId = (int) ($_POST['message_id'] ?? 0);
                $starred = MailService::toggleStar($userId, $messageId);
                echo json_encode(['ok' => true, 'starred' => $starred], JSON_THROW_ON_ERROR);
                exit;

            case 'discard_draft':
                $draftId = (int) ($_POST['draft_id'] ?? 0);
                MailService::deleteMessages($userId, [$draftId], true);
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
                exit;

            case 'cancel_schedule':
                $messageId = (int) ($_POST['message_id'] ?? 0);
                MailService::cancelScheduled($userId, $messageId);
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
                exit;
        }
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        exit;
    }
}

if ($ids === []) {
    mail_flash('error', 'Select at least one message.');
    header('Location: ' . $returnTo);
    exit;
}

try {
    switch ($action) {
        case 'archive':
            MailService::moveMessages($userId, $ids, 'archive');
            mail_flash('success', 'Messages archived.');
            break;

        case 'trash':
            MailService::moveMessages($userId, $ids, 'trash');
            mail_flash('success', 'Messages moved to trash.');
            break;

        case 'delete':
            MailService::deleteMessages($userId, $ids, true);
            mail_flash('success', 'Messages deleted.');
            break;

        case 'spam':
            MailService::moveMessages($userId, $ids, 'spam');
            mail_flash('success', 'Messages marked as spam.');
            break;

        case 'not_spam':
            MailService::moveMessages($userId, $ids, 'inbox');
            mail_flash('success', 'Messages moved to inbox.');
            break;

        case 'mark_read':
            MailService::markRead($userId, $ids, true);
            mail_flash('success', 'Messages marked as read.');
            break;

        case 'mark_unread':
            MailService::markRead($userId, $ids, false);
            mail_flash('success', 'Messages marked as unread.');
            break;

        default:
            throw new \InvalidArgumentException('Unknown action.');
    }
} catch (\Throwable $e) {
    mail_flash('error', $e->getMessage());
}

header('Location: ' . $returnTo);
exit;
