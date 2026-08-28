<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap-auth.php';
mf_auth_bootstrap_extras(['GoogleOAuth.php']);

use App\AppUrl;
use App\AuthService;
use App\GoogleOAuth;
use App\Response;
use App\Session;

Session::start();

if (!GoogleOAuth::isConfigured()) {
    Response::redirect(AppUrl::to('login?error=' . rawurlencode('Google sign in is not configured yet.')));
}

$plan = AuthService::normalizePlan($_GET['plan'] ?? ($_SESSION['oauth_plan'] ?? 'free'));
$_SESSION['oauth_plan'] = $plan;
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));
$_SESSION['oauth_mode'] = isset($_GET['mode']) && $_GET['mode'] === 'login' ? 'login' : 'signup';
AuthService::rememberReturnPath((string) ($_GET['next'] ?? ''));

Response::redirect(GoogleOAuth::authorizationUrl((string) $_SESSION['oauth_state']));
