<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;

mail_send_security_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$user = MailService::requireUser();
require_csrf();

try {
    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('No image uploaded.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $binary = is_readable($tmp) ? (string) file_get_contents($tmp) : '';
    if ($binary === '') {
        throw new \RuntimeException('Could not read uploaded image.');
    }

    $name = (string) ($file['name'] ?? 'image.png');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'png');
    $mime = (string) ($file['type'] ?? 'image/png');

    $url = MailService::storeInlineImage(mail_user_id($user), $binary, $ext, $mime);
    echo json_encode(['ok' => true, 'url' => $url], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
