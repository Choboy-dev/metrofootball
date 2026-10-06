<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AuthService;
use App\BillingService;
use App\Response;
use App\Session;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

Session::start();
$userId = Session::userId();
if (!$userId) {
    Response::json(['ok' => false, 'error' => 'Please log in first.'], 401);
}

$user = AuthService::findUserById($userId);
if (!$user) {
    Session::logout();
    Response::json(['ok' => false, 'error' => 'Please log in first.'], 401);
}

$data = mf_read_json();
$cycle = (string) ($data['cycle'] ?? 'yearly');
AuthService::rememberReturnPath((string) ($data['next'] ?? ''));
$intended = AuthService::resolveIntended(null);

if (($user['subscription_status'] ?? '') === 'active' && ($user['plan'] ?? '') === 'premium' && BillingService::isPremiumActive($user)) {
    Response::json(['ok' => true, 'redirect' => AuthService::postAuthPath($user, $intended)]);
}

try {
    $checkout = BillingService::createCheckout($user, $cycle);
    Session::login(AuthService::findUserById($userId) ?: $user);
    Response::json([
        'ok' => true,
        'checkout_url' => $checkout['url'],
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
