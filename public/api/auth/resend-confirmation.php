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
if (!$userId) {
    Response::json(['ok' => false, 'error' => 'Please log in first.', 'redirect' => '/login'], 401);
}

try {
    AuthService::resendEmailConfirmation($userId);
    Response::json(['ok' => true, 'message' => 'Confirmation email sent.']);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
