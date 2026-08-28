<?php

declare(strict_types=1);

namespace App;

final class ContactService
{
    private const REASONS = [
        'general' => 'General question',
        'account' => 'Account and billing',
        'bug' => 'Bug or wrong data',
        'partnership' => 'Partnership',
        'press' => 'Press and media',
        'privacy' => 'Privacy or legal concern',
        'other' => 'Other',
    ];

    private const MIN_GAP_SECONDS = 60;

    /** @return array{label: string, value: string}[] */
    public static function reasons(): array
    {
        $items = [];
        foreach (self::REASONS as $value => $label) {
            $items[] = ['value' => $value, 'label' => $label];
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, email: string, reason: string, reason_label: string, message: string}
     */
    public static function validate(array $input): array
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            throw new \InvalidArgumentException('We could not send your message. Please try again.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));

        if ($name === '' || mb_strlen($name) < 2) {
            throw new \InvalidArgumentException('Please enter your name.');
        }
        if (mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Your name is too long.');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Please enter a valid email address.');
        }
        if (mb_strlen($email) > 190) {
            throw new \InvalidArgumentException('Your email address is too long.');
        }

        if ($reason === '' || !isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('Please choose a reason for your message.');
        }

        if ($message === '' || mb_strlen($message) < 12) {
            throw new \InvalidArgumentException('Please tell us a little more in your message.');
        }
        if (mb_strlen($message) > 4000) {
            throw new \InvalidArgumentException('Your message is too long. Please keep it under 4000 characters.');
        }

        self::assertRateLimit();

        return [
            'name' => $name,
            'email' => $email,
            'reason' => $reason,
            'reason_label' => self::REASONS[$reason],
            'message' => $message,
        ];
    }

    /** @param array{name: string, email: string, reason: string, reason_label: string, message: string} $payload */
    public static function send(array $payload): void
    {
        $to = self::inboxAddress();
        $safeName = htmlspecialchars($payload['name'], ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($payload['email'], ENT_QUOTES, 'UTF-8');
        $safeReason = htmlspecialchars($payload['reason_label'], ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($payload['message'], ENT_QUOTES, 'UTF-8'));

        $subject = 'Metro Football contact: ' . $payload['reason_label'];
        $html = '<div style="font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.55;color:#14161c">'
            . '<p style="margin:0 0 12px"><strong>Reason:</strong> ' . $safeReason . '</p>'
            . '<p style="margin:0 0 12px"><strong>Name:</strong> ' . $safeName . '</p>'
            . '<p style="margin:0 0 12px"><strong>Email:</strong> ' . $safeEmail . '</p>'
            . '<p style="margin:0 0 8px"><strong>Message</strong></p>'
            . '<p style="margin:0;padding:14px 16px;border-radius:12px;background:#f4f6f8">' . $safeMessage . '</p>'
            . '</div>';
        $text = "Reason: {$payload['reason_label']}\n"
            . "Name: {$payload['name']}\n"
            . "Email: {$payload['email']}\n\n"
            . "Message:\n{$payload['message']}";

        ResendMailer::send($to, $subject, $html, $text);
        ResendMailer::sendContactAutoReply($payload['email'], $payload['name']);

        $_SESSION['mf_contact_last_sent'] = time();
    }

    private static function inboxAddress(): string
    {
        $configured = Env::get('CONTACT_TO');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $from = Env::get('RESEND_FROM', 'Metro Football <hello@metrofootball.co.uk>');
        if (preg_match('/<([^>]+)>/', (string) $from, $matches)) {
            return $matches[1];
        }

        return 'hello@metrofootball.co.uk';
    }

    private static function assertRateLimit(): void
    {
        $last = (int) ($_SESSION['mf_contact_last_sent'] ?? 0);
        if ($last > 0 && (time() - $last) < self::MIN_GAP_SECONDS) {
            throw new \InvalidArgumentException('Please wait a moment before sending another message.');
        }
    }
}
