<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;
use App\Mail\MailSession;

mail_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $target = MailService::safeReturnUrl($_SERVER['REQUEST_URI'] ?? mail_url());
    header('Location: ' . $target);
    exit;
}

$user = MailService::requireUser();
require_csrf();

$action = (string) ($_POST['action'] ?? 'send');
$returnTo = MailService::safeReturnUrl((string) ($_POST['return_to'] ?? mail_url()));
$mode = (string) ($_POST['compose_mode'] ?? 'new');

$to = mail_parse_recipients((string) ($_POST['to'] ?? ''));
$cc = mail_parse_recipients((string) ($_POST['cc'] ?? ''));
$bcc = mail_parse_recipients((string) ($_POST['bcc'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));
$html = (string) ($_POST['html'] ?? '');
$draftId = (int) ($_POST['draft_id'] ?? 0) ?: null;
$inReplyTo = trim((string) ($_POST['in_reply_to'] ?? '')) ?: null;
$scheduledAt = trim((string) ($_POST['scheduled_at'] ?? '')) ?: null;

$uploads = MailService::validateUploads(mail_normalize_file_uploads($_FILES['attachments'] ?? []));
if ($uploads['errors'] !== []) {
    mail_store_compose_retry($_POST, $mode);
    mail_flash('error', implode(' ', $uploads['errors']));
    header('Location: ' . $returnTo);
    exit;
}

try {
    if ($action === 'draft') {
        $draft = MailService::saveDraft(
            $user,
            $to,
            $cc,
            $bcc,
            $subject,
            $html,
            $draftId,
            $inReplyTo,
            $uploads['valid']
        );
        MailSession::forget('mail_compose_retry');
        mail_flash('success', 'Draft saved.');
        $redirect = mail_url() . '?' . http_build_query([
            'folder' => 'drafts',
            'compose' => 'draft',
            'draft' => (int) ($draft['id'] ?? 0),
        ]);
        header('Location: ' . $redirect);
        exit;
    }

    MailService::sendMessage(
        $user,
        $to,
        $cc,
        $bcc,
        $subject,
        $html,
        $scheduledAt,
        $inReplyTo,
        $draftId,
        $uploads['valid']
    );
    MailSession::forget('mail_compose_retry');
    mail_flash('success', $scheduledAt !== null && $scheduledAt !== '' ? 'Message scheduled.' : 'Message sent.');
    header('Location: ' . $returnTo);
    exit;
} catch (\Throwable $e) {
    mail_store_compose_retry($_POST, $mode);
    mail_flash('error', $e->getMessage());
    header('Location: ' . $returnTo);
    exit;
}
