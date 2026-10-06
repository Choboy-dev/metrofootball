<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-admin.php';

use App\AdminService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$data = mf_read_json();
$email = (string) ($data['email'] ?? '');
$password = (string) ($data['password'] ?? '');

try {
    $user = AdminService::login($email, $password);
    Response::json(['ok' => true, 'user' => $user]);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
