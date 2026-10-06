<?php

declare(strict_types=1);

/**
 * Lightweight bootstrap for public endpoints (contact form, etc.).
 * No auth stack, no FPL / AI services.
 */

$root = dirname(__DIR__, 2);

require_once $root . '/app/Env.php';
require_once $root . '/app/EmailTemplate.php';
require_once $root . '/app/ResendMailer.php';
require_once $root . '/app/Response.php';
require_once $root . '/app/Session.php';

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
