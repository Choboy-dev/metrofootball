<?php

declare(strict_types=1);

namespace App\Mail;

use App\AppUrl;
use App\Database;
use App\Env;
use PDO;

final class MailService
{
    private const PER_PAGE = 25;

    /** @var list<string> */
    private const FOLDERS = ['inbox', 'sent', 'drafts', 'scheduled', 'archive', 'spam', 'trash'];

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
    ];

    /** @var list<string> */
    private const SOCIAL_DOMAINS = [
        'facebook.com', 'facebookmail.com', 'instagram.com', 'linkedin.com', 'linkedinmail.com',
        'tiktok.com', 'tiktokglobalshop.com', 'youtube.com', 'twitter.com', 'x.com', 'pinterest.com',
        'reddit.com', 'redditmail.com', 'threads.net', 'snapchat.com', 'discord.com', 'tumblr.com',
        'quora.com', 'meetup.com', 'nextdoor.com', 'bsky.app', 'mastodon.social', 'vk.com', 'weibo.com',
    ];

    /** @var list<string> */
    private const PROMO_LOCAL_PARTS = [
        'newsletter', 'marketing', 'promo', 'promotions', 'deals', 'offers', 'sale', 'sales',
        'campaign', 'ads', 'advertising', 'info', 'updates',
    ];

    /** @var list<string> */
    private const UPDATE_LOCAL_PARTS = [
        'noreply', 'no-reply', 'donotreply', 'notifications', 'notification', 'billing',
        'accounts', 'account', 'security', 'support', 'mailer-daemon', 'postmaster', 'alerts',
    ];

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    public static function ensureStorage(): void
    {
        $root = dirname(__DIR__, 2);
        $dirs = [
            $root . '/storage/mail/inbound',
            $root . '/storage/mail/outbound',
            $root . '/storage/mail/drafts',
            $root . '/storage/uploads/mail-inline',
            $root . '/storage/uploads/mail-avatars',
            $root . '/public/uploads/mail-inline',
            $root . '/public/uploads/mail-avatars',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public static function domain(): string
    {
        return strtolower(trim((string) Env::get('MAIL_DOMAIN', 'metrofootball.co.uk')));
    }

    public static function appName(): string
    {
        return trim((string) Env::get('MAIL_APP_NAME', 'Metro Mail'));
    }

    public static function path(): string
    {
        $path = trim((string) Env::get('MAIL_PATH', '/mail'));
        return $path === '' ? '/mail' : '/' . trim($path, '/');
    }

    public static function url(string $suffix = ''): string
    {
        $base = rtrim(AppUrl::base(), '/') . self::path();
        $suffix = trim($suffix);
        if ($suffix === '') {
            return $base;
        }
        return $base . '/' . ltrim($suffix, '/');
    }

    public static function safeReturnUrl(?string $url): string
    {
        $default = self::url();
        $url = trim((string) $url);
        if ($url === '') {
            return $default;
        }

        $mailPath = self::path();
        if (str_starts_with($url, $mailPath . '/') || $url === $mailPath) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            $full = rtrim(AppUrl::base(), '/') . $url;
            if (str_starts_with($full, rtrim(AppUrl::base(), '/') . $mailPath)) {
                return $url;
            }
        }

        return $default;
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public static function findUserByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM mail_users WHERE email = ? COLLATE NOCASE LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch();
        return is_array($row) ? self::normalizeUser($row) : null;
    }

    public static function findUserById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM mail_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::normalizeUser($row) : null;
    }

    public static function authenticate(string $email, string $password): ?array
    {
        if (MailSession::isLoginRateLimited()) {
            return null;
        }

        $user = self::findUserByEmail($email);
        if (!$user || !(int) ($user['is_active'] ?? 0)) {
            MailSession::recordFailedLogin();
            return null;
        }

        if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            MailSession::recordFailedLogin();
            return null;
        }

        return $user;
    }

    public static function login(array $user): void
    {
        MailSession::login((int) $user['id'], (string) $user['email']);
    }

    public static function logout(): void
    {
        MailSession::logout();
    }

    public static function currentUser(): ?array
    {
        $id = MailSession::userId();
        return $id ? self::findUserById($id) : null;
    }

    public static function requireUser(): array
    {
        $user = self::currentUser();
        if (!$user || !(int) ($user['is_active'] ?? 0)) {
            header('Location: ' . self::url('/login'));
            exit;
        }

        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            $changePath = self::url('/change-password');
            $current = $_SERVER['REQUEST_URI'] ?? '';
            if (!str_contains($current, '/change-password')) {
                header('Location: ' . $changePath);
                exit;
            }
        }

        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if (($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            throw new \RuntimeException('Admin access required.');
        }
        return $user;
    }

    public static function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        self::assertPasswordStrength($newPassword);
        $user = self::findUserById($userId);
        if (!$user) {
            throw new \RuntimeException('User not found.');
        }
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new \RuntimeException('Current password is incorrect.');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $now = gmdate('c');
        $stmt = Database::pdo()->prepare(
            'UPDATE mail_users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$hash, $now, $userId]);
    }

    public static function userInitials(array $user): string
    {
        $source = trim((string) ($user['display_name'] ?? ''));
        if ($source === '') {
            $source = (string) ($user['email'] ?? '');
        }
        $parts = preg_split('/\s+/', $source) ?: [];
        $initials = '';
        foreach ($parts as $part) {
            if ($part !== '') {
                $initials .= strtoupper($part[0]);
            }
            if (strlen($initials) >= 2) {
                break;
            }
        }
        if ($initials === '' && $source !== '') {
            $initials = strtoupper($source[0]);
        }
        return $initials !== '' ? $initials : '?';
    }

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------

    public static function createUser(
        string $email,
        string $displayName,
        string $password,
        string $role = 'user',
        bool $mustChangePassword = true
    ): array {
        self::assertPasswordStrength($password);
        $email = strtolower(trim($email));
        self::assertDomainEmail($email);
        $role = $role === 'admin' ? 'admin' : 'user';
        $now = gmdate('c');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $colors = ['#dc2626', '#1a73e8', '#0b8043', '#9334e6', '#f9ab00', '#5f6368'];
        $color = $colors[array_rand($colors)];

        $stmt = Database::pdo()->prepare(
            'INSERT INTO mail_users (
                email, display_name, password_hash, role, is_active, must_change_password,
                avatar_color, created_at, updated_at
            ) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $email,
            trim($displayName),
            $hash,
            $role,
            $mustChangePassword ? 1 : 0,
            $color,
            $now,
            $now,
        ]);

        return self::findUserById((int) Database::pdo()->lastInsertId()) ?? [];
    }

    public static function updateUser(int $userId, array $data): array
    {
        $user = self::findUserById($userId);
        if (!$user) {
            throw new \RuntimeException('User not found.');
        }

        $displayName = array_key_exists('display_name', $data)
            ? trim((string) $data['display_name'])
            : (string) $user['display_name'];
        $role = array_key_exists('role', $data)
            ? ((string) $data['role'] === 'admin' ? 'admin' : 'user')
            : (string) $user['role'];
        $isActive = array_key_exists('is_active', $data)
            ? ((int) $data['is_active'] ? 1 : 0)
            : (int) $user['is_active'];
        $mustChange = array_key_exists('must_change_password', $data)
            ? ((int) $data['must_change_password'] ? 1 : 0)
            : (int) $user['must_change_password'];

        if ($role !== 'admin' || $isActive === 0) {
            self::assertNotLastActiveAdmin($userId, $role, $isActive);
        }

        $fields = [
            'display_name' => $displayName,
            'role' => $role,
            'is_active' => $isActive,
            'must_change_password' => $mustChange,
            'updated_at' => gmdate('c'),
        ];

        if (!empty($data['password'])) {
            self::assertPasswordStrength((string) $data['password']);
            $fields['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }

        $sets = [];
        $values = [];
        foreach ($fields as $column => $value) {
            $sets[] = $column . ' = ?';
            $values[] = $value;
        }
        $values[] = $userId;

        $sql = 'UPDATE mail_users SET ' . implode(', ', $sets) . ' WHERE id = ?';
        Database::pdo()->prepare($sql)->execute($values);

        return self::findUserById($userId) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public static function listUsers(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT * FROM mail_users ORDER BY role DESC, email ASC'
        );
        $rows = $stmt->fetchAll();
        return array_map(self::normalizeUser(...), is_array($rows) ? $rows : []);
    }

    // -------------------------------------------------------------------------
    // Inbox
    // -------------------------------------------------------------------------

    /** @return array<string, int> */
    public static function folderCounts(int $userId): array
    {
        $counts = [];
        foreach (self::FOLDERS as $folder) {
            $counts[$folder] = 0;
        }
        $counts['starred'] = 0;

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT folder, COUNT(*) AS total
             FROM mail_messages
             WHERE user_id = ?
             GROUP BY folder'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['folder']] = (int) $row['total'];
        }

        $starStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM mail_messages
             WHERE user_id = ? AND is_starred = 1 AND folder != ?'
        );
        $starStmt->execute([$userId, 'trash']);
        $counts['starred'] = (int) $starStmt->fetchColumn();

        $unreadStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM mail_messages
             WHERE user_id = ? AND folder = ? AND direction = ? AND is_read = 0'
        );
        $unreadStmt->execute([$userId, 'inbox', 'inbound']);
        $counts['inbox_unread'] = (int) $unreadStmt->fetchColumn();

        return $counts;
    }

    /** @return array<string, int> */
    public static function inboxCategoryCounts(int $userId, bool $unreadOnly = false): array
    {
        $counts = [
            'primary' => 0,
            'promotions' => 0,
            'social' => 0,
            'updates' => 0,
        ];

        $pdo = Database::pdo();
        $sql = 'SELECT category, COUNT(*) AS total
             FROM mail_messages
             WHERE user_id = ? AND folder = ? AND direction = ?';
        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' GROUP BY category';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, 'inbox', 'inbound']);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['category'] ?? '');
            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    /** @return array{latest_id: int, unread: int} */
    public static function inboxState(int $userId): array
    {
        $pdo = Database::pdo();
        $latest = $pdo->prepare(
            'SELECT COALESCE(MAX(id), 0) FROM mail_messages
             WHERE user_id = ? AND folder = ? AND direction = ?'
        );
        $latest->execute([$userId, 'inbox', 'inbound']);

        $unread = $pdo->prepare(
            'SELECT COUNT(*) FROM mail_messages
             WHERE user_id = ? AND folder = ? AND direction = ? AND is_read = 0'
        );
        $unread->execute([$userId, 'inbox', 'inbound']);

        return [
            'latest_id' => (int) $latest->fetchColumn(),
            'unread' => (int) $unread->fetchColumn(),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public static function listMessages(
        int $userId,
        string $folder = 'inbox',
        ?string $category = null,
        ?string $query = null,
        int $page = 1
    ): array {
        $folder = self::normalizeFolder($folder);
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $where = ['user_id = ?'];
        $params = [$userId];

        if ($folder === 'starred') {
            $where[] = 'is_starred = 1';
            $where[] = 'folder != ?';
            $params[] = 'trash';
        } else {
            $where[] = 'folder = ?';
            $params[] = $folder;
        }

        if ($folder === 'inbox' && $category !== null && $category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }

        if ($query !== null && trim($query) !== '') {
            $q = '%' . trim($query) . '%';
            $where[] = '(subject LIKE ? OR snippet LIKE ? OR from_email LIKE ? OR from_name LIKE ? OR body_text LIKE ?)';
            array_push($params, $q, $q, $q, $q, $q);
        }

        $whereSql = implode(' AND ', $where);
        $order = $folder === 'scheduled'
            ? 'scheduled_at ASC, id ASC'
            : 'COALESCE(sent_at, received_at, created_at) DESC, id DESC';

        $pdo = Database::pdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mail_messages WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare(
            "SELECT * FROM mail_messages WHERE {$whereSql}
             ORDER BY {$order}
             LIMIT ? OFFSET ?"
        );
        $listParams = array_merge($params, [self::PER_PAGE, $offset]);
        $listStmt->execute($listParams);
        $items = array_map(self::normalizeMessage(...), $listStmt->fetchAll() ?: []);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ];
    }

    public static function getMessage(int $userId, int $messageId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM mail_messages WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$messageId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? self::normalizeMessage($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public static function getAttachments(int $messageId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM mail_attachments WHERE message_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$messageId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function getAttachment(int $userId, int $attachmentId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, m.user_id
             FROM mail_attachments a
             INNER JOIN mail_messages m ON m.id = a.message_id
             WHERE a.id = ? AND m.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$attachmentId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function markRead(int $userId, array $messageIds, bool $read = true): void
    {
        if ($messageIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $params = array_merge([$read ? 1 : 0], $messageIds, [$userId]);
        $sql = "UPDATE mail_messages SET is_read = ? WHERE id IN ({$placeholders}) AND user_id = ?";
        Database::pdo()->prepare($sql)->execute($params);
    }

    public static function toggleStar(int $userId, int $messageId): bool
    {
        $message = self::getMessage($userId, $messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        $newValue = (int) ($message['is_starred'] ?? 0) ? 0 : 1;
        $stmt = Database::pdo()->prepare(
            'UPDATE mail_messages SET is_starred = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$newValue, $messageId, $userId]);
        return (bool) $newValue;
    }

    public static function moveMessages(int $userId, array $messageIds, string $folder): void
    {
        if ($messageIds === []) {
            return;
        }
        $folder = self::normalizeFolder($folder);
        if ($folder === 'starred') {
            throw new \InvalidArgumentException('Cannot move messages to starred folder.');
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $params = array_merge([$folder], $messageIds, [$userId]);
        $sql = "UPDATE mail_messages SET folder = ? WHERE id IN ({$placeholders}) AND user_id = ?";
        Database::pdo()->prepare($sql)->execute($params);
    }

    public static function deleteMessages(int $userId, array $messageIds, bool $permanent = false): void
    {
        if ($messageIds === []) {
            return;
        }

        if ($permanent) {
            foreach ($messageIds as $messageId) {
                self::deleteMessageFiles((int) $messageId);
            }
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $params = array_merge($messageIds, [$userId]);
            Database::pdo()->prepare(
                "DELETE FROM mail_messages WHERE id IN ({$placeholders}) AND user_id = ?"
            )->execute($params);
            return;
        }

        self::moveMessages($userId, $messageIds, 'trash');
    }

    // -------------------------------------------------------------------------
    // Outbound
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array{name: string, type: string, tmp_name: string, size: int, error: int}> $uploads
     * @return array{valid: list<array{path: string, name: string, type: string, size: int}>, errors: list<string>}
     */
    public static function validateUploads(array $uploads): array
    {
        $maxFiles = (int) Env::get('MAIL_MAX_ATTACHMENTS', '10');
        $maxBytes = (int) Env::get('MAIL_MAX_ATTACHMENT_BYTES', '10485760');
        $valid = [];
        $errors = [];
        $totalBytes = 0;

        foreach ($uploads as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = 'One or more attachments failed to upload.';
                continue;
            }

            $name = (string) ($file['name'] ?? 'file');
            $size = (int) ($file['size'] ?? 0);
            $type = (string) ($file['type'] ?? 'application/octet-stream');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $errors[] = $name . ' has a file type that is not allowed.';
                continue;
            }
            if (!self::isAllowedMime($type, $ext)) {
                $errors[] = $name . ' has a MIME type that is not allowed.';
                continue;
            }
            if ($size <= 0 || $size > $maxBytes) {
                $errors[] = $name . ' exceeds the size limit.';
                continue;
            }

            $totalBytes += $size;
            if (count($valid) >= $maxFiles) {
                $errors[] = 'Too many attachments.';
                break;
            }
            if ($totalBytes > $maxBytes) {
                $errors[] = 'Total attachment size exceeds the limit.';
                break;
            }

            $valid[] = [
                'path' => (string) $file['tmp_name'],
                'name' => $name,
                'type' => $type,
                'size' => $size,
            ];
        }

        return ['valid' => $valid, 'errors' => $errors];
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     * @param list<array{path: string, name: string, type: string, size: int}> $attachments
     */
    public static function sendMessage(
        array $user,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $html,
        ?string $scheduledAtLocal = null,
        ?string $inReplyTo = null,
        ?int $draftId = null,
        array $attachments = []
    ): array {
        $to = self::normalizeRecipients($to);
        $cc = self::normalizeRecipients($cc);
        $bcc = self::normalizeRecipients($bcc);
        if ($to === [] && $cc === [] && $bcc === []) {
            throw new \RuntimeException('Add at least one recipient.');
        }

        $html = MailHtml::sanitize($html);
        $html = self::materializeInlineImages($html, (int) $user['id']);
        $text = MailHtml::plainText($html);
        $snippet = self::makeSnippet($text !== '' ? $text : $html);
        $now = gmdate('c');
        $scheduledUtc = null;
        $folder = 'sent';

        if ($scheduledAtLocal !== null && trim($scheduledAtLocal) !== '') {
            $scheduledUtc = self::parseScheduledAt($scheduledAtLocal);
            $folder = 'scheduled';
        }

        $fromEmail = (string) $user['email'];
        $fromName = (string) ($user['display_name'] ?: self::appName());
        $displayFrom = trim($fromName) !== '' ? "{$fromName} <{$fromEmail}>" : $fromEmail;

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($draftId) {
                $existing = self::getMessage((int) $user['id'], $draftId);
                if ($existing && ($existing['folder'] ?? '') === 'drafts') {
                    self::deleteMessageFiles($draftId);
                    $pdo->prepare('DELETE FROM mail_attachments WHERE message_id = ?')->execute([$draftId]);
                    $pdo->prepare('DELETE FROM mail_messages WHERE id = ? AND user_id = ?')->execute([
                        $draftId,
                        (int) $user['id'],
                    ]);
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO mail_messages (
                    user_id, folder, category, direction, from_email, from_name,
                    to_emails, cc_emails, bcc_emails, subject, body_html, body_text, snippet,
                    is_read, is_starred, in_reply_to, scheduled_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $user['id'],
                $folder,
                'primary',
                'outbound',
                $fromEmail,
                $fromName,
                json_encode($to, JSON_THROW_ON_ERROR),
                json_encode($cc, JSON_THROW_ON_ERROR),
                json_encode($bcc, JSON_THROW_ON_ERROR),
                trim($subject),
                $html,
                $text,
                $snippet,
                $inReplyTo,
                $scheduledUtc,
                $now,
            ]);
            $messageId = (int) $pdo->lastInsertId();

            $apiAttachments = [];
            foreach ($attachments as $attachment) {
                $stored = self::storeAttachmentFile($messageId, $attachment, 'outbound');
                $apiAttachments[] = [
                    'filename' => $stored['filename'],
                    'content' => base64_encode((string) file_get_contents($stored['storage_path'])),
                    'content_type' => (string) ($attachment['type'] ?? 'application/octet-stream'),
                ];
            }

            $payload = [
                'from' => $displayFrom,
                'to' => $to,
                'subject' => trim($subject),
                'html' => $html,
                'text' => $text,
            ];
            if ($cc !== []) {
                $payload['cc'] = $cc;
            }
            if ($bcc !== []) {
                $payload['bcc'] = $bcc;
            }
            if ($inReplyTo) {
                $payload['headers'] = ['In-Reply-To' => $inReplyTo];
            }
            if ($scheduledUtc) {
                $payload['scheduled_at'] = $scheduledUtc;
            }
            if ($apiAttachments !== []) {
                $payload['attachments'] = $apiAttachments;
            }

            $response = ResendMailClient::sendEmail($payload);
            $resendId = (string) ($response['id'] ?? '');
            $messageHeaderId = '<' . $resendId . '@' . self::domain() . '>';

            $update = $pdo->prepare(
                'UPDATE mail_messages
                 SET resend_email_id = ?, message_id = ?, sent_at = ?
                 WHERE id = ?'
            );
            $update->execute([
                $resendId !== '' ? $resendId : null,
                $messageHeaderId,
                $scheduledUtc ? null : $now,
                $messageId,
            ]);

            $pdo->commit();
            return self::getMessage((int) $user['id'], $messageId) ?? [];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function saveDraft(
        array $user,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $html,
        ?int $draftId = null,
        ?string $inReplyTo = null,
        array $attachments = []
    ): array {
        $html = MailHtml::sanitize($html);
        $text = MailHtml::plainText($html);
        $snippet = self::makeSnippet($text !== '' ? $text : $html);
        $now = gmdate('c');
        $pdo = Database::pdo();

        if ($draftId) {
            $existing = self::getMessage((int) $user['id'], $draftId);
            if (!$existing || ($existing['folder'] ?? '') !== 'drafts') {
                $draftId = null;
            }
        }

        if ($draftId) {
            $stmt = $pdo->prepare(
                'UPDATE mail_messages SET
                    to_emails = ?, cc_emails = ?, bcc_emails = ?, subject = ?,
                    body_html = ?, body_text = ?, snippet = ?, in_reply_to = ?
                 WHERE id = ? AND user_id = ? AND folder = ?'
            );
            $stmt->execute([
                json_encode(self::normalizeRecipients($to), JSON_THROW_ON_ERROR),
                json_encode(self::normalizeRecipients($cc), JSON_THROW_ON_ERROR),
                json_encode(self::normalizeRecipients($bcc), JSON_THROW_ON_ERROR),
                trim($subject),
                $html,
                $text,
                $snippet,
                $inReplyTo,
                $draftId,
                (int) $user['id'],
                'drafts',
            ]);
            $messageId = $draftId;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO mail_messages (
                    user_id, folder, category, direction, from_email, from_name,
                    to_emails, cc_emails, bcc_emails, subject, body_html, body_text, snippet,
                    is_read, is_starred, in_reply_to, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)'
            );
            $stmt->execute([
                (int) $user['id'],
                'drafts',
                'primary',
                'outbound',
                (string) $user['email'],
                (string) ($user['display_name'] ?: self::appName()),
                json_encode(self::normalizeRecipients($to), JSON_THROW_ON_ERROR),
                json_encode(self::normalizeRecipients($cc), JSON_THROW_ON_ERROR),
                json_encode(self::normalizeRecipients($bcc), JSON_THROW_ON_ERROR),
                trim($subject),
                $html,
                $text,
                $snippet,
                $inReplyTo,
                $now,
            ]);
            $messageId = (int) $pdo->lastInsertId();
        }

        foreach ($attachments as $attachment) {
            self::storeAttachmentFile($messageId, $attachment, 'drafts');
        }

        return self::getMessage((int) $user['id'], $messageId) ?? [];
    }

    public static function cancelScheduled(int $userId, int $messageId): array
    {
        $message = self::getMessage($userId, $messageId);
        if (!$message || ($message['folder'] ?? '') !== 'scheduled') {
            throw new \RuntimeException('Scheduled message not found.');
        }

        $resendId = (string) ($message['resend_email_id'] ?? '');
        if ($resendId !== '') {
            ResendMailClient::cancelEmail($resendId);
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE mail_messages
             SET folder = ?, scheduled_at = NULL, resend_email_id = NULL
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute(['drafts', $messageId, $userId]);

        return self::getMessage($userId, $messageId) ?? [];
    }

    /** @return array<string, mixed> */
    public static function composeContext(array $user, array $query): array
    {
        $mode = strtolower(trim((string) ($query['compose'] ?? 'new')));
        $context = [
            'mode' => $mode,
            'title' => 'New message',
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => '',
            'html' => '',
            'draft_id' => null,
            'in_reply_to' => null,
            'show_cc' => false,
            'show_bcc' => false,
        ];

        $retry = MailSession::get('mail_compose_retry');
        if (is_array($retry)) {
            return array_merge($context, $retry, ['mode' => $retry['mode'] ?? $mode]);
        }

        if ($mode === 'draft') {
            $draftId = (int) ($query['draft'] ?? 0);
            $draft = $draftId ? self::getMessage((int) $user['id'], $draftId) : null;
            if ($draft) {
                $context['title'] = 'Edit draft';
                $context['draft_id'] = $draftId;
                $context['to'] = implode(', ', self::decodeJsonList($draft['to_emails'] ?? '[]'));
                $context['cc'] = implode(', ', self::decodeJsonList($draft['cc_emails'] ?? '[]'));
                $context['bcc'] = implode(', ', self::decodeJsonList($draft['bcc_emails'] ?? '[]'));
                $context['subject'] = (string) ($draft['subject'] ?? '');
                $context['html'] = (string) ($draft['body_html'] ?? '');
                $context['in_reply_to'] = $draft['in_reply_to'] ?? null;
                $context['show_cc'] = $context['cc'] !== '';
                $context['show_bcc'] = $context['bcc'] !== '';
            }
            return $context;
        }

        $sourceId = (int) ($query['source'] ?? 0);
        $source = $sourceId ? self::getMessage((int) $user['id'], $sourceId) : null;
        if (!$source) {
            if ((int) ($user['signature_on_new'] ?? 1) === 1) {
                $sig = self::defaultSignature((int) $user['id']);
                if ($sig) {
                    $context['html'] = self::signatureBlock($sig);
                }
            }
            return $context;
        }

        $fromEmail = (string) ($source['from_email'] ?? '');
        $fromName = (string) ($source['from_name'] ?? '');
        $subject = (string) ($source['subject'] ?? '');
        $quote = self::buildQuoteBlock($source);

        if ($mode === 'reply' || $mode === 'reply-all') {
            $context['title'] = $mode === 'reply-all' ? 'Reply all' : 'Reply';
            $context['to'] = $fromEmail;
            if ($mode === 'reply-all') {
                $recipients = array_merge(
                    self::decodeJsonList($source['to_emails'] ?? '[]'),
                    self::decodeJsonList($source['cc_emails'] ?? '[]')
                );
                $recipients = array_values(array_filter(
                    $recipients,
                    static fn (string $email): bool => strcasecmp($email, (string) $user['email']) !== 0
                        && strcasecmp($email, $fromEmail) !== 0
                ));
                $context['cc'] = implode(', ', $recipients);
                $context['show_cc'] = $context['cc'] !== '';
            }
            $context['subject'] = self::prefixSubject($subject, 'Re:');
            $context['in_reply_to'] = (string) ($source['message_id'] ?? '');
        } elseif ($mode === 'forward') {
            $context['title'] = 'Forward';
            $context['subject'] = self::prefixSubject($subject, 'Fwd:');
        }

        $body = '';
        if ((int) ($user['signature_on_reply'] ?? 1) === 1) {
            $sig = self::defaultSignature((int) $user['id']);
            if ($sig) {
                $body = self::signatureBlock($sig) . '<br><br>';
            }
        }
        $context['html'] = $body . $quote;
        return $context;
    }

    /** @return list<array{label: string, value: string}> */
    public static function scheduleOptions(): array
    {
        $tz = self::appTimezone();
        $now = new \DateTimeImmutable('now', $tz);
        $tomorrow = $now->modify('+1 day');

        $options = [
            [
                'label' => 'Tomorrow morning',
                'value' => $tomorrow->setTime(8, 0)->format('Y-m-d H:i'),
            ],
            [
                'label' => 'Tomorrow afternoon',
                'value' => $tomorrow->setTime(13, 0)->format('Y-m-d H:i'),
            ],
        ];

        $monday = $now->modify('next monday')->setTime(8, 0);
        $options[] = [
            'label' => 'Monday morning',
            'value' => $monday->format('Y-m-d H:i'),
        ];

        return $options;
    }

    public static function timezoneLabel(): string
    {
        return self::appTimezone()->getName();
    }

    public static function formatScheduledAt(?string $utc): string
    {
        if ($utc === null || trim($utc) === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $dt->setTimezone(self::appTimezone())->format('D, j M Y, g:i A');
        } catch (\Exception) {
            return $utc;
        }
    }

    // -------------------------------------------------------------------------
    // Inbound
    // -------------------------------------------------------------------------

    public static function processInboundWebhook(array $event): array
    {
        if (($event['type'] ?? '') !== 'email.received') {
            return ['ok' => true, 'skipped' => true];
        }

        $emailId = (string) ($event['data']['email_id'] ?? '');
        if ($emailId === '') {
            throw new \RuntimeException('Missing email_id in webhook payload.');
        }

        return self::importReceivedEmail($emailId);
    }

    /**
     * @return array{ok: bool, message_id?: int, duplicate?: bool, reason?: string}
     */
    public static function importReceivedEmail(string $emailId): array
    {
        $emailId = trim($emailId);
        if ($emailId === '') {
            throw new \InvalidArgumentException('Missing received email id.');
        }

        $pdo = Database::pdo();
        $dup = $pdo->prepare('SELECT id FROM mail_messages WHERE resend_email_id = ? LIMIT 1');
        $dup->execute([$emailId]);
        if ($dup->fetchColumn()) {
            return ['ok' => true, 'duplicate' => true];
        }

        $email = ResendMailClient::getReceivedEmail($emailId);
        $recipient = self::resolveInboundRecipient($email);
        if ($recipient === null) {
            return ['ok' => false, 'reason' => 'no_recipient'];
        }

        $from = self::parseAddress($email['from'] ?? '');
        $toList = self::parseAddressList($email['to'] ?? []);
        $ccList = self::parseAddressList($email['cc'] ?? []);
        $headers = self::normalizeHeaders($email['headers'] ?? []);
        $category = self::categorizeInbound(
            (string) ($from['email'] ?? ''),
            (string) ($email['subject'] ?? ''),
            $headers
        );

        $html = MailHtml::sanitize((string) ($email['html'] ?? ''));
        $text = trim((string) ($email['text'] ?? ''));
        if ($text === '') {
            $text = MailHtml::plainText($html);
        }
        $snippet = self::makeSnippet($text !== '' ? $text : $html);
        $now = gmdate('c');
        $messageHeaderId = (string) ($headers['message-id'] ?? $headers['Message-ID'] ?? '');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO mail_messages (
                    user_id, folder, category, direction, from_email, from_name,
                    to_emails, cc_emails, bcc_emails, subject, body_html, body_text, snippet,
                    is_read, is_starred, message_id, in_reply_to, resend_email_id, received_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $recipient['id'],
                'inbox',
                $category,
                'inbound',
                (string) ($from['email'] ?? ''),
                (string) ($from['name'] ?? ''),
                json_encode($toList, JSON_THROW_ON_ERROR),
                json_encode($ccList, JSON_THROW_ON_ERROR),
                json_encode(self::parseAddressList($email['bcc'] ?? []), JSON_THROW_ON_ERROR),
                (string) ($email['subject'] ?? ''),
                $html,
                $text,
                $snippet,
                $messageHeaderId !== '' ? $messageHeaderId : null,
                $headers['in-reply-to'] ?? $headers['In-Reply-To'] ?? null,
                $emailId,
                $now,
                $now,
            ]);
            $messageId = (int) $pdo->lastInsertId();

            foreach ($email['attachments'] ?? [] as $attachmentMeta) {
                if (!is_array($attachmentMeta)) {
                    continue;
                }
                $attachmentId = (string) ($attachmentMeta['id'] ?? '');
                if ($attachmentId === '') {
                    continue;
                }
                $detail = ResendMailClient::getReceivedAttachment($emailId, $attachmentId);
                $downloadUrl = (string) ($detail['download_url'] ?? '');
                if ($downloadUrl === '') {
                    continue;
                }
                $binary = self::downloadRemoteFile($downloadUrl);
                $filename = (string) ($attachmentMeta['filename'] ?? 'attachment');
                $contentType = (string) ($attachmentMeta['content_type'] ?? 'application/octet-stream');
                $stored = self::storeBinaryAttachment(
                    $messageId,
                    $filename,
                    $contentType,
                    $binary,
                    'inbound',
                    $attachmentId
                );
                unset($stored);
            }

            $pdo->commit();
            return ['ok' => true, 'message_id' => $messageId, 'category' => $category];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pull recent received emails from Resend when webhooks were missed.
     *
     * @return array{ok: bool, imported: int, duplicates: int, skipped: int, pages: int}
     */
    public static function syncInboundFromResend(int $maxPages = 5): array
    {
        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $pages = 0;
        $after = null;

        do {
            $pages++;
            $response = ResendMailClient::listReceivedEmails(50, $after);
            $items = is_array($response['data'] ?? null) ? $response['data'] : [];
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $emailId = (string) ($item['id'] ?? '');
                if ($emailId === '') {
                    $skipped++;
                    continue;
                }

                $result = self::importReceivedEmail($emailId);
                if (!empty($result['duplicate'])) {
                    $duplicates++;
                    continue;
                }
                if (!empty($result['message_id'])) {
                    $imported++;
                    continue;
                }
                $skipped++;
            }

            $after = (string) ($items[array_key_last($items)]['id'] ?? '');
            $hasMore = !empty($response['has_more']) && $after !== '';
        } while ($hasMore && $pages < max(1, $maxPages));

        return [
            'ok' => true,
            'imported' => $imported,
            'duplicates' => $duplicates,
            'skipped' => $skipped,
            'pages' => $pages,
        ];
    }

    /** @return array{ok: bool, updated: int} */
    public static function resanitizeStoredInboundBodies(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT id, body_html, body_text FROM mail_messages WHERE direction = 'inbound' AND TRIM(body_html) != ''"
        );
        $update = $pdo->prepare(
            'UPDATE mail_messages SET body_html = ?, body_text = ?, snippet = ? WHERE id = ?'
        );
        $updated = 0;

        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $original = (string) ($row['body_html'] ?? '');
            $html = MailHtml::sanitize($original);
            if ($html === $original) {
                continue;
            }

            $text = MailHtml::plainText($html);
            if ($text === '') {
                $text = trim((string) ($row['body_text'] ?? ''));
            }
            $snippet = self::makeSnippet($text !== '' ? $text : $html);
            $update->execute([$html, $text, $snippet, (int) $row['id']]);
            $updated++;
        }

        return ['ok' => true, 'updated' => $updated];
    }

    /**
     * @param array<string, string> $headers
     */
    public static function categorizeInbound(string $from, string $subject, array $headers): string
    {
        $domain = self::emailDomain($from);
        foreach (self::SOCIAL_DOMAINS as $social) {
            if ($domain === $social || str_ends_with($domain, '.' . $social)) {
                return 'social';
            }
        }

        $local = self::emailLocalPart($from);
        foreach (self::PROMO_LOCAL_PARTS as $part) {
            if ($local === $part || str_starts_with($local, $part . '+') || str_starts_with($local, $part . '.')) {
                if (self::hasPromoHeaders($headers)) {
                    return 'promotions';
                }
            }
        }
        if (self::hasPromoHeaders($headers)) {
            return 'promotions';
        }

        foreach (self::UPDATE_LOCAL_PARTS as $part) {
            if ($local === $part || str_starts_with($local, $part . '+') || str_starts_with($local, $part . '.')) {
                return 'updates';
            }
        }

        $autoSubmitted = strtolower((string) ($headers['auto-submitted'] ?? $headers['Auto-Submitted'] ?? ''));
        if ($autoSubmitted !== '' && str_contains($autoSubmitted, 'auto')) {
            return 'updates';
        }

        if (self::looksTransactionalSubject($subject)) {
            return 'updates';
        }

        return 'primary';
    }

    // -------------------------------------------------------------------------
    // Media
    // -------------------------------------------------------------------------

    /**
     * @param array{path: string, name: string, type: string, size: int} $file
     * @return array<string, mixed>
     */
    public static function storeAttachmentFile(int $messageId, array $file, string $bucket): array
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/storage/mail/' . $bucket . '/' . $messageId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $safeName = self::randomFilename((string) $file['name']);
        $dest = $dir . '/' . $safeName;
        if (!copy((string) $file['path'], $dest)) {
            throw new \RuntimeException('Could not store attachment.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO mail_attachments (message_id, filename, content_type, size_bytes, storage_path, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $messageId,
            (string) $file['name'],
            (string) $file['type'],
            (int) $file['size'],
            $dest,
            gmdate('c'),
        ]);

        return [
            'id' => (int) Database::pdo()->lastInsertId(),
            'filename' => (string) $file['name'],
            'storage_path' => $dest,
        ];
    }

    public static function storeInlineImage(int $userId, string $binary, string $extension, string $mime): string
    {
        $ext = strtolower(preg_replace('/[^a-z0-9]/', '', $extension) ?: 'png');
        if ($ext === '') {
            $ext = 'png';
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $relative = 'mail-inline/' . $userId . '/' . $filename;
        $storagePath = dirname(__DIR__, 2) . '/storage/uploads/' . $relative;
        $publicPath = dirname(__DIR__, 2) . '/public/uploads/' . $relative;
        $dir = dirname($storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $publicDir = dirname($publicPath);
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        if (file_put_contents($storagePath, $binary) === false) {
            throw new \RuntimeException('Could not save inline image.');
        }
        copy($storagePath, $publicPath);

        return rtrim(AppUrl::base(), '/') . '/uploads/' . $relative;
    }

    public static function materializeInlineImages(string $html, int $userId): string
    {
        return (string) preg_replace_callback(
            '/<img\b[^>]*\bsrc=(["\'])(data:image\/[^"\']+)\1/i',
            function (array $matches) use ($userId): string {
                $dataUri = $matches[2];
                if (!preg_match('#^data:image/([a-zA-Z0-9+.-]+);base64,(.+)$#', $dataUri, $parts)) {
                    return $matches[0];
                }
                $mime = 'image/' . strtolower($parts[1]);
                $binary = base64_decode($parts[2], true);
                if ($binary === false) {
                    return $matches[0];
                }
                $url = self::storeInlineImage($userId, $binary, $parts[1], $mime);
                return str_replace($dataUri, $url, $matches[0]);
            },
            $html
        );
    }

    public static function storeAvatar(int $userId, string $sourcePath): string
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is required for avatar processing.');
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new \RuntimeException('Invalid image file.');
        }

        $mime = (string) ($info['mime'] ?? '');
        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png' => imagecreatefrompng($sourcePath),
            'image/gif' => imagecreatefromgif($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default => false,
        };
        if ($image === false) {
            throw new \RuntimeException('Unsupported image type.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $size = min($width, $height, 256);
        $srcX = (int) max(0, ($width - $size) / 2);
        $srcY = (int) max(0, ($height - $size) / 2);

        $canvas = imagecreatetruecolor(256, 256);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, $srcX, $srcY, 256, 256, $size, $size);
        imagedestroy($image);

        $filename = bin2hex(random_bytes(16)) . '.png';
        $relative = 'mail-avatars/' . $userId . '/' . $filename;
        $storagePath = dirname(__DIR__, 2) . '/storage/uploads/' . $relative;
        $publicPath = dirname(__DIR__, 2) . '/public/uploads/' . $relative;
        foreach ([dirname($storagePath), dirname($publicPath)] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        if (!imagepng($canvas, $storagePath)) {
            imagedestroy($canvas);
            throw new \RuntimeException('Could not save avatar.');
        }
        copy($storagePath, $publicPath);
        imagedestroy($canvas);

        self::removeAvatar($userId, false);
        $stmt = Database::pdo()->prepare(
            'UPDATE mail_users SET avatar_path = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$relative, gmdate('c'), $userId]);

        return $relative;
    }

    public static function removeAvatar(int $userId, bool $updateDb = true): void
    {
        $user = self::findUserById($userId);
        $path = (string) ($user['avatar_path'] ?? '');
        if ($path !== '') {
            $root = dirname(__DIR__, 2);
            @unlink($root . '/storage/uploads/' . $path);
            @unlink($root . '/public/uploads/' . $path);
        }
        if ($updateDb) {
            Database::pdo()->prepare(
                'UPDATE mail_users SET avatar_path = NULL, updated_at = ? WHERE id = ?'
            )->execute([gmdate('c'), $userId]);
        }
    }

    public static function avatarUrl(array $user): ?string
    {
        $path = (string) ($user['avatar_path'] ?? '');
        if ($path === '') {
            return null;
        }
        return rtrim(AppUrl::base(), '/') . '/uploads/' . ltrim($path, '/');
    }

    public static function avatarUrlForEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!str_ends_with($email, '@' . self::domain())) {
            return null;
        }
        $user = self::findUserByEmail($email);
        return $user ? self::avatarUrl($user) : null;
    }

    public static function htmlSandboxSrcdoc(string $html): string
    {
        $safe = MailHtml::sanitize($html);
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: data: cid:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\';">'
            . '<style>body{font-family:Inter,Arial,sans-serif;font-size:14px;line-height:1.5;color:#202124;margin:16px;word-break:break-word;}</style>'
            . '</head><body>' . $safe . '</body></html>';
    }

    // -------------------------------------------------------------------------
    // Signatures
    // -------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public static function listSignatures(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM mail_signatures WHERE user_id = ? ORDER BY is_default DESC, name ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function getSignature(int $userId, int $signatureId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM mail_signatures WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$signatureId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function saveSignature(int $userId, ?int $signatureId, string $name, string $html, bool $isDefault): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 120) {
            throw new \RuntimeException('Signature name must be between 1 and 120 characters.');
        }

        $html = MailHtml::sanitize($html);
        $html = self::materializeInlineImages($html, $userId);
        $now = gmdate('c');
        $pdo = Database::pdo();

        if ($signatureId) {
            $existing = self::getSignature($userId, $signatureId);
            if (!$existing) {
                throw new \RuntimeException('Signature not found.');
            }
            $stmt = $pdo->prepare(
                'UPDATE mail_signatures SET name = ?, body_html = ?, is_default = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([$name, $html, $isDefault ? 1 : 0, $now, $signatureId, $userId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO mail_signatures (user_id, name, body_html, is_default, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $name, $html, $isDefault ? 1 : 0, $now, $now]);
            $signatureId = (int) $pdo->lastInsertId();
        }

        if ($isDefault) {
            self::setDefaultSignature($userId, $signatureId);
        }

        return self::getSignature($userId, $signatureId) ?? [];
    }

    public static function deleteSignature(int $userId, int $signatureId): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM mail_signatures WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$signatureId, $userId]);
    }

    public static function setDefaultSignature(int $userId, int $signatureId): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE mail_signatures SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare(
            'UPDATE mail_signatures SET is_default = 1, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([gmdate('c'), $signatureId, $userId]);
    }

    public static function defaultSignature(int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM mail_signatures WHERE user_id = ? AND is_default = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            return $row;
        }

        $fallback = Database::pdo()->prepare(
            'SELECT * FROM mail_signatures WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1'
        );
        $fallback->execute([$userId]);
        $row = $fallback->fetch();
        return is_array($row) ? $row : null;
    }

    public static function updateSignaturePreferences(int $userId, bool $onNew, bool $onReply): void
    {
        Database::pdo()->prepare(
            'UPDATE mail_users SET signature_on_new = ?, signature_on_reply = ?, updated_at = ? WHERE id = ?'
        )->execute([$onNew ? 1 : 0, $onReply ? 1 : 0, gmdate('c'), $userId]);
    }

    public static function signatureBlock(array $signature): string
    {
        $html = MailHtml::sanitize((string) ($signature['body_html'] ?? ''));
        if ($html === '') {
            return '';
        }
        return '<div class="lb-mail-signature">' . $html . '</div>';
    }

    // -------------------------------------------------------------------------
    // Display helpers
    // -------------------------------------------------------------------------

    /** @return list<string> */
    public static function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $decoded)));
    }

    public static function formatListDate(?string $iso): string
    {
        if ($iso === null || trim($iso) === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($iso);
            $local = $dt->setTimezone(self::appTimezone());
            $today = new \DateTimeImmutable('today', self::appTimezone());
            if ($local->format('Y-m-d') === $today->format('Y-m-d')) {
                return $local->format('g:i A');
            }
            if ($local->format('Y') === $today->format('Y')) {
                return $local->format('M j');
            }
            return $local->format('M j, Y');
        } catch (\Exception) {
            return $iso;
        }
    }

    public static function displaySender(array $message): string
    {
        $name = trim((string) ($message['from_name'] ?? ''));
        $email = trim((string) ($message['from_email'] ?? ''));
        if ($name !== '' && $email !== '') {
            return $name . ' <' . $email . '>';
        }
        return $email !== '' ? $email : $name;
    }

    public static function makeSnippet(string $text, int $length = 140): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
        }
        return strlen($text) > $length ? rtrim(substr($text, 0, $length - 1)) . '…' : $text;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function normalizeUser(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) ($row['is_active'] ?? 0);
        $row['must_change_password'] = (int) ($row['must_change_password'] ?? 0);
        $row['signature_on_new'] = (int) ($row['signature_on_new'] ?? 1);
        $row['signature_on_reply'] = (int) ($row['signature_on_reply'] ?? 1);
        return $row;
    }

    private static function normalizeMessage(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['is_read'] = (int) ($row['is_read'] ?? 0);
        $row['is_starred'] = (int) ($row['is_starred'] ?? 0);
        return $row;
    }

    private static function normalizeFolder(string $folder): string
    {
        $folder = strtolower(trim($folder));
        if ($folder === 'starred') {
            return 'starred';
        }
        return in_array($folder, self::FOLDERS, true) ? $folder : 'inbox';
    }

    private static function assertPasswordStrength(string $password): void
    {
        if (strlen($password) < 12) {
            throw new \RuntimeException('Password must be at least 12 characters.');
        }
    }

    private static function assertDomainEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid email address.');
        }
        if (!str_ends_with(strtolower($email), '@' . self::domain())) {
            throw new \RuntimeException('Mailbox must use @' . self::domain() . '.');
        }
    }

    private static function assertNotLastActiveAdmin(int $userId, string $role, int $isActive): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM mail_users WHERE role = ? AND is_active = 1 AND id != ?'
        );
        $stmt->execute(['admin', $userId]);
        $remaining = (int) $stmt->fetchColumn();
        if ($remaining < 1) {
            throw new \RuntimeException('Cannot disable or demote the last active admin.');
        }
    }

    /** @param list<string> $emails */
    private static function normalizeRecipients(array $emails): array
    {
        $out = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '') {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Invalid recipient: ' . $email);
            }
            $out[] = $email;
        }
        return array_values(array_unique($out));
    }

    private static function parseScheduledAt(string $local): string
    {
        $tz = self::appTimezone();
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', trim($local), $tz);
        if (!$dt) {
            throw new \RuntimeException('Invalid schedule date.');
        }

        $now = new \DateTimeImmutable('now', $tz);
        $min = $now->modify('+1 minute');
        $max = $now->modify('+90 days');
        if ($dt < $min) {
            throw new \RuntimeException('Scheduled time must be at least one minute in the future.');
        }
        if ($dt > $max) {
            throw new \RuntimeException('Scheduled time must be within 90 days.');
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function appTimezone(): \DateTimeZone
    {
        $tz = trim((string) Env::get('APP_TIMEZONE', 'Europe/London'));
        try {
            return new \DateTimeZone($tz !== '' ? $tz : 'Europe/London');
        } catch (\Exception) {
            return new \DateTimeZone('Europe/London');
        }
    }

    private static function prefixSubject(string $subject, string $prefix): string
    {
        $subject = trim($subject);
        $pattern = '/^' . preg_quote($prefix, '/') . '\s*/i';
        if (preg_match($pattern, $subject)) {
            return $subject;
        }
        return $prefix . ' ' . $subject;
    }

    /** @param array<string, mixed> $source */
    private static function buildQuoteBlock(array $source): string
    {
        $sender = self::displaySender($source);
        $email = (string) ($source['from_email'] ?? '');
        $dateIso = (string) ($source['received_at'] ?? $source['sent_at'] ?? $source['created_at'] ?? '');
        try {
            $date = $dateIso !== ''
                ? (new \DateTimeImmutable($dateIso))->setTimezone(self::appTimezone())->format('D, j M Y, g:i A')
                : 'unknown date';
        } catch (\Exception) {
            $date = 'unknown date';
        }
        $body = MailHtml::sanitize((string) ($source['body_html'] ?? ''));
        if ($body === '') {
            $body = nl2br(htmlspecialchars((string) ($source['body_text'] ?? ''), ENT_QUOTES, 'UTF-8'));
        }

        return '<div class="lb-mail-quote">On ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8')
            . ', ' . htmlspecialchars($sender, ENT_QUOTES, 'UTF-8')
            . ' &lt;' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '&gt; wrote:'
            . '<blockquote style="margin:0 0 0 8px;padding-left:12px;border-left:2px solid #e1e5ee">'
            . $body . '</blockquote></div>';
    }

    /** @return array{email: string, name: string} */
    private static function parseAddress(mixed $value): array
    {
        if (is_array($value)) {
            $email = strtolower(trim((string) ($value['email'] ?? $value['address'] ?? '')));
            $name = trim((string) ($value['name'] ?? ''));
            return ['email' => $email, 'name' => $name];
        }

        $string = trim((string) $value);
        if (preg_match('/^(.*)<([^>]+)>$/', $string, $matches)) {
            return [
                'name' => trim($matches[1], " \t\""),
                'email' => strtolower(trim($matches[2])),
            ];
        }
        return ['email' => strtolower($string), 'name' => ''];
    }

    /** @return list<string> */
    private static function parseAddressList(mixed $value): array
    {
        if (!is_array($value)) {
            $parsed = self::parseAddress($value);
            return $parsed['email'] !== '' ? [$parsed['email']] : [];
        }

        $emails = [];
        foreach ($value as $item) {
            $parsed = self::parseAddress($item);
            if ($parsed['email'] !== '') {
                $emails[] = $parsed['email'];
            }
        }
        return array_values(array_unique($emails));
    }

    /** @param array<string, mixed> $email */
    private static function resolveInboundRecipient(array $email): ?array
    {
        $candidates = array_merge(
            self::parseAddressList($email['to'] ?? []),
            self::parseAddressList($email['cc'] ?? []),
            self::parseAddressList($email['bcc'] ?? [])
        );

        foreach ($candidates as $candidate) {
            $user = self::findUserByEmail($candidate);
            if ($user && (int) ($user['is_active'] ?? 0) === 1) {
                return $user;
            }
        }

        $catchAll = strtolower(trim((string) Env::get('MAIL_CATCH_ALL_EMAIL', '')));
        if ($catchAll !== '') {
            return self::findUserByEmail($catchAll);
        }

        return null;
    }

    /**
     * @param array<string|list<string>> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $name = strtolower((string) $key);
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $out[$name] = trim((string) $value);
        }
        return $out;
    }

    /** @param array<string, string> $headers */
    private static function hasPromoHeaders(array $headers): bool
    {
        if (isset($headers['list-unsubscribe']) || isset($headers['list-id'])) {
            return true;
        }
        $precedence = strtolower($headers['precedence'] ?? '');
        return in_array($precedence, ['bulk', 'list', 'junk'], true);
    }

    private static function looksTransactionalSubject(string $subject): bool
    {
        $subject = strtolower($subject);
        $patterns = [
            'verification code', 'verify your', 'password reset', 'reset your password',
            'your code is', 'one-time', 'otp', 'invoice', 'receipt', 'order shipped',
            'delivery update', 'payment received', 'security alert',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($subject, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private static function emailDomain(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);
        return $parts[1] ?? '';
    }

    private static function emailLocalPart(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);
        return $parts[0] ?? '';
    }

    private static function isAllowedMime(string $mime, string $ext): bool
    {
        $mime = strtolower($mime);
        if ($mime === 'application/octet-stream') {
            return true;
        }
        if (str_starts_with($mime, 'text/') || str_starts_with($mime, 'image/')) {
            return true;
        }
        $allowed = [
            'application/pdf', 'application/zip', 'application/x-zip-compressed',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        return in_array($mime, $allowed, true);
    }

    private static function randomFilename(string $original): string
    {
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        return bin2hex(random_bytes(16)) . '.' . $ext;
    }

    private static function deleteMessageFiles(int $messageId): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['inbound', 'outbound', 'drafts'] as $bucket) {
            $dir = $root . '/storage/mail/' . $bucket . '/' . $messageId;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        $stmt = Database::pdo()->prepare('SELECT storage_path FROM mail_attachments WHERE message_id = ?');
        $stmt->execute([$messageId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            @unlink((string) ($row['storage_path'] ?? ''));
        }
    }

    private static function downloadRemoteFile(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Could not download attachment.');
        }
        return $body;
    }

    /** @return array<string, mixed> */
    private static function storeBinaryAttachment(
        int $messageId,
        string $filename,
        string $contentType,
        string $binary,
        string $bucket,
        ?string $resendAttachmentId = null
    ): array {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/storage/mail/' . $bucket . '/' . $messageId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $safeName = self::randomFilename($filename);
        $dest = $dir . '/' . $safeName;
        if (file_put_contents($dest, $binary) === false) {
            throw new \RuntimeException('Could not store inbound attachment.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO mail_attachments (
                message_id, filename, content_type, size_bytes, storage_path, resend_attachment_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $messageId,
            $filename,
            $contentType,
            strlen($binary),
            $dest,
            $resendAttachmentId,
            gmdate('c'),
        ]);

        return [
            'id' => (int) Database::pdo()->lastInsertId(),
            'filename' => $filename,
            'storage_path' => $dest,
        ];
    }
}
