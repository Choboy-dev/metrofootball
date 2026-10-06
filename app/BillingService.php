<?php

declare(strict_types=1);

namespace App;

final class BillingService
{
    public const CURRENCY = 'GBP';
    public const CURRENCY_NGN = 'NGN';

    public static function currency(?string $countryCode = null): string
    {
        return self::usesNaira($countryCode) ? self::CURRENCY_NGN : self::CURRENCY;
    }

    public static function usesNaira(?string $countryCode = null): bool
    {
        if ($countryCode !== null) {
            return strtoupper(trim($countryCode)) === 'NG';
        }

        return VisitorCountry::isNigeria();
    }

    /** @return array{cycle:string,amount:float,label:string,period:string,currency:string,display_amount:string,display_period:string,note:string,charge_amount:float,charge_currency:string,gbp_amount:float,gbp_label:string,gbp_display_amount:string,rate_updated_at?:string,rate_source?:string} */
    public static function plan(string $cycle, ?string $countryCode = null): array
    {
        $gbpPlan = self::gbpPlan($cycle);
        if (!self::usesNaira($countryCode)) {
            return self::withChargeAmounts($gbpPlan, (float) $gbpPlan['amount'], self::CURRENCY);
        }

        return self::ngnPlan($gbpPlan);
    }

    /** @param array<string,mixed> $gbpPlan @return array<string,mixed> */
    private static function withChargeAmounts(array $gbpPlan, float $chargeAmount, string $chargeCurrency): array
    {
        $gbpAmount = (float) $gbpPlan['amount'];

        return array_merge($gbpPlan, [
            'amount' => $gbpAmount,
            'currency' => self::CURRENCY,
            'charge_amount' => $chargeAmount,
            'charge_currency' => $chargeCurrency,
            'gbp_amount' => $gbpAmount,
            'gbp_label' => (string) $gbpPlan['label'],
            'gbp_display_amount' => (string) ($gbpPlan['display_amount'] ?? $gbpPlan['label']),
        ]);
    }

    /** @return array{cycle:string,amount:float,label:string,period:string,currency:string,display_amount:string,display_period:string,note:string} */
    private static function gbpPlan(string $cycle): array
    {
        $cycle = strtolower(trim($cycle)) === 'yearly' ? 'yearly' : 'monthly';

        if ($cycle === 'yearly') {
            return [
                'cycle' => 'yearly',
                'amount' => 38.5,
                'display_gbp_amount' => 3.5,
                'label' => '£38.50',
                'period' => '/ year',
                'display_amount' => '£3.50',
                'display_period' => '/ month',
                'note' => 'Billed yearly',
                'currency' => self::CURRENCY,
            ];
        }

        return [
            'cycle' => 'monthly',
            'amount' => 4.0,
            'display_gbp_amount' => 4.0,
            'label' => '£4',
            'period' => '/ month',
            'display_amount' => '£4',
            'display_period' => '/ month',
            'note' => 'Billed every month',
            'currency' => self::CURRENCY,
        ];
    }

    /** @param array<string,mixed> $gbpPlan @return array<string,mixed> */
    private static function ngnPlan(array $gbpPlan): array
    {
        $conversion = Flutterwave::gbpToNgn((float) $gbpPlan['amount']);
        $ngnAmount = (float) $conversion['ngn'];
        $cycle = (string) ($gbpPlan['cycle'] ?? 'monthly');

        $displayGbp = (float) ($gbpPlan['display_gbp_amount'] ?? $gbpPlan['amount']);
        $displayConversion = $displayGbp === (float) $gbpPlan['amount']
            ? $conversion
            : Flutterwave::gbpToNgn($displayGbp);
        $displayNgn = (float) $displayConversion['ngn'];

        $plan = self::withChargeAmounts($gbpPlan, $ngnAmount, self::CURRENCY_NGN);
        $plan['label'] = '₦' . number_format($ngnAmount);
        $plan['display_amount'] = '₦' . number_format($displayNgn);
        $plan['note'] = $cycle === 'yearly' ? 'Billed yearly' : 'Billed every month';
        $plan['rate_updated_at'] = (string) $conversion['fetched_at'];
        $plan['rate_source'] = (string) $conversion['source'];

        return $plan;
    }

