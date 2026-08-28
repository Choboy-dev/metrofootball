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

try {
    AuthService::rememberReturnPath((string) ($data['next'] ?? ''));
    $user = AuthService::loginWithPassword($email, $password);
    $intended = AuthService::resolveIntended(null);
    Response::json([
        'ok' => true,
        'redirect' => AuthService::postAuthPath($user, $intended),
        'user' => [
            'email' => $user['email'],
            'plan' => $user['plan'],
        ],
    ]);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
