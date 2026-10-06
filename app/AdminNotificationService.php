<?php

declare(strict_types=1);

namespace App;

/**
 * Internal alerts to the Metro Football team (registrations, payments, etc.).
 */
final class AdminNotificationService
{
    public static function notifyEmail(): string
    {
        foreach (['ADMIN_NOTIFY_EMAIL', 'MAIL_ADMIN_EMAIL'] as $key) {
            $configured = Env::get($key);
            if (is_string($configured) && trim($configured) !== '') {
                return trim($configured);
            }
        }

        return 'hello@metrofootball.co.uk';
    }

    /** @param array<string, scalar|null> $user */
    public static function userRegistered(array $user, string $chosenPlan, string $method): void
    {
        $email = trim((string) ($user['email'] ?? ''));
        $name = trim((string) ($user['name'] ?? ''));
        $plan = AuthService::normalizePlan($chosenPlan);
        $methodLabel = $method === 'google' ? 'Google sign up' : 'Email sign up';

        self::send(
            'New user registered on Metro Football',
            'A new account was created.',
            [
                'Sign up method' => $methodLabel,
                'Email' => $email !== '' ? $email : null,
                'Name' => $name !== '' ? $name : null,
                'Plan at signup' => ucfirst($plan),
                'User ID' => (string) ($user['id'] ?? ''),
            ]
        );
    }

    /** @param array<string, scalar|null> $user */
    public static function emailConfirmed(array $user): void
    {
        $email = trim((string) ($user['email'] ?? ''));

        self::send(
            'User confirmed email on Metro Football',
            'A user confirmed their email address.',
            [
                'Email' => $email !== '' ? $email : null,
                'User ID' => (string) ($user['id'] ?? ''),
            ]
        );
    }

    /** @param array<string, scalar|null> $user @param array<string, scalar|null> $payment */
    public static function subscriptionPayment(array $user, array $payment): void
    {
        $email = trim((string) ($user['email'] ?? ''));
        $cycle = (string) ($payment['billing_cycle'] ?? '');
        $currency = strtoupper((string) ($payment['charge_currency'] ?? $payment['currency'] ?? 'GBP'));
        $amount = (float) ($payment['charge_amount'] ?? $payment['amount'] ?? 0);
        $amountLabel = $currency === 'NGN'
            ? '₦' . number_format($amount, 0, '.', ',')
            : '£' . number_format($amount, 2, '.', '');

        self::send(
            'New Premium payment on Metro Football',
            'A subscription payment was completed.',
            [
                'Email' => $email !== '' ? $email : null,
                'Amount' => $amountLabel,
                'Billing cycle' => $cycle !== '' ? ucfirst($cycle) : null,
                'Transaction ref' => (string) ($payment['tx_ref'] ?? ''),
                'User ID' => (string) ($user['id'] ?? ''),
            ]
        );
    }

    /** @param array<string, scalar|null> $user @param array<string, mixed> $team */
    public static function fplTeamLinked(array $user, array $team): void
    {
        $email = trim((string) ($user['email'] ?? ''));

        self::send(
            'User linked FPL team on Metro Football',
            'A user linked their Fantasy Premier League team.',
            [
                'Email' => $email !== '' ? $email : null,
                'FPL team' => (string) ($team['team_name'] ?? ''),
                'Manager' => (string) ($team['manager_name'] ?? ''),
                'FPL ID' => (string) ($team['id'] ?? ''),
                'User ID' => (string) ($user['id'] ?? ''),
            ]
        );
    }

    /** @param array<string, scalar|null> $rows */
    private static function send(string $subject, string $intro, array $rows): void
    {
        try {
            $inner = EmailTemplate::paragraph($intro) . self::details($rows);
            $html = EmailTemplate::render($inner);
            $text = $intro . "\n\n" . self::detailsText($rows);
            ResendMailer::send(self::notifyEmail(), $subject, $html, $text);
        } catch (\Throwable $e) {
            error_log('[admin-notify] ' . $e->getMessage());
        }
    }

    /** @param array<string, scalar|null> $rows */
    private static function details(array $rows): string
    {
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" '
            . 'style="width:100%;margin:8px 0 0;border-collapse:collapse;font-family:Inter,Arial,sans-serif;font-size:15px;line-height:1.5;color:#374151;">';

        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $html .= '<tr>'
                . '<td style="padding:8px 12px 8px 0;vertical-align:top;font-weight:600;white-space:nowrap;">'
                . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8')
                . '</td>'
                . '<td style="padding:8px 0;vertical-align:top;">'
                . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
                . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    /** @param array<string, scalar|null> $rows */
    private static function detailsText(array $rows): string
    {
        $lines = [];
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = $label . ': ' . $value;
        }

        return implode("\n", $lines);
    }
}
