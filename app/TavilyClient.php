<?php

declare(strict_types=1);

namespace App;

/**
 * Tavily web research client for Injury Risk Digest.
 * Results are filtered to agreed Metro Football source tiers.
 */
final class TavilyClient
{
    private const SEARCH_URL = 'https://api.tavily.com/search';

    /** Tier 1: Premier League + club official sites. */
    public const TIER1_DOMAINS = [
        'premierleague.com',
        'arsenal.com',
        'avfc.co.uk',
        'afcb.co.uk',
        'brentfordfc.com',
        'brightonandhovealbion.com',
        'chelseafc.com',
        'cpfc.co.uk',
        'evertonfc.com',
        'fulhamfc.com',
        'liverpoolfc.com',
        'mancity.com',
        'manutd.com',
        'nufc.co.uk',
        'nottinghamforest.co.uk',
        'tottenhamhotspur.com',
        'whufc.com',
        'wolves.co.uk',
        'lcfc.com',
        'sufc.co.uk',
        'ipswichtown.com',
        'southamptonfc.com',
    ];

    /** Tier 2: Trusted sports desks and wires. */
    public const TIER2_DOMAINS = [
        'bbc.co.uk',
        'bbc.com',
        'skysports.com',
        'theguardian.com',
        'reuters.com',
        'apnews.com',
        'standard.co.uk',
        'athletic.com',
        'football.london',
        'manchestereveningnews.co.uk',
        'liverpoolecho.co.uk',
        'chroniclelive.co.uk',
        'birminghammail.co.uk',
        'eveningstandard.co.uk',
    ];

    /** Club official social (snippets only, never rumour accounts). */
    public const SOCIAL_DOMAINS = [
        'x.com',
        'twitter.com',
    ];

    /** Career / match stats desks used by guide Ask AI research. */
    public const STATS_DOMAINS = [
        'transfermarkt.com',
        'transfermarkt.co.uk',
        'fbref.com',
        'soccerbase.com',
    ];

    /** @var array<string, string> FPL short code → club domain */
    public const CLUB_DOMAIN_BY_SHORT = [
        'ARS' => 'arsenal.com',
        'AVL' => 'avfc.co.uk',
        'BOU' => 'afcb.co.uk',
        'BRE' => 'brentfordfc.com',
        'BHA' => 'brightonandhovealbion.com',
        'CHE' => 'chelseafc.com',
        'CRY' => 'cpfc.co.uk',
        'EVE' => 'evertonfc.com',
        'FUL' => 'fulhamfc.com',
        'LIV' => 'liverpoolfc.com',
        'MCI' => 'mancity.com',
        'MUN' => 'manutd.com',
        'NEW' => 'nufc.co.uk',
        'NFO' => 'nottinghamforest.co.uk',
        'TOT' => 'tottenhamhotspur.com',
        'WHU' => 'whufc.com',
        'WOL' => 'wolves.co.uk',
        'LEI' => 'lcfc.com',
        'SHU' => 'sufc.co.uk',
        'IPS' => 'ipswichtown.com',
        'SOU' => 'southamptonfc.com',
    ];

    public static function configured(): bool
    {
        $key = Env::get('TAVILY_API_KEY');
        return is_string($key) && $key !== '';
    }

    /**
     * @param list<string>|null $includeDomains
     * @return list<array{title:string,url:string,content:string,score:float,tier:string,outlet:string}>
     */
    public static function search(
        string $query,
        int $maxResults = 6,
        string $searchDepth = 'advanced',
        ?array $includeDomains = null,
        array $meta = []
    ): array {
        if (!self::configured()) {
            throw new \RuntimeException('Tavily is not configured. Add TAVILY_API_KEY in config/.env');
        }

        $depth = $searchDepth === 'basic' ? 'basic' : 'advanced';
        $payload = [
            'api_key' => Env::require('TAVILY_API_KEY'),
            'query' => $query,
            'search_depth' => $depth,
            'include_answer' => false,
            'include_raw_content' => false,
            'max_results' => max(3, min(10, $maxResults + 2)),
        ];
        if ($includeDomains !== null && $includeDomains !== []) {
            $payload['include_domains'] = array_values(array_unique($includeDomains));
        }

        $ch = curl_init(self::SEARCH_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 50,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Could not reach Tavily: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Tavily returned invalid JSON.');
        }
        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['detail']['error'] ?? $decoded['error'] ?? 'Tavily request failed.');
            if (is_array($decoded['detail'] ?? null)) {
                $message = json_encode($decoded['detail']);
            }
            throw new \RuntimeException(is_string($message) ? $message : 'Tavily request failed.');
        }

        $out = [];
        foreach (($decoded['results'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            if ($url === '' || !self::urlAllowed($url)) {
                continue;
            }
            $out[] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'url' => $url,
                'content' => trim((string) ($row['content'] ?? '')),
                'score' => (float) ($row['score'] ?? 0),
                'tier' => self::tierForUrl($url),
                'outlet' => self::outletFromUrl($url),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        AiUsageLogger::logTavily(
            (string) ($meta['service'] ?? 'tavily'),
            isset($meta['user_id']) ? (int) $meta['user_id'] : null,
            ['query' => mb_substr($query, 0, 120)]
        );

        return array_slice($out, 0, $maxResults);
    }

    public static function clubDomainForShort(string $teamShort): ?string
    {
        $key = strtoupper(trim($teamShort));
        return self::CLUB_DOMAIN_BY_SHORT[$key] ?? null;
    }

    public static function tierForUrl(string $url): string
    {
        $host = self::host($url);
        if (self::hostInList($host, self::TIER1_DOMAINS)) {
            return 'tier1_official';
        }
        if (self::hostInList($host, self::TIER2_DOMAINS)) {
            return 'tier2_desk';
        }
        if (self::hostInList($host, self::STATS_DOMAINS)) {
            return 'stats_desk';
        }
        if (self::hostInList($host, self::SOCIAL_DOMAINS)) {
            return 'social_official';
        }
        return 'other';
    }

    public static function outletFromUrl(string $url): string
    {
        return self::host($url);
    }

    /** @return list<string> */
    public static function allAllowedDomains(): array
    {
        return array_values(array_unique(array_merge(
            self::TIER1_DOMAINS,
            self::TIER2_DOMAINS,
            self::SOCIAL_DOMAINS,
            self::STATS_DOMAINS
        )));
    }

    private static function urlAllowed(string $url): bool
    {
        $host = self::host($url);
        return self::hostInList($host, self::allAllowedDomains());
    }

    private static function host(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /** @param list<string> $domains */
    private static function hostInList(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }
}
