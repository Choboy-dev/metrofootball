<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-admin.php';

use App\AdminService;
use App\Response;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $admin = AdminService::requireAdmin();

    if ($method === 'GET') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $q = isset($_GET['q']) ? (string) $_GET['q'] : null;
        $filter = isset($_GET['filter']) ? (string) $_GET['filter'] : null;
        Response::json([
            'ok' => true,
            'users' => AdminService::listUsers($page, 25, $q, $filter),
        ]);
    }

    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
