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

$errorRedirect = AppUrl::to('login?error=' . rawurlencode('Google sign in did not complete.'));

try {
    $state = (string) ($_GET['state'] ?? '');
    $code = (string) ($_GET['code'] ?? '');
    $expected = (string) ($_SESSION['oauth_state'] ?? '');

    if ($code === '' || $state === '' || $expected === '' || !hash_equals($expected, $state)) {
        Response::redirect($errorRedirect);
    }

    if (isset($_GET['error'])) {
        Response::redirect($errorRedirect);
    }

    $plan = AuthService::normalizePlan($_SESSION['oauth_plan'] ?? 'free');
    $mode = (string) ($_SESSION['oauth_mode'] ?? 'signup');
    $profile = GoogleOAuth::exchangeCode($code);
    $user = AuthService::upsertGoogleUser($profile['id'], $profile['email'], $profile['name'], $plan);

    if ($mode === 'login' && $user) {
        $user = AuthService::revertUnpaidPremium((int) $user['id']) ?: $user;
        Session::login($user);
    }

    unset($_SESSION['oauth_state'], $_SESSION['oauth_plan'], $_SESSION['oauth_mode']);

    $intended = AuthService::resolveIntended(null);
    $redirect = Session::flash('signup_checkout') === '1'
        ? AuthService::appendReturnToPath('/payment', $intended)
        : AuthService::postAuthPath($user, $intended);
    Response::redirect(AppUrl::to(ltrim($redirect, '/')));
} catch (Throwable $e) {
    Response::redirect(AppUrl::to('login?error=' . rawurlencode($e->getMessage())));
}
