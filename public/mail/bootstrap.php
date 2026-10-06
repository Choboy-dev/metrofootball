<?php

declare(strict_types=1);

$mailRoot = dirname(__DIR__, 2);

require_once $mailRoot . '/app/Env.php';
require_once $mailRoot . '/app/AppUrl.php';
require_once $mailRoot . '/app/Database.php';
require_once $mailRoot . '/app/Mail/MailSession.php';
require_once $mailRoot . '/app/Mail/MailCsrf.php';
require_once $mailRoot . '/app/Mail/MailHtml.php';
require_once $mailRoot . '/app/Mail/ResendMailClient.php';
require_once $mailRoot . '/app/Mail/MailService.php';

use App\Env;
use App\Mail\MailCsrf;
use App\Mail\MailService;
use App\Mail\MailSession;

Env::load($mailRoot . '/config/.env');
MailSession::start();
MailService::ensureStorage();

function mail_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mail_path(): string
{
    return MailService::path();
}

function mail_url(string $suffix = ''): string
{
    return MailService::url($suffix);
}

function mail_asset(string $path): string
{
    $clean = ltrim($path, '/');
    return '/' . $clean;
}

function mail_flash(string $key, ?string $value = null): ?string
{
    return MailSession::flash($key, $value);
}

function csrf_field(): string
{
    return MailCsrf::field();
}

function require_csrf(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    MailCsrf::require(is_string($token) ? $token : null);
}

function mail_user_id(array $user): int
{
    return (int) ($user['id'] ?? 0);
}

/** @return list<string> */
function mail_parse_recipients(string $raw): array
{
    $parts = preg_split('/[,;]+/', $raw) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = strtolower(trim($part));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

/** @return list<array{name: string, type: string, tmp_name: string, size: int, error: int}> */
function mail_normalize_file_uploads(array $files): array
{
    if ($files === [] || !isset($files['name'])) {
        return [];
    }

    if (!is_array($files['name'])) {
        return [[
            'name' => (string) ($files['name'] ?? ''),
            'type' => (string) ($files['type'] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'] ?? ''),
            'size' => (int) ($files['size'] ?? 0),
            'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
        ]];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => (string) $name,
            'type' => (string) ($files['type'][$index] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
            'size' => (int) ($files['size'][$index] ?? 0),
            'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
        ];
    }

    return $normalized;
}

function mail_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / 1048576, 1) . ' MB';
}

function mail_compose_is_open(array $query): bool
{
    return isset($query['compose']) && trim((string) $query['compose']) !== '';
}

/** @param array<string, mixed> $post */
function mail_store_compose_retry(array $post, string $mode = 'new'): void
{
    MailSession::set('mail_compose_retry', [
        'mode' => $mode,
        'to' => (string) ($post['to'] ?? ''),
        'cc' => (string) ($post['cc'] ?? ''),
        'bcc' => (string) ($post['bcc'] ?? ''),
        'subject' => (string) ($post['subject'] ?? ''),
        'html' => (string) ($post['html'] ?? ''),
        'draft_id' => (int) ($post['draft_id'] ?? 0) ?: null,
        'in_reply_to' => (string) ($post['in_reply_to'] ?? ''),
        'show_cc' => trim((string) ($post['cc'] ?? '')) !== '',
        'show_bcc' => trim((string) ($post['bcc'] ?? '')) !== '',
    ]);
}

function mail_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: https:; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "frame-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

