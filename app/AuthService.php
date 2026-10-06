<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AuthService
{
    public static function normalizePlan(?string $plan): string
    {
        $plan = strtolower(trim((string) $plan));
        return $plan === 'premium' ? 'premium' : 'free';
    }

    public static function postAuthPath(array $user, ?string $intended = null): string
    {
        $intended = self::resolveIntended($intended);
        $nextQ = $intended !== null ? '?next=' . rawurlencode($intended) : '';

        if (self::needsEmailVerification($user)) {
            return '/verify-email' . $nextQ;
        }

        $plan = (string) ($user['plan'] ?? 'free');
        $status = (string) ($user['subscription_status'] ?? 'none');

        if (empty($user['fpl_team_id']) && self::pathNeedsFplTeam($intended)) {
            return '/link-team' . $nextQ;
        }

        if ($intended !== null) {
            self::consumeReturnPath();
        }

        return $intended ?? '/live';
    }

    public static function sanitizeReturnPath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        $pathOnly = explode('?', $path, 2)[0];
        $pathOnly = explode('#', $pathOnly, 2)[0];
        $blocked = ['login', 'register', 'join', 'verify-otp', 'verify-email', 'payment'];
        foreach ($blocked as $segment) {
            if ($pathOnly === '/' . $segment || str_starts_with($pathOnly, '/' . $segment . '/')) {
                return null;
            }
        }

        return $path;
    }

    public static function rememberReturnPath(?string $path): void
    {
        $safe = self::sanitizeReturnPath($path);
        if ($safe === null) {
            return;
        }

        Session::start();
        $_SESSION['auth_return_to'] = $safe;
    }

    public static function peekReturnPath(): ?string
    {
        Session::start();
        return self::sanitizeReturnPath($_SESSION['auth_return_to'] ?? null);
    }

    public static function consumeReturnPath(): ?string
    {
        Session::start();
        $path = self::sanitizeReturnPath($_SESSION['auth_return_to'] ?? null);
        unset($_SESSION['auth_return_to']);
        return $path;
    }

    public static function resolveIntended(?string $explicit = null): ?string
    {
        return self::sanitizeReturnPath($explicit) ?? self::peekReturnPath();
    }

    public static function appendReturnToPath(string $path, ?string $intended = null): string
    {
        $intended = self::resolveIntended($intended);
        if ($intended === null) {
            return $path;
        }

        return $path . '?next=' . rawurlencode($intended);
    }

    public static function pathNeedsFplTeam(?string $path): bool
    {
        $path = self::sanitizeReturnPath($path);
        if ($path === null) {
            return true;
        }

        $base = strtok($path, '?#') ?: $path;
        $publicPrefixes = [
            '/gameweek-recap',
            '/price-changes',
            '/fixture-difficulty',
            '/fpl-injury-news',
            '/league-table',
            '/fpl-gameweek-',
            '/players',
            '/about',
            '/contact',
            '/help-center',
            '/terms',
            '/disclaimer',
            '/privacy-policy',
        ];

        if ($base === '/' || $base === '/index.html') {
            return false;
        }

        foreach ($publicPrefixes as $prefix) {
            if ($base === $prefix || str_starts_with($base, $prefix)) {
                return false;
            }
        }

        return true;
    }

    public static function needsTeamLink(array $user): bool
    {
        return empty($user['fpl_team_id']);
    }

    public static function needsEmailVerification(array $user): bool
    {
        return empty($user['email_verified_at']) && empty($user['google_id']);
    }

    /** Drop abandoned Premium checkout back to Free. */
    public static function revertUnpaidPremium(int $userId): ?array
    {
        $user = self::findUserById($userId);
        if (
            !$user
            || ($user['plan'] ?? '') !== 'premium'
            || ($user['subscription_status'] ?? '') === 'active'
        ) {
            return $user;
        }

        $now = gmdate('c');
        Database::pdo()->prepare(
            'UPDATE users SET plan = ?, subscription_status = ?, billing_cycle = NULL, subscription_expires_at = NULL, updated_at = ? WHERE id = ?'
        )->execute(['free', 'none', $now, $userId]);

        return self::findUserById($userId);
    }

    public static function loginWithPassword(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $user = self::findUserByEmail($email);

        if (!$user || empty($user['password_hash']) || !password_verify($password, (string) $user['password_hash'])) {
            throw new \InvalidArgumentException('Email or password is not correct.');
        }

        if (!empty($user['is_restricted'])) {
            throw new \InvalidArgumentException('This account has been restricted. Contact support if you think this is a mistake.');
        }

        $user = self::revertUnpaidPremium((int) $user['id']) ?: $user;
        Session::login($user);
        return $user;
    }

    public static function findUserByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findUserById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return BillingService::syncUserSubscription((int) $row['id']) ?? $row;
    }

    public static function startEmailSignup(string $email, string $password, string $plan): void
    {
        $email = strtolower(trim($email));
        $plan = self::normalizePlan($plan);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }

        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }

        if (self::findUserByEmail($email)) {
            throw new \InvalidArgumentException('An account with this email already exists. Please log in.');
        }

        $pdo = Database::pdo();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = gmdate('c');

        $pdo->prepare(
            'INSERT INTO pending_signups (email, password_hash, plan, created_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(email) DO UPDATE SET
               password_hash = excluded.password_hash,
               plan = excluded.plan,
               created_at = excluded.created_at'
        )->execute([$email, $hash, $plan, $now]);

        self::issueAndSendOtp($email);
    }

    public static function issueAndSendOtp(string $email): void
    {
        $email = strtolower(trim($email));
        $code = (string) random_int(100000, 999999);
        $now = gmdate('c');
        $expires = gmdate('c', time() + 600);

        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM otp_codes WHERE email = ? COLLATE NOCASE')->execute([$email]);
        $pdo->prepare(
            'INSERT INTO otp_codes (email, code_hash, purpose, expires_at, attempts, created_at)
             VALUES (?, ?, ?, ?, 0, ?)'
        )->execute([$email, password_hash($code, PASSWORD_DEFAULT), 'signup', $expires, $now]);

        ResendMailer::sendOtp($email, $code);
    }

    public static function verifySignupOtp(string $email, string $code): array
    {
        $email = strtolower(trim($email));
        $code = trim($code);

        if (!preg_match('/^\d{6}$/', $code)) {
            throw new \InvalidArgumentException('Enter the 6 digit code from your email.');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM otp_codes WHERE email = ? COLLATE NOCASE AND purpose = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$email, 'signup']);
        $otp = $stmt->fetch();

        if (!$otp) {
            throw new \InvalidArgumentException('No code found. Please request a new one.');
        }

        if ((int) $otp['attempts'] >= 5) {
            throw new \InvalidArgumentException('Too many attempts. Please request a new code.');
        }

        if (strtotime((string) $otp['expires_at']) < time()) {
            throw new \InvalidArgumentException('That code has expired. Please request a new one.');
        }

        if (!password_verify($code, (string) $otp['code_hash'])) {
            $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $otp['id']]);
            throw new \InvalidArgumentException('That code is not correct.');
        }

        $pendingStmt = $pdo->prepare('SELECT * FROM pending_signups WHERE email = ? COLLATE NOCASE LIMIT 1');
        $pendingStmt->execute([$email]);
        $pending = $pendingStmt->fetch();
        if (!$pending) {
            throw new \InvalidArgumentException('Signup session expired. Please start again.');
        }

        $now = gmdate('c');
        $chosenPlan = self::normalizePlan((string) $pending['plan']);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, plan, subscription_status, email_verified_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)'
        )->execute([
            $email,
            $pending['password_hash'],
            'free',
            'none',
            $now,
            $now,
        ]);

        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('DELETE FROM pending_signups WHERE email = ? COLLATE NOCASE')->execute([$email]);
        $pdo->prepare('DELETE FROM otp_codes WHERE email = ? COLLATE NOCASE')->execute([$email]);

        $user = self::findUserById($userId);
        if (!$user) {
            throw new \RuntimeException('Could not create your account.');
        }

        ResendMailer::sendWelcome((string) $user['email'], $chosenPlan);
        AdminNotificationService::userRegistered($user, $chosenPlan, 'email');
        self::sendEmailConfirmation($user);
        Session::login($user);

        if ($chosenPlan === 'premium') {
            Session::flash('signup_checkout', '1');
        }

        return $user;
    }

    public static function resendEmailConfirmation(int $userId): void
    {
        $user = self::findUserById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('Account not found.');
        }

        if (!self::needsEmailVerification($user)) {
            throw new \InvalidArgumentException('Your email is already confirmed.');
        }

        self::sendEmailConfirmation($user);
    }

    public static function sendEmailConfirmation(array $user): void
    {
        $token = bin2hex(random_bytes(32));
        $now = gmdate('c');
        $expires = gmdate('c', time() + 60 * 60 * 24);

        Database::pdo()->prepare(
            'INSERT INTO email_confirmations (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?)'
        )->execute([(int) $user['id'], hash('sha256', $token), $expires, $now]);

        $url = AppUrl::to('api/auth/confirm-email?token=' . urlencode($token));
        ResendMailer::sendConfirmEmail((string) $user['email'], $url);
    }

    public static function confirmEmail(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new \InvalidArgumentException('Missing confirmation token.');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM email_confirmations WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new \InvalidArgumentException('This confirmation link is not valid.');
        }

        if ($row['used_at'] !== null) {
            throw new \InvalidArgumentException('This confirmation link was already used.');
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            throw new \InvalidArgumentException('This confirmation link has expired.');
        }

        $now = gmdate('c');
        $pdo->prepare('UPDATE users SET email_verified_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$now, $now, (int) $row['user_id']]);
        $pdo->prepare('UPDATE email_confirmations SET used_at = ? WHERE id = ?')
            ->execute([$now, (int) $row['id']]);

        $user = self::findUserById((int) $row['user_id']);
        if (!$user) {
            throw new \RuntimeException('Account not found.');
        }

        AdminNotificationService::emailConfirmed($user);

        return $user;
    }

    public static function upsertGoogleUser(string $googleId, string $email, ?string $name, string $plan): array
    {
        $email = strtolower(trim($email));
        $plan = self::normalizePlan($plan);
        $pdo = Database::pdo();
        $now = gmdate('c');

        $existing = self::findUserByEmail($email);
        $isNew = $existing === null;
        if ($existing) {
            $pdo->prepare(
                'UPDATE users SET google_id = ?, name = COALESCE(?, name), email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?'
            )->execute([$googleId, $name, $now, $now, (int) $existing['id']]);

            $user = self::findUserById((int) $existing['id']);
        } else {
            $pdo->prepare(
                'INSERT INTO users (email, password_hash, google_id, name, plan, subscription_status, email_verified_at, created_at, updated_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$email, $googleId, $name, 'free', 'none', $now, $now, $now]);
            $user = self::findUserById((int) $pdo->lastInsertId());
            if ($plan === 'premium') {
                Session::flash('signup_checkout', '1');
            }
        }

        if (!$user) {
            throw new \RuntimeException('Could not sign in with Google.');
        }

        if ($isNew) {
            ResendMailer::sendWelcome((string) $user['email'], $plan, true);
            AdminNotificationService::userRegistered($user, $plan, 'google');
        }

        Session::login($user);
        return $user;
    }
}
