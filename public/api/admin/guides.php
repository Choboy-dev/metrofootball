<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-admin.php';

use App\AdminService;
use App\FplGuidesService;
use App\GuidePostService;
use App\Response;
use App\Session;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    AdminService::requireAdmin();

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $post = GuidePostService::getById($id);
            if ($post === null) {
                Response::json(['ok' => false, 'error' => 'Post not found'], 404);
            }
            Response::json(['ok' => true, 'post' => $post, 'meta' => meta()]);
        }

        Response::json([
            'ok' => true,
            'posts' => FplGuidesService::adminListAll(),
            'meta' => meta(),
        ]);
    }

    if ($method === 'POST') {
        $body = mf_read_json();
        $userId = Session::userId();
        $post = GuidePostService::save($body, $userId > 0 ? $userId : null);
        Response::json(['ok' => true, 'post' => $post]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            Response::json(['ok' => false, 'error' => 'Post id is required'], 400);
        }
        GuidePostService::delete($id);
        Response::json(['ok' => true]);
    }

    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
}

/**
 * @return array<string, mixed>
 */
function meta(): array
{
    return [
        'schema_types' => GuidePostService::SCHEMA_TYPES,
        'categories' => GuidePostService::CATEGORIES,
    ];
}