/** @return array<string, string> */
function mail_icon_paths(): array
{
    return [
        'compose' => '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round"/>',
        'inbox' => '<path d="M22 12h-6l-2 3H10l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z" stroke-linecap="round" stroke-linejoin="round"/>',
        'star' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z" stroke-linecap="round" stroke-linejoin="round"/>',
        'star-filled' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z" fill="currentColor" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/>',
        'sent' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13" stroke-linecap="round" stroke-linejoin="round"/>',
        'drafts' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke-linecap="round" stroke-linejoin="round"/>',
        'scheduled' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2" stroke-linecap="round" stroke-linejoin="round"/>',
        'archive' => '<rect x="2" y="4" width="20" height="5" rx="1"/><path d="M4 9v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9M10 13h4" stroke-linecap="round" stroke-linejoin="round"/>',
        'spam' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/>',
        'trash' => '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke-linecap="round" stroke-linejoin="round"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" stroke-linecap="round" stroke-linejoin="round"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke-linecap="round" stroke-linejoin="round"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3" stroke-linecap="round" stroke-linejoin="round"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" stroke-linecap="round" stroke-linejoin="round"/>',
        'bell-off' => '<path d="M8.7 3A6 6 0 0 1 18 8c0 2.5-.8 4.2-1.5 5.5M6 8c0 7-3 9-3 9h14M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="m2 2 20 20" stroke-linecap="round" stroke-linejoin="round"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>',
        'minimize' => '<path d="M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>',
        'maximize' => '<path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3" stroke-linecap="round" stroke-linejoin="round"/>',
        'attach' => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" stroke-linecap="round" stroke-linejoin="round"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke-linecap="round" stroke-linejoin="round"/>',
        'signature' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'more-vertical' => '<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',
        'mail-unread' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" stroke-linecap="round" stroke-linejoin="round"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13" stroke-linecap="round" stroke-linejoin="round"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/>',
        'back' => '<path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/>',
        'reply' => '<path d="M9 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v5"/><path d="M9 17v4l4-4-4-4v4Z" stroke-linecap="round" stroke-linejoin="round"/>',
        'reply-all' => '<path d="m18 14-4 4-4-4"/><path d="M14 18V6a2 2 0 0 0-2-2H4"/><path d="M10 6H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-1" stroke-linecap="round" stroke-linejoin="round"/>',
        'forward' => '<path d="m15 17 5-5-5-5"/><path d="M20 12H9a4 4 0 0 0-4 4v1" stroke-linecap="round" stroke-linejoin="round"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6" stroke-linecap="round" stroke-linejoin="round"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2" stroke-linecap="round" stroke-linejoin="round"/>',
        'paperclip' => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" stroke-linecap="round" stroke-linejoin="round"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12" stroke-linecap="round" stroke-linejoin="round"/>',
        'undo' => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13" stroke-linecap="round" stroke-linejoin="round"/>',
        'redo' => '<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13" stroke-linecap="round" stroke-linejoin="round"/>',
        'bold' => '<path d="M6 4h8a4 4 0 0 1 0 8H6zM6 12h9a4 4 0 0 1 0 8H6z" stroke-linecap="round" stroke-linejoin="round"/>',
        'italic' => '<path d="M19 4h-9M14 20H5M15 4 9 20" stroke-linecap="round" stroke-linejoin="round"/>',
        'underline' => '<path d="M6 4v6a6 6 0 0 0 12 0V4M4 20h16" stroke-linecap="round" stroke-linejoin="round"/>',
        'align-left' => '<path d="M21 6H3M15 12H3M17 18H3" stroke-linecap="round" stroke-linejoin="round"/>',
        'align-center' => '<path d="M21 6H3M18 12H6M19 18H5" stroke-linecap="round" stroke-linejoin="round"/>',
        'align-right' => '<path d="M21 6H3M21 12H9M21 18H7" stroke-linecap="round" stroke-linejoin="round"/>',
        'align-justify' => '<path d="M21 6H3M21 12H3M21 18H3" stroke-linecap="round" stroke-linejoin="round"/>',
        'list-ordered' => '<path d="M10 6h11M10 12h11M10 18h11M4 6h1v4M4 10h2M4 18h1a1 1 0 0 0 0-2 1 1 0 0 0 0-2 1 1 0 0 0-2 0 2 2 0 0 0 1 1.7V18" stroke-linecap="round" stroke-linejoin="round"/>',
        'list-unordered' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" stroke-linecap="round" stroke-linejoin="round"/>',
        'indent' => '<path d="M21 12H11M21 18H11M21 6H11M3 8l4 4-4 4" stroke-linecap="round" stroke-linejoin="round"/>',
        'outdent' => '<path d="M21 12H11M21 18H11M21 6H11M3 12h8M3 6h8M7 8l-4 4 4 4" stroke-linecap="round" stroke-linejoin="round"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" stroke-linecap="round" stroke-linejoin="round"/>',
        'text-color' => '<path d="M4 20h16M7 20l5-14 5 14M9.5 14h5" stroke-linecap="round" stroke-linejoin="round"/>',
        'highlight' => '<path d="m9 11-6 6v3h9l6-6-3-3Z"/><path d="m16.5 4.5 3 3" stroke-linecap="round" stroke-linejoin="round"/>',
        'strikethrough' => '<path d="M16 4H9a3 3 0 0 0-2.83 4M14 12a4 4 0 0 1 0 8H6M4 12h16" stroke-linecap="round" stroke-linejoin="round"/>',
        'quote' => '<path d="M3 21c3 0 7-1 7-8V5H3v8c0 4-1 7-3 8Z"/><path d="M14 21c3 0 7-1 7-8V5h-7v8c0 4-1 7-3 8Z" stroke-linecap="round" stroke-linejoin="round"/>',
        'unlink' => '<path d="m18.84 12.25 1.72-1.71h-.02a5.004 5.004 0 0 0-.12-7.07 5.006 5.006 0 0 0-7.07-.12l-1.71 1.71"/><path d="m5.17 11.75-1.71 1.71a5.004 5.004 0 0 0 .12 7.07 5.006 5.006 0 0 0 7.07.12l1.71-1.71"/><path d="m8 8 8 8" stroke-linecap="round" stroke-linejoin="round"/>',
        'remove-format' => '<path d="M4 7V4h3M4 4l9 9M20 17v3h-3M20 20l-9-9" stroke-linecap="round" stroke-linejoin="round"/>',
        'format' => '<path d="M4 7V4h16v3M9 20h6M12 4v16" stroke-linecap="round" stroke-linejoin="round"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"/>',
        'plus' => '<path d="M12 5v14M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>',
    ];
}

