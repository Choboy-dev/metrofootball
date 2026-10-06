<?php

declare(strict_types=1);

namespace App;

/**
 * Stable Premier League / Opta stats URLs keyed by FPL element id.
 *
 * Built once via scripts/build-opta-player-links.php from FPL bootstrap `code` values.
 */
final class OptaPlayerLinks
{
    private const STORAGE_KEY = 'opta-player-links';

    /** @var array<string, mixed>|null */
    private static ?array $index = null;

    public static function statsUrl(int $elementId, int $plCode = 0, string $firstName = '', string $secondName = ''): ?string
    {
        if ($elementId < 1) {
            return null;
        }

        $stored = self::lookup($elementId);
        if ($stored !== null) {
            return $stored;
        }

        if ($plCode < 1) {
            return null;
        }

        return self::buildUrl($plCode, $firstName, $secondName);
    }

    public static function plPlayerSlug(string $firstName, string $secondName): string
    {
        $name = trim($firstName . ' ' . $secondName);
        if ($name === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $slug = strtolower(is_string($ascii) && $ascii !== '' ? $ascii : $name);
        $slug = str_replace(["'", '’', '.'], '', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    public static function buildUrl(int $plCode, string $firstName = '', string $secondName = ''): ?string
    {
        if ($plCode < 1) {
            return null;
        }

        $slug = self::plPlayerSlug($firstName, $secondName);
        if ($slug !== '') {
            return 'https://www.premierleague.com/en/players/' . $plCode . '/' . $slug . '/stats';
        }

        return 'https://www.premierleague.com/en/players/' . $plCode . '/stats';
    }

    /**
     * @return array{version:int,updated_at:string,count:int,verified:int,missing:int,players:array<string, array<string, mixed>>}
     */
    public static function buildFromBootstrap(?callable $log = null): array
    {
        $bootstrap = FplClient::fetchBootstrapPick();
        $elements = is_array($bootstrap['elements'] ?? null) ? $bootstrap['elements'] : [];

        $players = [];
        $missing = 0;

        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }

            $elementId = (int) ($el['id'] ?? 0);
            $plCode = (int) ($el['code'] ?? 0);
            if ($elementId < 1) {
                continue;
            }

            $firstName = (string) ($el['first_name'] ?? '');
            $secondName = (string) ($el['second_name'] ?? '');
            $url = self::buildUrl($plCode, $firstName, $secondName);

            if ($url === null) {
                $missing++;
            }

            $players[(string) $elementId] = [
                'element_id' => $elementId,
                'code' => $plCode,
                'web_name' => (string) ($el['web_name'] ?? ''),
                'url' => $url,
            ];
        }

        ksort($players, SORT_NUMERIC);

        $payload = [
            'version' => 1,
            'updated_at' => gmdate('c'),
            'source' => 'fpl-bootstrap-code',
            'count' => count($players),
            'missing' => $missing,
            'players' => $players,
        ];

        self::save($payload);
        self::$index = null;

        if ($log !== null) {
            $log('Saved ' . count($players) . ' player Opta links (' . $missing . ' missing code).');
        }

        return $payload;
    }

    private static function lookup(int $elementId): ?string
    {
        $index = self::loadIndex();
        $row = $index['players'][(string) $elementId] ?? null;
        if (!is_array($row)) {
            return null;
        }

        $url = (string) ($row['url'] ?? '');
        return $url !== '' ? $url : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadIndex(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $path = self::storagePath();
        if (!is_file($path)) {
            self::$index = ['players' => []];
            return self::$index;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            self::$index = ['players' => []];
            return self::$index;
        }

        $decoded = json_decode($raw, true);
        self::$index = is_array($decoded) ? $decoded : ['players' => []];

        return self::$index;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function save(array $payload): void
    {
        $dir = dirname(self::storagePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            self::storagePath(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private static function storagePath(): string
    {
        return dirname(__DIR__) . '/storage/data/' . self::STORAGE_KEY . '.json';
    }
}
