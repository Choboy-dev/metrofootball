<?php

declare(strict_types=1);

/**
 * Lightweight bootstrap for auth endpoints only.
 * Avoids loading the full FPL / AI stack on login and session checks.
 */

$root = dirname(__DIR__, 2);

require_once $root . '/app/Env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/EmailTemplate.php';
require_once $root . '/app/ResendMailer.php';
require_once $root . '/app/AdminNotificationService.php';
require_once $root . '/app/Response.php';
require_once $root . '/app/AppUrl.php';
require_once $root . '/app/Session.php';
require_once $root . '/app/AuthService.php';
require_once $root . '/app/BillingService.php';

use App\Env;
use App\Session;

Env::load($root . '/config/.env');
Session::start();

header('X-Content-Type-Options: nosniff');

function mf_read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST ?: [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mf_auth_bootstrap_extras(array $files): void
{
    static $loaded = [];
    $root = dirname(__DIR__, 2);
    foreach ($files as $file) {
        if (isset($loaded[$file])) {
            continue;
        }
        require_once $root . '/app/' . $file;
        $loaded[$file] = true;
    }
}
