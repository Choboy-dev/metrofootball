<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-auth.php';

use App\AuthService;
use App\Response;
use App\Session;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

Session::start();
$userId = Session::userId();

if ($userId) {
    AuthService::revertUnpaidPremium($userId);
}

Session::logout();
Response::json(['ok' => true, 'redirect' => '/login']);
