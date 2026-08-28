<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\Database;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$data = mf_read_json();
$email = strtolower(trim((string) ($data['email'] ?? '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::json(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
}

$stmt = Database::pdo()->prepare('SELECT id FROM pending_signups WHERE email = ? COLLATE NOCASE LIMIT 1');
$stmt->execute([$email]);
if (!$stmt->fetch()) {
    Response::json(['ok' => false, 'error' => 'No pending signup found for this email. Please start again.'], 422);
}

try {
    AuthService::issueAndSendOtp($email);
    Response::json(['ok' => true]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