/** @return array<string, string> */
function mail_icon_images(): array
{
    return [
        'mail-read' => '/images/mail/icon-mark-read.png?v=2',
    ];
}

function mail_icon(string $name, string $class = '', int $size = 24): string
{
    $images = mail_icon_images();
    if (isset($images[$name])) {
        $classAttr = $class !== '' ? ' class="' . mail_h($class) . ' mail-icon-img"' : ' class="mail-icon-img"';

        return '<img' . $classAttr . ' src="' . mail_h($images[$name]) . '" width="' . $size . '" height="' . $size . '" alt="" aria-hidden="true" loading="lazy" decoding="async">';
    }

    $paths = mail_icon_paths();
    $path = $paths[$name] ?? $paths['more'];
    $classAttr = $class !== '' ? ' class="' . mail_h($class) . '"' : '';

    return '<svg' . $classAttr . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . $path . '</svg>';
}

function mail_avatar_html(array $user, string $class = 'mail-avatar'): string
{
    $url = MailService::avatarUrl($user);
    $initials = MailService::userInitials($user);
    $label = mail_h((string) ($user['display_name'] ?? $user['email'] ?? 'User'));

    if ($url !== null && $url !== '') {
        return '<img class="' . mail_h($class) . '" src="' . mail_h($url) . '" alt="" width="32" height="32" decoding="async">';
    }

    return '<span class="' . mail_h($class) . ' mail-avatar--initials" aria-hidden="true">' . mail_h($initials) . '</span>';
}

