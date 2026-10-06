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
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
    Response::json(['ok' => true, 'usage' => AdminService::listAiUsage($page, 40, $days)]);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
