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
        $subject = 'Your Metro Football code';
        $html = '<p style="font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.5;color:#14161c">'
            . 'Use this code to finish creating your Metro Football account.</p>'
            . '<p style="font-family:Google Sans,Inter,Arial,sans-serif;font-size:32px;font-weight:700;letter-spacing:0.2em;color:#0b1220">'
            . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="font-family:Inter,Arial,sans-serif;font-size:14px;color:#4b5563">This code expires in 10 minutes.</p>';
        $text = "Your Metro Football code is {$code}. It expires in 10 minutes.";

        self::send($to, $subject, $html, $text);
    }

    public static function sendConfirmEmail(string $to, string $confirmUrl): void
    {
        $safeUrl = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');
        $subject = 'Confirm your email address';
        $html = '<p style="font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.5;color:#14161c">'
            . 'Thanks for joining Metro Football. Please confirm your email address.</p>'
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 20px;background:#dc2626;color:#fff;'
            . 'text-decoration:none;border-radius:999px;font-family:Inter,Arial,sans-serif;font-weight:600">'
            . 'Confirm email</a></p>'
            . '<p style="font-family:Inter,Arial,sans-serif;font-size:14px;color:#4b5563">Or open this link:<br>'
            . $safeUrl . '</p>';
        $text = "Confirm your Metro Football email: {$confirmUrl}";

        self::send($to, $subject, $html, $text);
    }

    public static function sendContactAutoReply(string $to, string $name): void
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $subject = 'We received your message';
        $html = '<p style="font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.55;color:#14161c">'
            . 'Hi ' . $safeName . ',</p>'
            . '<p style="font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.55;color:#14161c">'
            . 'Thanks for contacting Metro Football. We read every message and reply by email when we need to follow up.</p>'
            . '<p style="font-family:Inter,Arial,sans-serif;font-size:14px;color:#4b5563">'
            . 'For account help, you can also visit your billing page or check our pricing page for plan details.</p>';
        $text = "Hi {$name},\n\nThanks for contacting Metro Football. We read every message and reply by email when we need to follow up.";

        self::send($to, $subject, $html, $text);
    }
}