    private static function formatChargeAmount(float $amount, string $currency): string
    {
        if ($currency === self::CURRENCY_NGN) {
            return (string) (int) round($amount);
        }

        return number_format($amount, 2, '.', '');
    }

    /** @return array{url:string} */
    public static function createCheckout(array $user, string $cycle): array
    {
        if (!Flutterwave::isConfigured()) {
            throw new \RuntimeException('Flutterwave is not configured. Add FLW_SECRET_KEY in config/.env');
        }

        Flutterwave::assertLiveKeysForProduction();

        $visitorCountry = VisitorCountry::code();
        $plan = self::plan($cycle, $visitorCountry);
        $chargeCurrency = strtoupper((string) ($plan['charge_currency'] ?? self::CURRENCY));
        $chargeAmount = (float) ($plan['charge_amount'] ?? $plan['amount']);
        $revenueAmount = (float) ($plan['gbp_amount'] ?? $plan['amount']);
        $txRef = 'mf_' . (int) $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4));
        $now = gmdate('c');

        Database::pdo()->prepare(
            'INSERT INTO payments (user_id, tx_ref, amount, currency, charge_amount, charge_currency, billing_cycle, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $user['id'],
            $txRef,
            $revenueAmount,
            self::CURRENCY,
            $chargeAmount,
            $chargeCurrency,
            $plan['cycle'],
            'pending',
            $now,
            $now,
        ]);

        $paymentOptions = VisitorCountry::paymentOptions($visitorCountry);

        $payment = Flutterwave::createPayment([
            'tx_ref' => $txRef,
            'amount' => self::formatChargeAmount($chargeAmount, $chargeCurrency),
            'currency' => $chargeCurrency,
            'redirect_url' => AppUrl::to('api/billing/callback'),
            'payment_options' => $paymentOptions,
            'customer' => [
                'email' => (string) $user['email'],
                'name' => (string) ($user['name'] ?: 'Metro Football user'),
            ],
            'customizations' => [
                'title' => 'Metro Football Premium',
                'description' => $plan['label'],
            ],
            'meta' => [
                'user_id' => (int) $user['id'],
                'billing_cycle' => $plan['cycle'],
                'product' => 'metro_football_premium',
                'visitor_country' => $visitorCountry ?? 'unknown',
                'payment_options' => $paymentOptions,
                'charge_currency' => $chargeCurrency,
                'revenue_gbp' => $revenueAmount,
            ],
        ]);

        return [
            'url' => $payment['link'],
        ];
    }

    public static function isPremiumActive(array $user): bool
    {
        if (($user['plan'] ?? '') !== 'premium') {
            return false;
        }

        if (($user['subscription_status'] ?? '') !== 'active') {
            return false;
        }

        $expires = trim((string) ($user['subscription_expires_at'] ?? ''));
        if ($expires === '') {
            return true;
        }

        $expiresAt = strtotime($expires);

        return $expiresAt !== false && $expiresAt > time();
    }

    public static function syncUserSubscription(int $userId): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        if (!self::shouldExpire($user)) {
            return $user;
        }

        self::expireSubscription($userId);

        $stmt->execute([$userId]);
        $fresh = $stmt->fetch();

        return $fresh ?: null;
    }

    /** @return array{expired:int, reminders_7d:int, reminders_1d:int, expired_emails:int} */
    public static function runDailySubscriptionJobs(): array
    {
        $stats = [
            'expired' => 0,
            'reminders_7d' => 0,
            'reminders_1d' => 0,
            'expired_emails' => 0,
        ];

        $pdo = Database::pdo();
        $now = gmdate('c');
        $nowTs = time();

        $active = $pdo->query(
            "SELECT * FROM users
             WHERE plan = 'premium'
               AND subscription_status = 'active'
               AND subscription_expires_at IS NOT NULL
               AND subscription_expires_at != ''"
        );

        if ($active === false) {
            return $stats;
        }

        while ($user = $active->fetch()) {
            if (!is_array($user)) {
                continue;
            }

            $userId = (int) ($user['id'] ?? 0);
            $expiresTs = strtotime((string) ($user['subscription_expires_at'] ?? ''));
            if ($userId <= 0 || $expiresTs === false) {
                continue;
            }

            if ($expiresTs <= $nowTs) {
                self::expireSubscription($userId);
                $stats['expired']++;
                continue;
            }

            $daysLeft = (int) ceil(($expiresTs - $nowTs) / 86400);
            $email = (string) ($user['email'] ?? '');
            $expiresLabel = gmdate('j M Y', $expiresTs);

            if ($daysLeft <= 7 && $daysLeft >= 6 && empty($user['subscription_reminder_7d_at']) && $email !== '') {
                ResendMailer::sendSubscriptionRenewalReminder($email, $expiresLabel, $daysLeft);
                $pdo->prepare('UPDATE users SET subscription_reminder_7d_at = ?, updated_at = ? WHERE id = ?')
                    ->execute([$now, $now, $userId]);
                $stats['reminders_7d']++;
            }

            if ($daysLeft <= 1 && empty($user['subscription_reminder_1d_at']) && $email !== '') {
                ResendMailer::sendSubscriptionRenewalReminder($email, $expiresLabel, $daysLeft);
                $pdo->prepare('UPDATE users SET subscription_reminder_1d_at = ?, updated_at = ? WHERE id = ?')
                    ->execute([$now, $now, $userId]);
                $stats['reminders_1d']++;
            }
        }

        $expiredUsers = $pdo->query(
            "SELECT * FROM users
             WHERE subscription_status = 'expired'
               AND subscription_expired_email_at IS NULL"
        );

        if ($expiredUsers !== false) {
            while ($user = $expiredUsers->fetch()) {
                if (!is_array($user)) {
                    continue;
                }
                $userId = (int) ($user['id'] ?? 0);
                $email = (string) ($user['email'] ?? '');
                if ($userId <= 0 || $email === '') {
                    continue;
                }
                ResendMailer::sendSubscriptionExpired($email);
                $pdo->prepare('UPDATE users SET subscription_expired_email_at = ?, updated_at = ? WHERE id = ?')
                    ->execute([$now, $now, $userId]);
                $stats['expired_emails']++;
            }
        }

        return $stats;
    }

    public static function subscriptionNotice(array $user): ?string
    {
        if (!self::isPremiumActive($user)) {
            return null;
        }

        $expires = trim((string) ($user['subscription_expires_at'] ?? ''));
        if ($expires === '') {
            return null;
        }

        $expiresTs = strtotime($expires);
        if ($expiresTs === false) {
            return null;
        }

        $daysLeft = (int) ceil(($expiresTs - time()) / 86400);
        if ($daysLeft <= 0) {
            return null;
        }

        if ($daysLeft > 7) {
            return null;
        }

        $dateLabel = gmdate('j M Y', $expiresTs);

        if ($daysLeft === 1) {
            return 'Your Premium plan ends tomorrow (' . $dateLabel . '). Renew at Payment to keep your tools unlocked.';
        }

        return 'Your Premium plan ends on ' . $dateLabel . '. Renew at Payment to keep your tools unlocked.';
    }

    public static function markSuccessfulFromVerification(array $data): ?array
    {
        $status = strtolower((string) ($data['status'] ?? ''));
        if ($status !== 'successful') {
            self::markFailedByTxRef((string) ($data['tx_ref'] ?? ''), (string) ($data['id'] ?? ''));
            return null;
        }

        $txRef = (string) ($data['tx_ref'] ?? '');
        if ($txRef === '') {
            throw new \InvalidArgumentException('Missing transaction reference.');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE tx_ref = ? LIMIT 1');
        $stmt->execute([$txRef]);
        $payment = $stmt->fetch();

        if (!$payment) {
            throw new \RuntimeException('Payment record not found.');
        }

        $expectedCurrency = strtoupper((string) ($payment['charge_currency'] ?? $payment['currency'] ?? self::CURRENCY));
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($currency !== $expectedCurrency) {
            throw new \RuntimeException('Payment currency did not match.');
        }

        $amountPaid = (float) ($data['amount'] ?? 0);
        $expectedAmount = (float) ($payment['charge_amount'] ?? $payment['amount']);
        $tolerance = $expectedCurrency === self::CURRENCY_NGN ? 1.0 : 0.009;
        if (abs($amountPaid - $expectedAmount) > $tolerance) {
            throw new \RuntimeException('Payment amount did not match.');
        }

        if (($payment['status'] ?? '') === 'successful') {
            $existing = self::syncUserSubscription((int) $payment['user_id']);
            return $existing;
        }

        $now = gmdate('c');
        $flwId = (string) ($data['id'] ?? '');
        $cycle = (string) $payment['billing_cycle'];

        $userStmt = $pdo->prepare('SELECT subscription_expires_at FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([(int) $payment['user_id']]);
        $currentUser = $userStmt->fetch();
        $currentExpires = is_array($currentUser)
            ? (string) ($currentUser['subscription_expires_at'] ?? '')
            : '';
        $expires = self::computeExpiresAt($cycle, $currentExpires !== '' ? $currentExpires : null);

        $pdo->prepare(
            'UPDATE payments SET status = ?, flw_tx_id = ?, paid_at = ?, updated_at = ? WHERE id = ?'
        )->execute(['successful', $flwId, $now, $now, (int) $payment['id']]);

        $pdo->prepare(
            'UPDATE users SET plan = ?, subscription_status = ?, billing_cycle = ?, subscription_expires_at = ?,
             subscription_reminder_7d_at = NULL, subscription_reminder_1d_at = NULL, subscription_expired_email_at = NULL,
             updated_at = ? WHERE id = ?'
        )->execute(['premium', 'active', $cycle, $expires, $now, (int) $payment['user_id']]);

        $user = self::syncUserSubscription((int) $payment['user_id']);
        $email = is_array($user) ? trim((string) ($user['email'] ?? '')) : '';
        if ($email !== '') {
            try {
                ResendMailer::sendSubscriptionThankYou($email, $cycle);
            } catch (\Throwable) {
                // Payment succeeded even if the receipt email fails.
            }
        }

        if (is_array($user)) {
            AdminNotificationService::subscriptionPayment($user, $payment);
        }

        return $user;
    }

    public static function markFailedByTxRef(string $txRef, string $flwId = ''): void
    {
        if ($txRef === '') {
            return;
        }

        $now = gmdate('c');
        Database::pdo()->prepare(
            'UPDATE payments SET status = ?, flw_tx_id = COALESCE(NULLIF(?, \'\'), flw_tx_id), updated_at = ? WHERE tx_ref = ? AND status = ?'
        )->execute(['failed', $flwId, $now, $txRef, 'pending']);
    }

    private static function shouldExpire(array $user): bool
    {
        if (($user['plan'] ?? '') !== 'premium') {
            return false;
        }

        if (($user['subscription_status'] ?? '') !== 'active') {
            return false;
        }

        $expires = trim((string) ($user['subscription_expires_at'] ?? ''));
        if ($expires === '') {
            return false;
        }

        $expiresAt = strtotime($expires);

        return $expiresAt !== false && $expiresAt <= time();
    }

    private static function expireSubscription(int $userId): void
    {
        $now = gmdate('c');
        Database::pdo()->prepare(
            'UPDATE users SET plan = ?, subscription_status = ?, billing_cycle = NULL, subscription_expires_at = NULL, updated_at = ? WHERE id = ?'
        )->execute(['free', 'expired', $now, $userId]);
    }

    private static function computeExpiresAt(string $cycle, ?string $currentExpiresAt): string
    {
        $base = time();
        if ($currentExpiresAt !== null && $currentExpiresAt !== '') {
            $currentTs = strtotime($currentExpiresAt);
            if ($currentTs !== false && $currentTs > $base) {
                $base = $currentTs;
            }
        }

        $interval = strtolower(trim($cycle)) === 'yearly' ? '+1 year' : '+1 month';

        return gmdate('c', strtotime($interval, $base));
    }
}
