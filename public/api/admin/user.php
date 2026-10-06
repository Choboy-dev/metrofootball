<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-admin.php';

use App\AdminService;
use App\Response;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$userId = (int) ($_GET['id'] ?? 0);

try {
    $admin = AdminService::requireAdmin();

    if ($userId <= 0) {
        Response::json(['ok' => false, 'error' => 'User id required.'], 422);
    }

    if ($method === 'GET') {
        $user = AdminService::getUser($userId);
        if (!$user) {
            Response::json(['ok' => false, 'error' => 'User not found.'], 404);
        }
        Response::json(['ok' => true, 'user' => $user]);
    }

    if ($method === 'POST') {
        $data = mf_read_json();
        $user = AdminService::updateUser($admin, $userId, $data);
        Response::json(['ok' => true, 'user' => $user]);
    }

    if ($method === 'DELETE') {
        AdminService::deleteUser($admin, $userId);
        Response::json(['ok' => true]);
    }

    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
