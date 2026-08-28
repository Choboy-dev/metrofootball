<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-auth.php';

use App\AuthService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$data = mf_read_json();
$email = (string) ($data['email'] ?? '');
$password = (string) ($data['password'] ?? '');
$plan = (string) ($data['plan'] ?? 'free');

try {
    AuthService::startEmailSignup($email, $password, $plan);
    Response::json([
        'ok' => true,
        'email' => strtolower(trim($email)),
        'plan' => AuthService::normalizePlan($plan),
        'next' => '/verify-otp',
    ]);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
