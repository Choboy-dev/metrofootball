<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AppUrl;
use App\AuthService;
use App\Response;
use App\Session;

$token = (string) ($_GET['token'] ?? '');

try {
    $user = AuthService::confirmEmail($token);
    Session::login($user);
    Session::flash('notice', 'Your email is confirmed. Welcome to Metro Football.');
    Response::redirect(AppUrl::to(ltrim(AuthService::postAuthPath($user), '/')));
} catch (Throwable $e) {
    Response::redirect(AppUrl::to('login?error=' . rawurlencode($e->getMessage())));
}
