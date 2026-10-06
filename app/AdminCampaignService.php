<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Admin email campaigns with audience segments (Mailchimp-style).
 */
final class AdminCampaignService
{
    public const SEGMENTS = [
        'all_users' => [
            'label' => 'All users',
            'help' => 'Every registered account',
            'source' => 'users',
        ],
        'premium' => [
            'label' => 'Premium users',
            'help' => 'Active Premium subscriptions',
            'source' => 'users',
        ],
        'free' => [
            'label' => 'Free users',
            'help' => 'Accounts that are not on active Premium',
            'source' => 'users',
        ],
        'fpl_linked' => [
            'label' => 'FPL team linked',
            'help' => 'Users who imported their FPL squad',
            'source' => 'users',
        ],
        'fpl_not_linked' => [
            'label' => 'FPL team not linked',
            'help' => 'Registered users with no FPL team yet',
            'source' => 'users',
        ],
        'premium_no_team' => [
            'label' => 'Premium without team',
            'help' => 'Paying users who still need to link a team',
            'source' => 'users',
        ],
        'unverified' => [
            'label' => 'Email not confirmed',
            'help' => 'Registered but email not verified',
            'source' => 'users',
        ],
        'incomplete_signup' => [
            'label' => 'Incomplete signups',
            'help' => 'Started signup but never finished OTP or account creation',
            'source' => 'pending',
        ],
        'expired_premium' => [
            'label' => 'Expired Premium',
            'help' => 'Previously Premium, now expired',
            'source' => 'users',
        ],
    ];

