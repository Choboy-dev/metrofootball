<?php

declare(strict_types=1);

namespace App;

/**
 * Transactional email layout (Flutterwave-style: logo, white card, clean body).
 */
final class EmailTemplate
{
    /** PNG for broad email client support; must be metro-football-correct-dark-logo (no origynlabs). */
    private const LOGO_URL = 'https://metrofootball.co.uk/images/metro-football-correct-dark-logo.png?v=20260901email';

    private const LOGO_WIDTH = 200;
    private const LOGO_HEIGHT = 67;

    public static function render(string $innerHtml): string
    {
        $logo = htmlspecialchars(self::LOGO_URL, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<title>Metro Football</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 16px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="max-width:560px;background:#ffffff;border-radius:12px;padding:40px 32px;">'
            . '<tr><td align="center" style="padding-bottom:28px;">'
            . '<img src="' . $logo . '" alt="Metro Football" width="' . self::LOGO_WIDTH . '" height="' . self::LOGO_HEIGHT . '" '
            . 'style="display:block;height:auto;border:0;max-width:' . self::LOGO_WIDTH . 'px;width:' . self::LOGO_WIDTH . 'px;">'
            . '</td></tr>'
            . '<tr><td style="font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.6;color:#374151;">'
            . $innerHtml
            . '</td></tr>'
            . '<tr><td style="padding-top:32px;font-family:Inter,Arial,sans-serif;font-size:14px;line-height:1.5;color:#6b7280;">'
            . 'Best regards,<br>The Metro Football team'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';
    }

    public static function paragraph(string $text): string
    {
        return '<p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.6;color:#374151;">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    public static function code(string $code): string
    {
        return '<p style="margin:20px 0 12px;font-family:Inter,Arial,sans-serif;font-size:36px;font-weight:700;'
            . 'letter-spacing:0.18em;color:#dc2626;">'
            . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    public static function button(string $label, string $url): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        return '<p style="margin:24px 0 16px;">'
            . '<a href="' . $safeUrl . '" style="display:inline-block;padding:14px 28px;background:#dc2626;color:#ffffff;'
            . 'text-decoration:none;border-radius:999px;font-family:Inter,Arial,sans-serif;font-size:15px;font-weight:600;">'
            . $safeLabel
            . '</a></p>';
    }

    public static function linkFallback(string $url): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return '<p style="margin:0 0 8px;font-family:Inter,Arial,sans-serif;font-size:14px;line-height:1.5;color:#6b7280;">'
            . 'If the button does not work, copy and paste this link into your browser:</p>'
            . '<p style="margin:0;font-family:Inter,Arial,sans-serif;font-size:14px;line-height:1.5;word-break:break-all;">'
            . '<a href="' . $safeUrl . '" style="color:#dc2626;text-decoration:underline;">' . $safeUrl . '</a></p>';
    }

    public static function muted(string $text): string
    {
        return '<p style="margin:0;font-family:Inter,Arial,sans-serif;font-size:14px;line-height:1.5;color:#6b7280;">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }
}
