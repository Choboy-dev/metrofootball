<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-admin.php';

use App\AdminService;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    AdminService::requireAdmin();
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

try {
    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('No image uploaded.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if (!is_readable($tmp)) {
        throw new \RuntimeException('Could not read uploaded image.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        throw new \RuntimeException('Image must be JPEG, PNG, WebP, or GIF.');
    }

    $ext = $allowed[$mime];
    $dir = dirname(__DIR__, 2) . '/images/guides/posts';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new \RuntimeException('Could not create upload directory.');
    }

    $name = 'guide-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new \RuntimeException('Could not save uploaded image.');
    }

    echo json_encode([
        'ok' => true,
        'url' => '/images/guides/posts/' . $name,
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
