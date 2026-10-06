<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;

mail_send_security_headers();

$user = MailService::requireUser();
$userId = mail_user_id($user);

$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$attachment = MailService::getAttachment($userId, $attachmentId);
if ($attachment === null) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$storagePath = (string) ($attachment['storage_path'] ?? '');
$fullPath = str_starts_with($storagePath, '/')
    ? $storagePath
    : dirname(__DIR__, 2) . '/' . ltrim($storagePath, '/');

if (!is_file($fullPath)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$filename = (string) ($attachment['filename'] ?? 'attachment');
$contentType = (string) ($attachment['content_type'] ?? 'application/octet-stream');
$safeName = preg_replace('/[^\w.\- ]+/u', '_', $filename) ?: 'attachment';

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $contentType);
header('Content-Length: ' . (string) filesize($fullPath));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $safeName) . '"');
header('Cache-Control: private, no-store');

readfile($fullPath);
exit;