function mail_nav_items(): array
{
    return [
        ['key' => 'inbox', 'label' => 'Inbox', 'icon' => 'inbox', 'folder' => 'inbox', 'count' => 'inbox_unread'],
        ['key' => 'starred', 'label' => 'Starred', 'icon' => 'star', 'folder' => 'starred', 'count' => 'starred'],
        ['key' => 'sent', 'label' => 'Sent', 'icon' => 'sent', 'folder' => 'sent', 'count' => null],
        ['key' => 'drafts', 'label' => 'Drafts', 'icon' => 'drafts', 'folder' => 'drafts', 'count' => 'drafts'],
        ['key' => 'scheduled', 'label' => 'Scheduled', 'icon' => 'scheduled', 'folder' => 'scheduled', 'count' => null],
        ['key' => 'archive', 'label' => 'Archive', 'icon' => 'archive', 'folder' => 'archive', 'count' => null],
        ['key' => 'spam', 'label' => 'Spam', 'icon' => 'spam', 'folder' => 'spam', 'count' => null],
        ['key' => 'trash', 'label' => 'Trash', 'icon' => 'trash', 'folder' => 'trash', 'count' => null],
    ];
}

function mail_category_tabs(): array
{
    return [
        'primary' => 'Primary',
        'promotions' => 'Promotions',
        'social' => 'Social',
        'updates' => 'Updates',
    ];
}

/** @return list<array{action: string, label: string, icon: string}> */
function mail_list_toolbar_actions(string $folder): array
{
    if ($folder === 'trash') {
        return [
            ['action' => 'delete', 'label' => 'Delete forever', 'icon' => 'trash'],
            ['action' => 'mark_read', 'label' => 'Mark as read', 'icon' => 'mail-read'],
            ['action' => 'mark_unread', 'label' => 'Mark as unread', 'icon' => 'mail-unread'],
        ];
    }

    if ($folder === 'spam') {
        return [
            ['action' => 'not_spam', 'label' => 'Not spam', 'icon' => 'inbox'],
            ['action' => 'trash', 'label' => 'Delete', 'icon' => 'trash'],
            ['action' => 'mark_read', 'label' => 'Mark as read', 'icon' => 'mail-read'],
            ['action' => 'mark_unread', 'label' => 'Mark as unread', 'icon' => 'mail-unread'],
        ];
    }

    $actions = [
        ['action' => 'archive', 'label' => 'Archive', 'icon' => 'archive'],
        ['action' => 'spam', 'label' => 'Report spam', 'icon' => 'spam'],
        ['action' => 'trash', 'label' => 'Delete', 'icon' => 'trash'],
        ['action' => 'mark_read', 'label' => 'Mark as read', 'icon' => 'mail-read'],
        ['action' => 'mark_unread', 'label' => 'Mark as unread', 'icon' => 'mail-unread'],
    ];

    if ($folder === 'archive') {
        return array_values(array_filter(
            $actions,
            static fn (array $item): bool => $item['action'] !== 'archive'
        ));
    }

    if ($folder === 'sent' || $folder === 'drafts' || $folder === 'scheduled') {
        return array_values(array_filter(
            $actions,
            static fn (array $item): bool => !in_array($item['action'], ['archive', 'spam'], true)
        ));
    }

    return $actions;
}

