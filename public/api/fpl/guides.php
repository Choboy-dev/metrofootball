<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\FplGuidesService;
use App\Response;

$slug = trim((string) ($_GET['slug'] ?? ''));

if ($slug !== '') {
    $guide = FplGuidesService::getGuide($slug);
    if ($guide === null) {
        Response::json(['ok' => false, 'error' => 'Guide not found'], 404);
    }
    Response::json(['ok' => true, 'guide' => $guide]);
}

Response::json([
    'ok' => true,
    'guides' => FplGuidesService::listGuides(),
    'weekly_teaser' => FplGuidesService::publicWeeklyTeaser(),
]);
