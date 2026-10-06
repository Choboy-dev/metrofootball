<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-admin.php';

use App\AdminService;
use App\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

AdminService::logout();
Response::json(['ok' => true]);
