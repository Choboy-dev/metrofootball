<?php

declare(strict_types=1);

namespace App;

final class SocialLinks
{
    /** @return array{facebook:string,x:string,instagram:string} */
    public static function urls(): array
    {
        return [
            'facebook' => self::normalize((string) Env::get('SOCIAL_FACEBOOK_URL', '')),
            'x' => self::normalize((string) Env::get('SOCIAL_X_URL', '')),
            'instagram' => self::normalize((string) Env::get('SOCIAL_INSTAGRAM_URL', '')),
        ];
    }

    private static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '#';
        }

        if (!preg_match('#^https?://#i', $url)) {
            return '#';
        }

        return $url;
    }
}
