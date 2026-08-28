<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-auth.php';
mf_auth_bootstrap_extras(['BillingService.php', 'Flutterwave.php']);

use App\AuthService;
use App\BillingService;
use App\Response;
use App\Session;

Session::start();
$userId = Session::userId();
if (!$userId) {
    $idle = Session::consumeIdleExpiredFlag();
    $payload = [
        'ok' => false,
        'authenticated' => false,
        'reason' => $idle ? 'idle' : null,
        'idle_timeout_seconds' => Session::idleSeconds(),
    ];
    Session::release();
    Response::json($payload, 401);
}

$user = AuthService::findUserById($userId);
if (!$user) {
    Session::logout();
    Session::release();
    Response::json(['ok' => false, 'authenticated' => false], 401);
}

$payload = [
    'ok' => true,
    'authenticated' => true,
    'idle_timeout_seconds' => Session::idleSeconds(),
    'user' => [
        'email' => $user['email'],
        'plan' => $user['plan'],
        'subscription_status' => $user['subscription_status'],
        'billing_cycle' => $user['billing_cycle'] ?? null,
        'subscription_expires_at' => $user['subscription_expires_at'] ?? null,
        'email_verified' => $user['email_verified_at'] !== null,
        'fpl_team_id' => isset($user['fpl_team_id']) && $user['fpl_team_id'] !== null && $user['fpl_team_id'] !== ''
            ? (int) $user['fpl_team_id']
            : null,
        'fpl_team_name' => $user['fpl_team_name'] ?? null,
        'fpl_manager_name' => $user['fpl_manager_name'] ?? null,
        'needs_team_link' => AuthService::needsTeamLink($user),
    ],
    'redirect' => AuthService::postAuthPath($user),
    'billing' => [
        'monthly' => BillingService::plan('monthly'),
        'yearly' => BillingService::plan('yearly'),
        'currency' => BillingService::CURRENCY,
        'flutterwave_ready' => \App\Flutterwave::isConfigured(),
    ],
    'notice' => Session::flash('notice'),
];
Session::release();
Response::json($payload);
