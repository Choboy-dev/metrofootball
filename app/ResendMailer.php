<?php

declare(strict_types=1);

namespace App;

final class ResendMailer
{
    public static function send(string $to, string $subject, string $html, string $text): void
    {
        $apiKey = Env::get('RESEND_API_KEY');
        $from = Env::get('RESEND_FROM', 'Metro Football <onboarding@metrofootball.co.uk>');

        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('Resend is not configured. Add RESEND_API_KEY in config/.env');
        }

        $payload = json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Could not reach Resend: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            $message = 'Resend request failed';
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['message'])) {
                $message = (string) $decoded['message'];
            }
            throw new \RuntimeException($message);
        }
    }

    public static function sendOtp(string $to, string $code): void
    {
        $subject = 'Your Metro Football verification code';
        $inner = EmailTemplate::paragraph('Thank you for creating a Metro Football account.')
            . EmailTemplate::paragraph('Use the code below to verify your email address and continue setting up your account.')
            . EmailTemplate::code($code)
            . EmailTemplate::muted('This code expires in 10 minutes.');
        $html = EmailTemplate::render($inner);
        $text = "Thank you for creating a Metro Football account.\n\nYour verification code is {$code}.\n\nThis code expires in 10 minutes.";

        self::send($to, $subject, $html, $text);
    }

    public static function sendConfirmEmail(string $to, string $confirmUrl): void
    {
        $subject = 'Confirm your Metro Football email';
        $inner = EmailTemplate::paragraph('Your account is almost ready.')
            . EmailTemplate::paragraph('Click the button below to confirm your email address. After that you can finish payment or start using your Free tools.')
            . EmailTemplate::button('Confirm email address', $confirmUrl)
            . EmailTemplate::linkFallback($confirmUrl)
            . EmailTemplate::muted('This link expires in 24 hours.');
        $html = EmailTemplate::render($inner);
        $text = "Confirm your Metro Football email: {$confirmUrl}\n\nThis link expires in 24 hours.";

        self::send($to, $subject, $html, $text);
    }

    public static function sendWelcome(string $to, string $plan, bool $emailAlreadyVerified = false): void
    {
        $isPremium = strtolower(trim($plan)) === 'premium';
        $subject = 'Welcome to Metro Football';
        $homeUrl = AppUrl::to('');

        if ($isPremium) {
            $lead = $emailAlreadyVerified
                ? 'Thanks for choosing Premium. Finish checkout to unlock personal team tools for your squad.'
                : 'Thanks for choosing Premium. Once your email is confirmed you can finish checkout and unlock personal team tools.';
            $ctaLabel = 'Continue to payment';
            $ctaUrl = AppUrl::to('payment');
        } else {
            $lead = 'Your Free account is ready. Explore weekly reports, player pages, and core FPL tools whenever you like.';
            $ctaLabel = 'Open Metro Football';
            $ctaUrl = AppUrl::to('live');
        }

        $inner = EmailTemplate::paragraph('Welcome to Metro Football.')
            . EmailTemplate::paragraph($lead)
            . EmailTemplate::button($ctaLabel, $ctaUrl)
            . EmailTemplate::linkFallback($ctaUrl);
        $html = EmailTemplate::render($inner);
        $text = "Welcome to Metro Football.\n\n{$lead}\n\n{$ctaUrl}";

        self::send($to, $subject, $html, $text);
    }

    public static function sendSubscriptionThankYou(string $to, string $cycle): void
    {
        $linkUrl = AppUrl::to('link-team');
        $liveUrl = AppUrl::to('live');
        $cycleLabel = strtolower(trim($cycle)) === 'yearly' ? 'yearly' : 'monthly';
        $subject = 'Thank you for subscribing to Metro Football Premium';
        $inner = EmailTemplate::paragraph('Thank you. We just received your Premium payment.')
            . EmailTemplate::paragraph('Your ' . $cycleLabel . ' subscription is active. Personal team tools are unlocked on your account.')
            . EmailTemplate::paragraph('Next, import your FPL squad so Metro Football can score your team, track live points, and run tools on your picks.')
            . EmailTemplate::button('Import your squad', $linkUrl)
            . EmailTemplate::linkFallback($linkUrl)
            . EmailTemplate::muted('Already linked your team? Open Live to see your squad.');
        $html = EmailTemplate::render($inner);
        $text = "Thank you. We just received your Premium payment.\n\n"
            . "Your {$cycleLabel} subscription is active.\n\n"
            . "Import your FPL squad: {$linkUrl}\n\n"
            . "Live dashboard: {$liveUrl}";

        self::send($to, $subject, $html, $text);
    }

    public static function sendContactAutoReply(string $to, string $name): void
    {
        $subject = 'We received your message';
        $inner = EmailTemplate::paragraph('Hi ' . $name . ',')
            . EmailTemplate::paragraph('Thanks for contacting Metro Football. We read every message and reply by email when we need to follow up.')
            . EmailTemplate::muted('For account help, visit our Help Center or check your billing page for plan details.');
        $html = EmailTemplate::render($inner);
        $text = "Hi {$name},\n\nThanks for contacting Metro Football. We read every message and reply by email when we need to follow up.";

        self::send($to, $subject, $html, $text);
    }

    public static function sendSubscriptionRenewalReminder(string $to, string $expiresLabel, int $daysLeft): void
    {
        $renewUrl = AppUrl::to('payment');
        $subject = $daysLeft <= 1
            ? 'Your Metro Football Premium ends tomorrow'
            : 'Your Metro Football Premium renews soon';
        $lead = $daysLeft <= 1
            ? 'Your Premium plan ends tomorrow (' . $expiresLabel . ').'
            : 'Your Premium plan ends on ' . $expiresLabel . ' (' . $daysLeft . ' days left).';
        $inner = EmailTemplate::paragraph($lead)
            . EmailTemplate::paragraph('Metro Football does not charge your card automatically. Visit the payment page to renew and keep your personal team tools unlocked.')
            . EmailTemplate::button('Renew Premium', $renewUrl);
        $html = EmailTemplate::render($inner);
        $text = $lead . ' Renew at ' . $renewUrl . ' to keep Premium active.';

        self::send($to, $subject, $html, $text);
    }

    public static function sendSubscriptionExpired(string $to): void
    {
        $renewUrl = AppUrl::to('payment');
        $subject = 'Your Metro Football Premium has ended';
        $inner = EmailTemplate::paragraph('Your Premium plan has ended and your account is back on Free.')
            . EmailTemplate::paragraph('Your account, linked FPL team, and free tools are still here. Renew any time to unlock personal team tools again.')
            . EmailTemplate::button('Renew Premium', $renewUrl);
        $html = EmailTemplate::render($inner);
        $text = 'Your Premium plan has ended. Renew at ' . $renewUrl . ' whenever you are ready.';

        self::send($to, $subject, $html, $text);
    }
}
