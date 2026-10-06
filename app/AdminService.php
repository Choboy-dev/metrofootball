<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AdminService
{
    public static function isAdmin(?array $user): bool
    {
        return is_array($user) && !empty($user['is_admin']);
    }

    public static function requireAdmin(): array
    {
        $userId = Session::userId();
        if ($userId === null) {
            Response::json(['ok' => false, 'error' => 'Admin sign in required.'], 401);
        }

        $user = AuthService::findUserById($userId);
        if (!$user || !self::isAdmin($user)) {
            Response::json(['ok' => false, 'error' => 'You do not have admin access.'], 403);
        }

        if (!empty($user['is_restricted'])) {
            Response::json(['ok' => false, 'error' => 'This admin account is restricted.'], 403);
        }

        return $user;
    }

    public static function login(string $email, string $password): array
    {
        $user = AuthService::loginWithPassword($email, $password);
        if (!self::isAdmin($user)) {
            Session::logout();
            throw new \InvalidArgumentException('This account does not have admin access.');
        }

        Session::start();
        $_SESSION['admin_mode'] = true;

        return self::publicUser($user);
    }

    public static function logout(): void
    {
        Session::logout();
    }

    /** @return array<string, mixed> */
    public static function dashboard(): array
    {
        $pdo = Database::pdo();
        $now = time();
        $day7 = gmdate('c', $now - 7 * 86400);
        $day30 = gmdate('c', $now - 30 * 86400);

        $users = self::scalar($pdo, 'SELECT COUNT(*) FROM users');
        $users7 = self::scalar($pdo, 'SELECT COUNT(*) FROM users WHERE created_at >= ?', [$day7]);
        $users30 = self::scalar($pdo, 'SELECT COUNT(*) FROM users WHERE created_at >= ?', [$day30]);
        $premium = self::scalar(
            $pdo,
            "SELECT COUNT(*) FROM users WHERE plan = 'premium' AND subscription_status = 'active'"
        );
        $restricted = self::scalar($pdo, 'SELECT COUNT(*) FROM users WHERE is_restricted = 1');
        $linked = self::scalar($pdo, 'SELECT COUNT(*) FROM users WHERE fpl_team_id IS NOT NULL');
        $verified = self::scalar($pdo, 'SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL');

        $revenueTotal = self::scalarFloat(
            $pdo,
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'successful' AND currency = 'GBP'"
        );
        $revenue30 = self::scalarFloat(
            $pdo,
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'successful' AND currency = 'GBP' AND paid_at >= ?",
            [$day30]
        );
        $revenue7 = self::scalarFloat(
            $pdo,
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'successful' AND currency = 'GBP' AND paid_at >= ?",
            [$day7]
        );
        $paymentsPending = self::scalar($pdo, "SELECT COUNT(*) FROM payments WHERE status = 'pending'");
        $paymentsFailed = self::scalar($pdo, "SELECT COUNT(*) FROM payments WHERE status = 'failed'");

        $mrr = self::estimateMrr($pdo);

        $aiTotal = self::scalarFloat($pdo, 'SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage_log');
        $ai30 = self::scalarFloat(
            $pdo,
            'SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage_log WHERE created_at >= ?',
            [$day30]
        );
        $ai7 = self::scalarFloat(
            $pdo,
            'SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage_log WHERE created_at >= ?',
            [$day7]
        );
        $aiCalls = self::scalar($pdo, 'SELECT COUNT(*) FROM ai_usage_log');
        $tipsToday = self::scalar(
            $pdo,
            'SELECT COALESCE(SUM(used_count), 0) FROM ai_tip_usage WHERE day_key = ?',
            [gmdate('Y-m-d')]
        );

        return [
            'users' => [
                'total' => $users,
                'new_7d' => $users7,
                'new_30d' => $users30,
                'premium_active' => $premium,
                'free' => max(0, $users - $premium),
                'restricted' => $restricted,
                'fpl_linked' => $linked,
                'email_verified' => $verified,
            ],
            'revenue_gbp' => [
                'total' => round($revenueTotal, 2),
                'last_30d' => round($revenue30, 2),
                'last_7d' => round($revenue7, 2),
                'mrr_estimate' => round($mrr, 2),
                'pending_payments' => $paymentsPending,
                'failed_payments' => $paymentsFailed,
            ],
            'ai_usd' => [
                'total' => round($aiTotal, 4),
                'last_30d' => round($ai30, 4),
                'last_7d' => round($ai7, 4),
                'calls_total' => $aiCalls,
                'pick_tips_today' => $tipsToday,
            ],
            'charts' => [
                'signups_30d' => self::dailyCounts($pdo, 'users', 'created_at', 30),
                'revenue_30d' => self::dailyRevenue($pdo, 30),
                'ai_cost_30d' => self::dailyAiCost($pdo, 30),
            ],
            'ai_by_service' => self::aiByService($pdo, $day30),
        ];
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public static function listUsers(int $page = 1, int $perPage = 25, ?string $q = null, ?string $filter = null): array
    {
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($q !== null && trim($q) !== '') {
            $where[] = '(email LIKE ? OR name LIKE ? OR fpl_manager_name LIKE ?)';
            $like = '%' . trim($q) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($filter === 'premium') {
            $where[] = "plan = 'premium' AND subscription_status = 'active'";
        } elseif ($filter === 'restricted') {
            $where[] = 'is_restricted = 1';
        } elseif ($filter === 'admin') {
            $where[] = 'is_admin = 1';
        } elseif ($filter === 'unverified') {
            $where[] = 'email_verified_at IS NULL';
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, email, name, plan, subscription_status, billing_cycle, subscription_expires_at,
                    email_verified_at, fpl_team_id, fpl_team_name, fpl_manager_name, is_admin, is_restricted,
                    restricted_reason, admin_note, created_at, updated_at
             FROM users WHERE ' . $whereSql . '
             ORDER BY id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([...$params, $perPage, $offset]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = self::publicUser($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public static function getUser(int $id): ?array
    {
        $user = AuthService::findUserById($id);
        return $user ? self::publicUser($user, true) : null;
    }

    /** @param array<string,mixed> $actions */
    public static function updateUser(array $admin, int $userId, array $actions): array
    {
        $user = AuthService::findUserById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found.');
        }

        if ((int) $user['id'] === (int) $admin['id'] && !empty($actions['remove_admin'])) {
            throw new \InvalidArgumentException('You cannot remove your own admin access.');
        }

        $pdo = Database::pdo();
        $now = gmdate('c');
        $audit = [];

        if (array_key_exists('is_restricted', $actions)) {
            $restricted = !empty($actions['is_restricted']) ? 1 : 0;
            $reason = trim((string) ($actions['restricted_reason'] ?? ''));
            $pdo->prepare(
                'UPDATE users SET is_restricted = ?, restricted_reason = ?, updated_at = ? WHERE id = ?'
            )->execute([$restricted, $reason !== '' ? $reason : null, $now, $userId]);
            $audit[] = $restricted ? 'restrict' : 'unrestrict';
        }

        if (array_key_exists('admin_note', $actions)) {
            $note = trim((string) $actions['admin_note']);
            $pdo->prepare('UPDATE users SET admin_note = ?, updated_at = ? WHERE id = ?')
                ->execute([$note !== '' ? $note : null, $now, $userId]);
            $audit[] = 'note';
        }

        if (!empty($actions['grant_premium'])) {
            $cycle = strtolower((string) ($actions['billing_cycle'] ?? 'monthly')) === 'yearly' ? 'yearly' : 'monthly';
            $expires = $cycle === 'yearly'
                ? gmdate('c', time() + 365 * 86400)
                : gmdate('c', time() + 31 * 86400);
            $pdo->prepare(
                "UPDATE users SET plan = 'premium', subscription_status = 'active', billing_cycle = ?, subscription_expires_at = ?, updated_at = ? WHERE id = ?"
            )->execute([$cycle, $expires, $now, $userId]);
            $audit[] = 'grant_premium';
        }

        if (!empty($actions['revoke_premium'])) {
            $pdo->prepare(
                "UPDATE users SET plan = 'free', subscription_status = 'none', billing_cycle = NULL, subscription_expires_at = NULL, updated_at = ? WHERE id = ?"
            )->execute([$now, $userId]);
            $audit[] = 'revoke_premium';
        }

        if (!empty($actions['make_admin'])) {
            $pdo->prepare('UPDATE users SET is_admin = 1, updated_at = ? WHERE id = ?')->execute([$now, $userId]);
            $audit[] = 'make_admin';
        }

        if (!empty($actions['remove_admin'])) {
            $pdo->prepare('UPDATE users SET is_admin = 0, updated_at = ? WHERE id = ?')->execute([$now, $userId]);
            $audit[] = 'remove_admin';
        }

        if (!empty($actions['verify_email'])) {
            $pdo->prepare('UPDATE users SET email_verified_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$now, $now, $userId]);
            $audit[] = 'verify_email';
        }

        foreach ($audit as $action) {
            self::audit($admin, $action, $userId, $actions);
        }

        $updated = AuthService::findUserById($userId);
        return self::publicUser($updated ?: $user, true);
    }

    public static function deleteUser(array $admin, int $userId): void
    {
        if ((int) $admin['id'] === $userId) {
            throw new \InvalidArgumentException('You cannot delete your own account from admin.');
        }

        $user = AuthService::findUserById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found.');
        }

        Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        self::audit($admin, 'delete_user', $userId, ['email' => $user['email']]);
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public static function listPayments(int $page = 1, int $perPage = 30): array
    {
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $total = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT p.*, u.email AS user_email
             FROM payments p
             LEFT JOIN users u ON u.id = p.user_id
             ORDER BY p.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$perPage, $offset]);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public static function listAiUsage(int $page = 1, int $perPage = 40, int $days = 30): array
    {
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $since = gmdate('c', time() - max(1, min(365, $days)) * 86400);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ai_usage_log WHERE created_at >= ?');
        $countStmt->execute([$since]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT l.*, u.email AS user_email
             FROM ai_usage_log l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.created_at >= ?
             ORDER BY l.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$since, $perPage, $offset]);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function recentAudit(int $limit = 20): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, admin.email AS admin_email, target.email AS target_email
             FROM admin_audit_log a
             LEFT JOIN users admin ON admin.id = a.admin_user_id
             LEFT JOIN users target ON target.id = a.target_user_id
             ORDER BY a.id DESC LIMIT ?'
        );
        $stmt->execute([max(1, min(100, $limit))]);
        return $stmt->fetchAll();
    }

    public static function systemStatus(): array
    {
        return [
            'openai' => OpenAiClient::configured(),
            'openai_model' => OpenAiClient::model(),
            'flutterwave' => Flutterwave::isConfigured(),
            'tavily' => TavilyClient::configured(),
            'app_env' => (string) Env::get('APP_ENV', 'production'),
        ];
    }

    public static function ensureAdminUser(string $email, string $password, ?string $name = null): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid admin email.');
        }
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('Admin password must be at least 12 characters.');
        }

        $pdo = Database::pdo();
        $now = gmdate('c');
        $existing = AuthService::findUserByEmail($email);

        if ($existing) {
            $pdo->prepare(
                'UPDATE users SET password_hash = ?, is_admin = 1, is_restricted = 0, email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?'
            )->execute([
                password_hash($password, PASSWORD_DEFAULT),
                $now,
                $now,
                (int) $existing['id'],
            ]);
            return AuthService::findUserById((int) $existing['id']) ?: $existing;
        }

        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, plan, subscription_status, email_verified_at, is_admin, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $name ?: 'Metro Football Admin',
            'free',
            'none',
            $now,
            $now,
            $now,
        ]);

        return AuthService::findUserByEmail($email) ?: [];
    }

    private static function publicUser(array $user, bool $detail = false): array
    {
        $out = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'name' => (string) ($user['name'] ?? ''),
            'plan' => (string) ($user['plan'] ?? 'free'),
            'subscription_status' => (string) ($user['subscription_status'] ?? 'none'),
            'billing_cycle' => $user['billing_cycle'] ?? null,
            'subscription_expires_at' => $user['subscription_expires_at'] ?? null,
            'email_verified' => !empty($user['email_verified_at']),
            'fpl_linked' => !empty($user['fpl_team_id']),
            'fpl_team_name' => $user['fpl_team_name'] ?? null,
            'fpl_manager_name' => $user['fpl_manager_name'] ?? null,
            'is_admin' => !empty($user['is_admin']),
            'is_restricted' => !empty($user['is_restricted']),
            'restricted_reason' => $user['restricted_reason'] ?? null,
            'created_at' => $user['created_at'] ?? null,
        ];

        if ($detail) {
            $out['admin_note'] = $user['admin_note'] ?? null;
            $out['updated_at'] = $user['updated_at'] ?? null;
            $out['fpl_team_id'] = $user['fpl_team_id'] ?? null;
        }

        return $out;
    }

    private static function audit(array $admin, string $action, ?int $targetUserId, array $detail = []): void
    {
        Database::pdo()->prepare(
            'INSERT INTO admin_audit_log (admin_user_id, action, target_user_id, detail_json, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            (int) $admin['id'],
            $action,
            $targetUserId,
            $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            gmdate('c'),
        ]);
    }

    private static function estimateMrr(PDO $pdo): float
    {
        $monthly = self::scalar(
            $pdo,
            "SELECT COUNT(*) FROM users WHERE plan = 'premium' AND subscription_status = 'active' AND billing_cycle = 'monthly'"
        );
        $yearly = self::scalar(
            $pdo,
            "SELECT COUNT(*) FROM users WHERE plan = 'premium' AND subscription_status = 'active' AND billing_cycle = 'yearly'"
        );

        return ($monthly * 4.0) + ($yearly * 38.5 / 12.0);
    }

    /** @return list<array{date:string,count:int}> */
    private static function dailyCounts(PDO $pdo, string $table, string $column, int $days): array
    {
        $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $stmt = $pdo->prepare(
            "SELECT substr($column, 1, 10) AS day, COUNT(*) AS cnt
             FROM $table WHERE substr($column, 1, 10) >= ?
             GROUP BY day ORDER BY day ASC"
        );
        $stmt->execute([$since]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['day']] = (int) $row['cnt'];
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = ['date' => $day, 'count' => $map[$day] ?? 0];
        }

        return $out;
    }

    /** @return list<array{date:string,amount:float}> */
    private static function dailyRevenue(PDO $pdo, int $days): array
    {
        $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $stmt = $pdo->prepare(
            "SELECT substr(paid_at, 1, 10) AS day, COALESCE(SUM(amount), 0) AS total
             FROM payments WHERE status = 'successful' AND currency = 'GBP' AND substr(paid_at, 1, 10) >= ?
             GROUP BY day ORDER BY day ASC"
        );
        $stmt->execute([$since]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['day']] = round((float) $row['total'], 2);
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = ['date' => $day, 'amount' => $map[$day] ?? 0.0];
        }

        return $out;
    }

    /** @return list<array{date:string,cost:float}> */
    private static function dailyAiCost(PDO $pdo, int $days): array
    {
        $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $stmt = $pdo->prepare(
            "SELECT substr(created_at, 1, 10) AS day, COALESCE(SUM(cost_usd), 0) AS total
             FROM ai_usage_log WHERE substr(created_at, 1, 10) >= ?
             GROUP BY day ORDER BY day ASC"
        );
        $stmt->execute([$since]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['day']] = round((float) $row['total'], 4);
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = ['date' => $day, 'cost' => $map[$day] ?? 0.0];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function aiByService(PDO $pdo, string $since): array
    {
        $stmt = $pdo->prepare(
            'SELECT service, provider, COUNT(*) AS calls, COALESCE(SUM(cost_usd), 0) AS cost_usd,
                    COALESCE(SUM(tokens_in), 0) AS tokens_in, COALESCE(SUM(tokens_out), 0) AS tokens_out
             FROM ai_usage_log WHERE created_at >= ?
             GROUP BY service, provider ORDER BY cost_usd DESC'
        );
        $stmt->execute([$since]);
        return $stmt->fetchAll();
    }

    private static function scalar(PDO $pdo, string $sql, array $params = []): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private static function scalarFloat(PDO $pdo, string $sql, array $params = []): float
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }
}
