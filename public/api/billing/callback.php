<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\AppUrl;
use App\AuthService;
use App\BillingService;
use App\Flutterwave;
use App\Response;
use App\Session;

Session::start();

$status = strtolower((string) ($_GET['status'] ?? ''));
$txRef = (string) ($_GET['tx_ref'] ?? '');
$transactionId = (string) ($_GET['transaction_id'] ?? '');

try {
    if ($status === 'cancelled' || $status === 'canceled') {
        BillingService::markFailedByTxRef($txRef);
        Session::flash('notice', 'Payment was cancelled. You can try again when ready.');
        Response::redirect(AppUrl::to('payment'));
    }

    if ($transactionId === '') {
        BillingService::markFailedByTxRef($txRef);
        Session::flash('notice', 'Payment was not completed.');
        Response::redirect(AppUrl::to('payment'));
    }

    $verified = Flutterwave::verifyTransaction($transactionId);
    $user = BillingService::markSuccessfulFromVerification($verified);

    if (!$user) {
        Session::flash('notice', 'Payment could not be confirmed. Please try again or contact support.');
        Response::redirect(AppUrl::to('payment'));
    }

    Session::login($user);
    Session::flash('notice', 'Premium is active. Welcome aboard.');
    $intended = AuthService::resolveIntended(null);
    Response::redirect(AppUrl::to(ltrim(AuthService::postAuthPath($user, $intended), '/')));
} catch (Throwable $e) {
    Session::flash('notice', $e->getMessage());
    Response::redirect(AppUrl::to('payment'));
}
