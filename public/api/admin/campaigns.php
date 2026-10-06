<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap-admin.php';

use App\AdminCampaignService;
use App\AdminService;
use App\Response;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $admin = AdminService::requireAdmin();

    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? '');
        if ($action === 'preview') {
            $raw = (string) ($_GET['segments'] ?? '');
            $segments = array_values(array_filter(array_map('trim', explode(',', $raw))));
            $preview = AdminCampaignService::previewAudience($segments, 80);
            Response::json(['ok' => true, 'preview' => $preview]);
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $campaign = AdminCampaignService::getCampaign($id);
            if ($campaign === null) {
                Response::json(['ok' => false, 'error' => 'Campaign not found'], 404);
            }
            Response::json(['ok' => true, 'campaign' => $campaign]);
        }

        Response::json([
            'ok' => true,
            'segments' => AdminCampaignService::segmentCards(),
            'campaigns' => AdminCampaignService::listCampaigns(40),
        ]);
    }

    if ($method === 'POST') {
        $body = mf_read_json();
        $action = (string) ($body['action'] ?? 'send');

        if ($action === 'preview') {
            $segments = is_array($body['segments'] ?? null) ? $body['segments'] : [];
            $preview = AdminCampaignService::previewAudience($segments, 80);
            Response::json(['ok' => true, 'preview' => $preview]);
        }

        if ($action === 'send') {
            $segments = is_array($body['segments'] ?? null) ? $body['segments'] : [];
            $bodyHtml = (string) ($body['body_html'] ?? '');
            $bodyText = (string) ($body['body_text'] ?? '');
            $campaign = AdminCampaignService::createAndSend(
                $admin,
                (string) ($body['subject'] ?? ''),
                $bodyHtml !== '' ? $bodyHtml : $bodyText,
                $segments,
                isset($body['cta_label']) ? (string) $body['cta_label'] : null,
                isset($body['cta_url']) ? (string) $body['cta_url'] : null,
                $bodyHtml !== ''
            );
            Response::json(['ok' => true, 'campaign' => $campaign]);
        }

        Response::json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

    Response::json(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
