<?php

declare(strict_types=1);

namespace App;

/**
 * Default public author for FPL Guides and related articles.
 * Used for bylines, author pages, and Article JSON-LD.
 */
final class GuideAuthor
{
    public const SLUG = 'chosen-esiwe';

    /**
     * @return array{
     *   slug:string,
     *   name:string,
     *   role:string,
     *   email:string,
     *   bio:string,
     *   image:string,
     *   image_abs:string,
     *   url:string,
     *   instagram:string,
     *   same_as:list<string>
     * }
     */
    public static function default(): array
    {
        $image = '/images/authors/chosen-esiwe.jpg';

        return [
            'slug' => self::SLUG,
            'name' => 'Chosen Esiwe',
            'role' => 'Founder',
            'email' => 'chosen@metrofootball.co.uk',
            'bio' => 'Chosen writes clear FPL guides for Metro Football so managers can make better weekly decisions.',
            'image' => $image,
            'image_abs' => 'https://metrofootball.co.uk' . $image,
            'url' => '/author/' . self::SLUG,
            'instagram' => 'https://www.instagram.com/chosenesiwe/',
            'same_as' => [
                'https://www.instagram.com/chosenesiwe/',
                'mailto:chosen@metrofootball.co.uk',
            ],
        ];
    }

    public static function bySlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug !== self::SLUG) {
            return null;
        }

        return self::default();
    }

    /**
     * @return array<string, mixed>
     */
    public static function schemaPerson(): array
    {
        $author = self::default();

        return [
            '@type' => 'Person',
            'name' => $author['name'],
            'url' => 'https://metrofootball.co.uk' . $author['url'],
            'image' => $author['image_abs'],
            'jobTitle' => $author['role'],
            'email' => $author['email'],
            'sameAs' => $author['same_as'],
            'worksFor' => [
                '@type' => 'Organization',
                'name' => 'Metro Football',
                'url' => 'https://metrofootball.co.uk',
            ],
        ];
    }
}
