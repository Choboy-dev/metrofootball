<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-admin.php';

use App\AdminService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    AdminService::requireAdmin();
    Response::json([
        'ok' => true,
        'dashboard' => AdminService::dashboard(),
        'audit' => AdminService::recentAudit(15),
        'system' => AdminService::systemStatus(),
    ]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
