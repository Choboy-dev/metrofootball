<?php

declare(strict_types=1);

namespace App;

final class BillingService
{
    public const CURRENCY = 'GBP';

    /** @return array{cycle:string,amount:float,label:string,period:string} */
    public static function plan(string $cycle): array
    {
        $cycle = strtolower(trim($cycle)) === 'yearly' ? 'yearly' : 'monthly';

        if ($cycle === 'yearly') {
            return [
                'cycle' => 'yearly',
                'amount' => 22.0,
                'label' => '£22',
                'period' => '/ year',
                'display_amount' => '£2',
                'display_period' => '/ month',
                'note' => '£22/year billed yearly · 1 month free',
            ];
        }

        return [
            'cycle' => 'monthly',
            'amount' => 2.5,
            'label' => '£2.50',
            'period' => '/ month',
            'display_amount' => '£2.50',
            'display_period' => '/ month',
            'note' => 'Billed every month',
        ];
    }

    public static function createCheckout(array $user, string $cycle): string
    {
        if (!Flutterwave::isConfigured()) {
            throw new \RuntimeException('Flutterwave is not configured. Add FLW_SECRET_KEY in config/.env');
        }

        $plan = self::plan($cycle);
        $txRef = 'mf_' . (int) $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4));
        $now = gmdate('c');

        Database::pdo()->prepare(
            'INSERT INTO payments (user_id, tx_ref, amount, currency, billing_cycle, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $user['id'],
            $txRef,
            $plan['amount'],
            self::CURRENCY,
            $plan['cycle'],
            'pending',
            $now,
            $now,
        ]);

        // Mark intent as premium until payment succeeds or fails
        Database::pdo()->prepare(
            'UPDATE users SET plan = ?, updated_at = ? WHERE id = ?'
        )->execute(['premium', $now, (int) $user['id']]);

        $payment = Flutterwave::createPayment([
            'tx_ref' => $txRef,
            'amount' => number_format($plan['amount'], 2, '.', ''),
            'currency' => self::CURRENCY,
            'redirect_url' => AppUrl::to('api/billing/callback'),
            'payment_options' => 'card',
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
            ],
        ]);

        return $payment['link'];
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

        $expectedCurrency = self::CURRENCY;
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($currency !== $expectedCurrency) {
            throw new \RuntimeException('Payment currency did not match.');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE tx_ref = ? LIMIT 1');
        $stmt->execute([$txRef]);
        $payment = $stmt->fetch();

        if (!$payment) {
            throw new \RuntimeException('Payment record not found.');
        }

        $amountPaid = (float) ($data['amount'] ?? 0);
        if (abs($amountPaid - (float) $payment['amount']) > 0.009) {
            throw new \RuntimeException('Payment amount did not match.');
        }

        if (($payment['status'] ?? '') === 'successful') {
            return AuthService::findUserById((int) $payment['user_id']);
        }

        $now = gmdate('c');
        $flwId = (string) ($data['id'] ?? '');
        $cycle = (string) $payment['billing_cycle'];
        $expires = $cycle === 'yearly'
            ? gmdate('c', strtotime('+1 year'))
            : gmdate('c', strtotime('+1 month'));

        $pdo->prepare(
            'UPDATE payments SET status = ?, flw_tx_id = ?, paid_at = ?, updated_at = ? WHERE id = ?'
        )->execute(['successful', $flwId, $now, $now, (int) $payment['id']]);

        $pdo->prepare(
            'UPDATE users SET plan = ?, subscription_status = ?, billing_cycle = ?, subscription_expires_at = ?, updated_at = ? WHERE id = ?'
        )->execute(['premium', 'active', $cycle, $expires, $now, (int) $payment['user_id']]);

        return AuthService::findUserById((int) $payment['user_id']);
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
}
