<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-public.php';
require_once dirname(__DIR__, 2) . '/app/ContactService.php';

use App\ContactService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Response::json([
        'ok' => true,
        'reasons' => ContactService::reasons(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$data = mf_read_json();

try {
    $payload = ContactService::validate($data);
    ContactService::send($payload);
    Response::json(['ok' => true]);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => 'We could not send your message right now. Please try again soon.'], 500);
}
