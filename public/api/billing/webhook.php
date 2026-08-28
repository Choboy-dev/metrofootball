<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\BillingService;
use App\Flutterwave;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$secretHash = Flutterwave::webhookHash();
$incomingHash = (string) ($_SERVER['HTTP_VERIF_HASH'] ?? '');

if ($secretHash === null || $secretHash === '' || $incomingHash === '' || !hash_equals($secretHash, $incomingHash)) {
    Response::json(['ok' => false, 'error' => 'Invalid webhook signature'], 401);
}

$payload = mf_read_json();
$event = (string) ($payload['event'] ?? '');
$data = $payload['data'] ?? null;

if (!is_array($data)) {
    Response::json(['ok' => true, 'ignored' => true]);
}

try {
    if ($event === 'charge.completed') {
        $txId = $data['id'] ?? null;
        if ($txId !== null) {
            $verified = Flutterwave::verifyTransaction($txId);
            BillingService::markSuccessfulFromVerification($verified);
        }
    }
    Response::json(['ok' => true]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