    public static function ensureSchema(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id INTEGER NULL,
                subject TEXT NOT NULL,
                body_text TEXT NOT NULL,
                cta_label TEXT NULL,
                cta_url TEXT NULL,
                segments_json TEXT NOT NULL DEFAULT \'[]\',
                status TEXT NOT NULL DEFAULT \'draft\',
                recipient_count INTEGER NOT NULL DEFAULT 0,
                sent_count INTEGER NOT NULL DEFAULT 0,
                failed_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                sent_at TEXT NULL,
                FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_campaign_recipients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                email TEXT NOT NULL COLLATE NOCASE,
                name TEXT NULL,
                source TEXT NOT NULL DEFAULT \'users\',
                user_id INTEGER NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                error TEXT NULL,
                sent_at TEXT NULL,
                FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_email_campaign_recipients_campaign
             ON email_campaign_recipients(campaign_id)'
        );
    }

    /**
     * @return list<array{id:string,label:string,help:string,count:int}>
     */
    public static function segmentCards(): array
    {
        self::ensureSchema();
        $out = [];
        foreach (self::SEGMENTS as $id => $meta) {
            $out[] = [
                'id' => $id,
                'label' => $meta['label'],
                'help' => $meta['help'],
                'count' => self::countSegment($id),
            ];
        }

        return $out;
    }

    public static function countSegment(string $segment): int
    {
        return count(self::resolveAudience([$segment]));
    }

    /**
     * @param list<string> $segments
     * @return list<array{email:string,name:?string,source:string,user_id:?int}>
     */
    public static function resolveAudience(array $segments): array
    {
        $segments = array_values(array_unique(array_filter(array_map('strval', $segments))));
        if ($segments === []) {
            return [];
        }

        $byEmail = [];
        foreach ($segments as $segment) {
            if (!isset(self::SEGMENTS[$segment])) {
                continue;
            }
            foreach (self::fetchSegmentRows($segment) as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if (!isset($byEmail[$email])) {
                    $byEmail[$email] = $row;
                }
            }
        }

        $out = array_values($byEmail);
        usort($out, static fn (array $a, array $b): int => strcasecmp((string) $a['email'], (string) $b['email']));

        return $out;
    }

    /**
     * @param list<string> $segments
     * @return array{count:int,recipients:list<array{email:string,name:?string,source:string,user_id:?int}>}
     */
    public static function previewAudience(array $segments, int $limit = 50): array
    {
        $all = self::resolveAudience($segments);
        $limit = max(1, min(200, $limit));

        return [
            'count' => count($all),
            'recipients' => array_slice($all, 0, $limit),
        ];
    }

    /**
     * @param list<string> $segments
     * @return array<string, mixed>
     */
    public static function createAndSend(
        array $admin,
        string $subject,
        string $bodyContent,
        array $segments,
        ?string $ctaLabel = null,
        ?string $ctaUrl = null,
        bool $isHtml = false
    ): array {
        self::ensureSchema();

        $subject = trim($subject);
        $bodyContent = trim($bodyContent);
        $ctaLabel = $ctaLabel !== null ? trim($ctaLabel) : '';
        $ctaUrl = $ctaUrl !== null ? trim($ctaUrl) : '';

        if ($subject === '') {
            throw new \InvalidArgumentException('Subject is required.');
        }
        if ($bodyContent === '' || ($isHtml && trim(strip_tags($bodyContent)) === '')) {
            throw new \InvalidArgumentException('Message body is required.');
        }
        if ($segments === []) {
            throw new \InvalidArgumentException('Choose at least one audience segment.');
        }
        foreach ($segments as $segment) {
            if (!isset(self::SEGMENTS[(string) $segment])) {
                throw new \InvalidArgumentException('Unknown segment: ' . $segment);
            }
        }
        if (($ctaLabel !== '' && $ctaUrl === '') || ($ctaLabel === '' && $ctaUrl !== '')) {
            throw new \InvalidArgumentException('CTA label and URL must both be set, or both left blank.');
        }
        if ($ctaUrl !== '' && !preg_match('#^https?://#i', $ctaUrl) && !str_starts_with($ctaUrl, '/')) {
            throw new \InvalidArgumentException('CTA URL must start with https:// or /');
        }

        $bodyHtml = $isHtml ? self::sanitizeBodyHtml($bodyContent) : self::plainTextToHtml($bodyContent);
        $bodyText = self::htmlToPlainText($bodyHtml);

        $recipients = self::resolveAudience($segments);
        if ($recipients === []) {
            throw new \InvalidArgumentException('No recipients match the selected segments.');
        }

        $pdo = Database::pdo();
        $now = gmdate('c');
        $adminId = (int) ($admin['id'] ?? 0);

        $pdo->prepare(
            'INSERT INTO email_campaigns
             (admin_user_id, subject, body_text, cta_label, cta_url, segments_json, status, recipient_count, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $adminId > 0 ? $adminId : null,
            $subject,
            $bodyHtml,
            $ctaLabel !== '' ? $ctaLabel : null,
            $ctaUrl !== '' ? $ctaUrl : null,
            json_encode(array_values($segments), JSON_THROW_ON_ERROR),
            'sending',
            count($recipients),
            $now,
        ]);
        $campaignId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO email_campaign_recipients
             (campaign_id, email, name, source, user_id, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($recipients as $row) {
            $insert->execute([
                $campaignId,
                $row['email'],
                $row['name'],
                $row['source'],
                $row['user_id'],
                'pending',
            ]);
        }

        $sent = 0;
        $failed = 0;
        $update = $pdo->prepare(
            'UPDATE email_campaign_recipients SET status = ?, error = ?, sent_at = ? WHERE campaign_id = ? AND email = ?'
        );

        foreach ($recipients as $row) {
            $email = (string) $row['email'];
            $greeting = self::greetingFor($row['name'] ?? null);
            $html = self::buildHtml(
                $bodyHtml,
                $ctaLabel !== '' ? $ctaLabel : null,
                $ctaUrl !== '' ? $ctaUrl : null,
                $greeting,
                true
            );
            $text = self::buildText(
                $bodyText,
                $ctaLabel !== '' ? $ctaLabel : null,
                $ctaUrl !== '' ? $ctaUrl : null,
                $greeting
            );
            try {
                ResendMailer::send($email, $subject, $html, $text);
                $update->execute(['sent', null, gmdate('c'), $campaignId, $email]);
                $sent += 1;
            } catch (\Throwable $e) {
                $update->execute(['failed', substr($e->getMessage(), 0, 500), null, $campaignId, $email]);
                $failed += 1;
            }
            usleep(80000);
        }

        $status = $failed === 0 ? 'sent' : ($sent === 0 ? 'failed' : 'partial');
        $pdo->prepare(
            'UPDATE email_campaigns SET status = ?, sent_count = ?, failed_count = ?, sent_at = ? WHERE id = ?'
        )->execute([$status, $sent, $failed, gmdate('c'), $campaignId]);

        Database::pdo()->prepare(
            'INSERT INTO admin_audit_log (admin_user_id, action, target_user_id, detail_json, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $adminId > 0 ? $adminId : null,
            'email_campaign_send',
            null,
            json_encode([
                'campaign_id' => $campaignId,
                'subject' => $subject,
                'segments' => $segments,
                'sent' => $sent,
                'failed' => $failed,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            gmdate('c'),
        ]);

        return self::getCampaign($campaignId) ?? [
            'id' => $campaignId,
            'status' => $status,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'recipient_count' => count($recipients),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listCampaigns(int $limit = 30): array
    {
        self::ensureSchema();
        $limit = max(1, min(100, $limit));
        $stmt = Database::pdo()->query(
            'SELECT id, subject, segments_json, status, recipient_count, sent_count, failed_count, created_at, sent_at
             FROM email_campaigns
             ORDER BY id DESC
             LIMIT ' . $limit
        );

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $rows[] = self::mapCampaign($row);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCampaign(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM email_campaigns WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $campaign = self::mapCampaign($row);
        $recStmt = Database::pdo()->prepare(
            'SELECT email, name, source, status, error, sent_at
             FROM email_campaign_recipients
             WHERE campaign_id = ?
             ORDER BY email ASC
             LIMIT 200'
        );
        $recStmt->execute([$id]);
        $campaign['recipients'] = $recStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $campaign;
    }

    public static function buildHtml(
        string $bodyContent,
        ?string $ctaLabel = null,
        ?string $ctaUrl = null,
        string $greeting = 'Dear user',
        bool $isHtml = false
    ): string {
        $inner = EmailTemplate::paragraph($greeting . ',');
        if ($isHtml) {
            $inner .= self::emailSafeHtml($bodyContent);
        } else {
            foreach (preg_split("/\n{2,}/", trim($bodyContent)) ?: [] as $block) {
                $block = trim((string) $block);
                if ($block === '') {
                    continue;
                }
                $lines = preg_split("/\n/", $block) ?: [];
                $joined = implode(' ', array_map('trim', $lines));
                $inner .= EmailTemplate::paragraph($joined);
            }
        }

        if ($ctaLabel && $ctaUrl) {
            $url = str_starts_with($ctaUrl, '/') ? AppUrl::to(ltrim($ctaUrl, '/')) : $ctaUrl;
            $inner .= EmailTemplate::button($ctaLabel, $url);
            $inner .= EmailTemplate::linkFallback($url);
        }

        return EmailTemplate::render($inner);
    }

    public static function buildText(
        string $bodyText,
        ?string $ctaLabel = null,
        ?string $ctaUrl = null,
        string $greeting = 'Dear user'
    ): string {
        $text = $greeting . ",\n\n" . trim($bodyText);
        if ($ctaLabel && $ctaUrl) {
            $url = str_starts_with($ctaUrl, '/') ? AppUrl::to(ltrim($ctaUrl, '/')) : $ctaUrl;
            $text .= "\n\n{$ctaLabel}: {$url}";
        }

        return $text;
    }

    public static function plainTextToHtml(string $bodyText): string
    {
        $html = '';
        foreach (preg_split("/\n{2,}/", trim($bodyText)) ?: [] as $block) {
            $block = trim((string) $block);
            if ($block === '') {
                continue;
            }
            $html .= '<p>' . nl2br(htmlspecialchars($block, ENT_QUOTES, 'UTF-8'), false) . '</p>';
        }

        return $html !== '' ? $html : '<p></p>';
    }

    public static function sanitizeBodyHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a>';
        $clean = strip_tags($html, $allowed);

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8"><div id="ae-root">' . $clean . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('ae-root');
        if (!$root) {
            return strip_tags($clean, $allowed);
        }

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*[@*]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $attrs = [];
            foreach ($node->attributes ?? [] as $attr) {
                $attrs[] = $attr->name;
            }
            foreach ($attrs as $name) {
                if ($node->tagName === 'a' && strtolower($name) === 'href') {
                    $href = trim($node->getAttribute('href'));
                    if ($href === '' || preg_match('#^(javascript|data):#i', $href)) {
                        $node->removeAttribute('href');
                    } elseif (!preg_match('#^(https?:)?/#i', $href) && !str_starts_with($href, 'mailto:')) {
                        $node->setAttribute('href', 'https://' . ltrim($href, '/'));
                    }
                    continue;
                }
                $node->removeAttribute($name);
            }
        }

        foreach ($xpath->query('//a[not(@href)]') ?: [] as $badLink) {
            if ($badLink instanceof \DOMElement) {
                while ($badLink->firstChild) {
                    $badLink->parentNode?->insertBefore($badLink->firstChild, $badLink);
                }
                $badLink->parentNode?->removeChild($badLink);
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    public static function emailSafeHtml(string $html): string
    {
        $html = self::sanitizeBodyHtml($html);
        if ($html === '') {
            return '';
        }

        $pStyle = 'margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.6;color:#374151;';
        $listStyle = 'margin:0 0 16px;padding-left:1.35rem;font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.6;color:#374151;';
        $liStyle = 'margin:0 0 0.35rem;';
        $aStyle = 'color:#dc2626;text-decoration:underline;';

        $html = preg_replace('/<p(\s[^>]*)?>/i', '<p style="' . $pStyle . '">', $html) ?? $html;
        $html = preg_replace('/<ul(\s[^>]*)?>/i', '<ul style="' . $listStyle . '">', $html) ?? $html;
        $html = preg_replace('/<ol(\s[^>]*)?>/i', '<ol style="' . $listStyle . '">', $html) ?? $html;
        $html = preg_replace('/<li(\s[^>]*)?>/i', '<li style="' . $liStyle . '">', $html) ?? $html;
        $html = preg_replace_callback(
            '/<a\s+([^>]*?)href=("|\')(.*?)\2([^>]*)>/i',
            static function (array $m): string {
                $href = htmlspecialchars(html_entity_decode($m[3], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                return '<a href="' . $href . '" style="color:#dc2626;text-decoration:underline;" target="_blank" rel="noopener">';
            },
            $html
        ) ?? $html;

        return $html;
    }

    public static function htmlToPlainText(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ["\n", "\n", "\n", "\n\n", "\n"], $html)), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    public static function greetingFor(?string $name): string
    {
        $first = self::firstNameFrom($name);
        return $first !== '' ? 'Dear ' . $first : 'Dear user';
    }

    public static function firstNameFrom(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        if (str_contains($name, '@')) {
            return '';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $first = trim((string) ($parts[0] ?? ''));
        $first = preg_replace('/[^a-zA-Z0-9\'\-]/', '', $first) ?? '';
        if ($first === '' || strlen($first) < 2) {
            return '';
        }

        return strtoupper($first[0]) . strtolower(substr($first, 1));
    }

    /**
     * @return list<array{email:string,name:?string,source:string,user_id:?int}>
     */
    private static function fetchSegmentRows(string $segment): array
    {
        $pdo = Database::pdo();

        if ($segment === 'incomplete_signup') {
            $stmt = $pdo->query(
                'SELECT email, NULL AS name, \'pending\' AS source, NULL AS user_id
                 FROM pending_signups
                 ORDER BY id DESC'
            );
            $rows = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $rows[] = [
                        'email' => (string) $row['email'],
                        'name' => null,
                        'source' => 'pending',
                        'user_id' => null,
                    ];
                }
            }

            return $rows;
        }

        $base = 'is_admin = 0 AND is_restricted = 0';

        $where = match ($segment) {
            'all_users' => $base,
            'premium' => "{$base} AND plan = 'premium' AND subscription_status = 'active'",
            'free' => "{$base} AND NOT (plan = 'premium' AND subscription_status = 'active')",
            'fpl_linked' => "{$base} AND fpl_team_id IS NOT NULL",
            'fpl_not_linked' => "{$base} AND fpl_team_id IS NULL",
            'premium_no_team' => "{$base} AND plan = 'premium' AND subscription_status = 'active' AND fpl_team_id IS NULL",
            'unverified' => "{$base} AND email_verified_at IS NULL AND (google_id IS NULL OR google_id = '')",
            'expired_premium' => "{$base} AND (subscription_status = 'expired' OR (plan = 'premium' AND subscription_status != 'active'))",
            default => '0=1',
        };

        $stmt = $pdo->query(
            "SELECT id AS user_id, email, name
             FROM users
             WHERE {$where}
             ORDER BY id DESC"
        );

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'email' => (string) $row['email'],
                'name' => isset($row['name']) && $row['name'] !== '' ? (string) $row['name'] : null,
                'source' => 'users',
                'user_id' => (int) $row['user_id'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapCampaign(array $row): array
    {
        $segments = [];
        $decoded = json_decode((string) ($row['segments_json'] ?? '[]'), true);
        if (is_array($decoded)) {
            $segments = array_values(array_filter(array_map('strval', $decoded)));
        }

        $segmentLabels = [];
        foreach ($segments as $id) {
            $segmentLabels[] = self::SEGMENTS[$id]['label'] ?? $id;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'subject' => (string) ($row['subject'] ?? ''),
            'body_text' => (string) ($row['body_text'] ?? ''),
            'cta_label' => $row['cta_label'] ?? null,
            'cta_url' => $row['cta_url'] ?? null,
            'segments' => $segments,
            'segment_labels' => $segmentLabels,
            'status' => (string) ($row['status'] ?? 'draft'),
            'recipient_count' => (int) ($row['recipient_count'] ?? 0),
            'sent_count' => (int) ($row['sent_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'sent_at' => $row['sent_at'] ?? null,
        ];
    }
}