function mail_render_sidebar(array $user, string $activeFolder, array $counts): void
{
    $base = mail_url();
    $isAdmin = ($user['role'] ?? '') === 'admin';
    ?>
    <aside class="mail-sidebar" data-mail-sidebar>
        <a class="mail-sidebar__logo" href="<?= mail_h($base) ?>">
            <img class="site-logo__img" src="/images/metro-football-correct-dark-logo-315.webp?v=20260830logo" srcset="/images/metro-football-correct-dark-logo-315.webp?v=20260830logo 315w, /images/metro-football-correct-dark-logo-630.webp?v=20260830logo 630w" sizes="(min-width: 768px) 204px, 180px" alt="" width="938" height="313" decoding="async">
            <span><?= mail_h(MailService::appName()) ?></span>
        </a>

        <a class="mail-sidebar__compose" href="<?= mail_h($base . '?compose=new') ?>">
            <?= mail_icon('compose') ?>
            <span>Compose</span>
        </a>

        <nav class="mail-nav" aria-label="Mail folders">
                <?php foreach (mail_nav_items() as $item): ?>
                    <?php
                    $isActive = $activeFolder === $item['folder'];
                    $countKey = $item['count'];
                    $badge = $countKey !== null ? (int) ($counts[$countKey] ?? 0) : 0;
                    $href = $base . '?folder=' . rawurlencode($item['folder']);
                    ?>
                    <a class="mail-nav__item<?= $isActive ? ' is-active' : '' ?>" href="<?= mail_h($href) ?>">
                        <?= mail_icon($item['icon'], 'mail-nav__icon') ?>
                        <span class="mail-nav__label"><?= mail_h($item['label']) ?></span>
                        <?php if ($badge > 0): ?>
                            <span class="mail-nav__badge"><?= (int) $badge ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>

                <a class="mail-nav__item<?= $activeFolder === 'settings' ? ' is-active' : '' ?>" href="<?= mail_h(mail_url('settings')) ?>">
                    <?= mail_icon('settings', 'mail-nav__icon') ?>
                    <span class="mail-nav__label">Settings</span>
                </a>

                <?php if ($isAdmin): ?>
                    <a class="mail-nav__item<?= $activeFolder === 'admin' ? ' is-active' : '' ?>" href="<?= mail_h(mail_url('admin/users')) ?>">
                        <?= mail_icon('users', 'mail-nav__icon') ?>
                        <span class="mail-nav__label">Users</span>
                    </a>
                <?php endif; ?>
            </nav>
    </aside>
    <?php
}

function mail_render_topbar(array $user, string $searchQuery = '', ?string $folder = 'inbox'): void
{
    $logoutAction = mail_url('logout');
    ?>
    <header class="mail-topbar">
        <button type="button" class="mail-topbar__menu" data-mail-menu-toggle aria-label="Open menu">
            <?= mail_icon('menu') ?>
        </button>

        <form class="mail-search" method="get" action="<?= mail_h(mail_url()) ?>" role="search">
            <?php if ($folder !== null && $folder !== ''): ?>
                <input type="hidden" name="folder" value="<?= mail_h($folder) ?>">
            <?php endif; ?>
            <?= mail_icon('search', '', 20) ?>
            <input
                class="mail-search__input"
                type="search"
                name="q"
                value="<?= mail_h($searchQuery) ?>"
                placeholder="Search mail"
                autocomplete="off"
                aria-label="Search mail"
            >
        </form>

        <div class="mail-topbar__actions">
            <button type="button" class="mail-sound-toggle" data-mail-sound-toggle aria-pressed="true" aria-label="Mute new mail sound">
                <?= mail_icon('bell', 'mail-sound-toggle__on', 20) ?>
                <?= mail_icon('bell-off', 'mail-sound-toggle__off', 20) ?>
            </button>

            <form class="mail-topbar__logout" method="post" action="<?= mail_h($logoutAction) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="mail-avatar-btn" aria-label="Sign out">
                    <?= mail_avatar_html($user, 'mail-avatar-btn__img') ?>
                </button>
            </form>
        </div>
    </header>
    <?php
}

function mail_render_flash(): void
{
    $success = mail_flash('success');
    $error = mail_flash('error');
    $info = mail_flash('info');

    if ($success !== null): ?>
        <div class="mail-flash mail-flash--success" role="status"><?= mail_h($success) ?></div>
    <?php endif;

    if ($error !== null): ?>
        <div class="mail-flash mail-flash--error" role="alert"><?= mail_h($error) ?></div>
    <?php endif;

    if ($info !== null): ?>
        <div class="mail-flash mail-flash--info" role="status"><?= mail_h($info) ?></div>
    <?php endif;
}
