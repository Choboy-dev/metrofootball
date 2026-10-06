<?php

declare(strict_types=1);

namespace App;

/**
 * Turns admin Ask AI prompts (and optional screenshots / text files) into guide blocks.
 * Optional Tavily research is limited to Metro Football verified source domains.
 */
final class GuideAiAssistService
{
    private const MAX_IMAGES = 6;
    private const MAX_TEXT_FILES = 4;
    private const MAX_IMAGE_BYTES = 4_500_000;
    private const MAX_TEXT_CHARS = 40_000;
    private const MAX_QUERIES = 8;
    private const MAX_RESULTS_PER_QUERY = 5;

    /**
     * @param list<array{mime?:string,data?:string,name?:string}> $images base64 payloads
     * @param list<array{name?:string,text?:string}> $texts
     * @return array{
     *   blocks: list<array<string, mixed>>,
     *   research: array{enabled: bool, queries: list<string>, sources: list<array<string, mixed>>, tavily_calls: int}
     * }
     */
    public static function generate(
        string $instruction,
        array $images = [],
        array $texts = [],
        bool $research = false,
        ?int $userId = null
    ): array {
        if (!OpenAiClient::configured()) {
            throw new \RuntimeException('OpenAI is not configured. Add OPENAI_API_KEY in config/.env');
        }

        $instruction = trim($instruction);
        if ($instruction === '') {
            throw new \RuntimeException('Please write an instruction for Ask AI.');
        }

        $imageParts = self::normalizeImages($images);
        $textParts = self::normalizeTexts($texts);

        if ($imageParts === [] && $textParts === [] && !$research && mb_strlen($instruction) < 8) {
            throw new \RuntimeException('Add a clearer instruction, attach a file, or turn on web research.');
        }

        $researchPack = [
            'enabled' => false,
            'queries' => [],
            'sources' => [],
            'tavily_calls' => 0,
        ];

        // Screenshots + research: extract exact table first, then research missing cells.
        if ($research && $imageParts !== []) {
            if (!TavilyClient::configured()) {
                throw new \RuntimeException('Web research needs TAVILY_API_KEY in config/.env');
            }

            $extracted = self::requestBlocks(
                $instruction . "\n\nFirst pass: rebuild the table exactly from the screenshots. Leave genuinely blank/missing cells as empty strings. Do not invent zeros.",
                $imageParts,
                $textParts,
                [
                    'enabled' => false,
                    'queries' => [],
                    'sources' => [],
                    'tavily_calls' => 0,
                ],
                $userId,
                false
            );

            $gapContext = self::tableGapContext($extracted);
            $researchPack = self::runResearch(
                $instruction,
                $userId,
                $gapContext
            );
            if ($researchPack['sources'] === []) {
                throw new \RuntimeException(
                    'No verified sources found to fill the missing stats. Try again, or attach clearer screenshots.'
                );
            }

            $fillInstruction = $instruction . <<<'TXT'


Second pass rules:
1. Start from the extracted table JSON below.
2. Keep every value that is already filled exactly as extracted.
3. Fill only empty or clearly missing cells using the verified research snippets.
4. Never invent a zero. Zero is only allowed when a verified source explicitly says zero.
5. There should be no blank numeric cells when research provides the number.
6. Prefer Premier League / club / Transfermarkt / FBref figures when present.


Extracted table JSON:
TXT . json_encode(['blocks' => $extracted], JSON_UNESCAPED_UNICODE);

            $blocks = self::requestBlocks(
                $fillInstruction,
                [],
                $textParts,
                $researchPack,
                $userId,
                true
            );

            return [
                'blocks' => $blocks,
                'research' => $researchPack,
            ];
        }

        if ($research) {
            if (!TavilyClient::configured()) {
                throw new \RuntimeException('Web research needs TAVILY_API_KEY in config/.env');
            }
            $researchPack = self::runResearch($instruction, $userId);
            if ($researchPack['sources'] === []) {
                throw new \RuntimeException(
                    'No verified sources found for this request. Try a clearer instruction, or attach a screenshot.'
                );
            }
        }

        $blocks = self::requestBlocks(
            $instruction,
            $imageParts,
            $textParts,
            $researchPack,
            $userId,
            $researchPack['enabled']
        );

        return [
            'blocks' => $blocks,
            'research' => $researchPack,
        ];
    }

    /**
     * @param list<array{mime:string,data:string,name:string}> $imageParts
     * @param list<array{name:string,text:string}> $textParts
     * @param array{enabled: bool, queries: list<string>, sources: list<array<string, mixed>>, tavily_calls: int} $researchPack
     * @return list<array<string, mixed>>
     */
    private static function requestBlocks(
        string $instruction,
        array $imageParts,
        array $textParts,
        array $researchPack,
        ?int $userId,
        bool $researchEnabled
    ): array {
        $userContent = [];
        $userContent[] = [
            'type' => 'text',
            'text' => self::userPrompt($instruction, $textParts, count($imageParts), $researchPack),
        ];
        foreach ($imageParts as $img) {
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $img['mime'] . ';base64,' . $img['data'],
                ],
            ];
        }

        $parsed = OpenAiClient::chatJson(
            [
                ['role' => 'system', 'content' => self::systemPrompt($researchEnabled)],
                ['role' => 'user', 'content' => $userContent],
            ],
            'metro_guide_ask_ai',
            self::schema(),
            150,
            [
                'service' => 'guide_ask_ai',
                'user_id' => $userId,
                'temperature' => 0.05,
                'image_count' => count($imageParts),
                'text_file_count' => count($textParts),
                'research' => $researchEnabled,
                'tavily_calls' => $researchPack['tavily_calls'] ?? 0,
            ]
        );

        $rawBlocks = $parsed['blocks'] ?? null;
        if (!is_array($rawBlocks) || $rawBlocks === []) {
            throw new \RuntimeException('Ask AI did not return any blocks. Try again with a clearer request.');
        }

        $blocks = [];
        foreach ($rawBlocks as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $block = self::normalizeBlock($raw);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        if ($blocks === []) {
            throw new \RuntimeException('Ask AI returned blocks we could not use. Try again.');
        }

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private static function tableGapContext(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'table' || !is_array($block['rows'] ?? null)) {
                continue;
            }
            $rows = $block['rows'];
            $header = [];
            $start = 0;
            if (($block['header'] ?? true) !== false && isset($rows[0]) && is_array($rows[0])) {
                $header = array_map(static fn ($c) => trim((string) $c), $rows[0]);
                $start = 1;
            }
            for ($r = $start, $n = count($rows); $r < $n; $r++) {
                $row = $rows[$r];
                if (!is_array($row)) {
                    continue;
                }
                $player = trim((string) ($row[1] ?? $row[0] ?? ''));
                if ($player === '') {
                    continue;
                }
                $missing = [];
                foreach ($row as $c => $cell) {
                    $value = trim((string) $cell);
                    $label = $header[$c] ?? ('column ' . ($c + 1));
                    if ($value === '' || strcasecmp($value, 'n/a') === 0 || $value === '-' || $value === '—') {
                        $missing[] = $label;
                    }
                }
                if ($missing !== []) {
                    $lines[] = $player . ' missing: ' . implode(', ', $missing);
                } else {
                    $lines[] = $player . ' (confirm Premier League stats)';
                }
            }
        }

        return $lines !== []
            ? "Players and missing fields from the extracted table:\n" . implode("\n", $lines)
            : '';
    }

    /**
     * @deprecated Use generate()
     * @param list<array{mime?:string,data?:string,name?:string}> $images
     * @param list<array{name?:string,text?:string}> $texts
     * @return list<array<string, mixed>>
     */
    public static function generateBlocks(
        string $instruction,
        array $images = [],
        array $texts = [],
        ?int $userId = null
    ): array {
        $result = self::generate($instruction, $images, $texts, false, $userId);
        return $result['blocks'];
    }

    /**
     * @return array{enabled: bool, queries: list<string>, sources: list<array<string, mixed>>, tavily_calls: int}
     */
    private static function runResearch(string $instruction, ?int $userId, string $gapContext = ''): array
    {
        $queries = self::planSearchQueries($instruction, $userId, $gapContext);
        $domains = array_values(array_unique(array_merge(
            TavilyClient::TIER1_DOMAINS,
            TavilyClient::TIER2_DOMAINS,
            TavilyClient::STATS_DOMAINS
        )));

        $sources = [];
        $seenUrls = [];
        $calls = 0;

        foreach ($queries as $query) {
            $rows = TavilyClient::search(
                $query,
                self::MAX_RESULTS_PER_QUERY,
                'advanced',
                $domains,
                [
                    'service' => 'guide_ask_ai_research',
                    'user_id' => $userId,
                ]
            );
            $calls += 1;
            foreach ($rows as $row) {
                $url = (string) ($row['url'] ?? '');
                if ($url === '' || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;
                $sources[] = [
                    'title' => (string) ($row['title'] ?? ''),
                    'url' => $url,
                    'content' => mb_substr((string) ($row['content'] ?? ''), 0, 1200),
                    'tier' => (string) ($row['tier'] ?? ''),
                    'outlet' => (string) ($row['outlet'] ?? ''),
                    'score' => (float) ($row['score'] ?? 0),
                    'query' => $query,
                ];
            }
        }

        usort($sources, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return [
            'enabled' => true,
            'queries' => $queries,
            'sources' => array_slice($sources, 0, 24),
            'tavily_calls' => $calls,
        ];
    }

    /**
     * @return list<string>
     */
    private static function planSearchQueries(string $instruction, ?int $userId, string $gapContext = ''): array
    {
        $fallback = [mb_substr($instruction, 0, 180)];

        try {
            $user = 'Editor instruction:\n' . $instruction;
            if ($gapContext !== '') {
                $user .= "\n\n" . $gapContext . "\n\nBuild one search query per player that is missing stats. Include the metric names in the query.";
            }
            $planned = OpenAiClient::chatJson(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'TXT'
You plan short web search queries for Metro Football guide research.
Focus on Premier League career stats from official and trusted sources.
When players have missing stats, return one query per player naming the player and metrics such as appearances, clean sheets, titles, goals.
Return 1 to 8 focused English search queries.
Do not include site: operators.
Keep each query under 120 characters.
TXT,
                    ],
                    [
                        'role' => 'user',
                        'content' => $user,
                    ],
                ],
                'metro_guide_ask_ai_queries',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'queries' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_QUERIES,
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['queries'],
                ],
                45,
                [
                    'service' => 'guide_ask_ai_query_plan',
                    'user_id' => $userId,
                    'temperature' => 0.2,
                ]
            );
        } catch (\Throwable) {
            return $fallback;
        }

        $out = [];
        foreach (($planned['queries'] ?? []) as $query) {
            $q = trim((string) $query);
            if ($q === '') {
                continue;
            }
            $out[] = mb_substr($q, 0, 160);
            if (count($out) >= self::MAX_QUERIES) {
                break;
            }
        }

        return $out !== [] ? $out : $fallback;
    }

    private static function systemPrompt(bool $researchEnabled): string
    {
        $base = <<<'TXT'
You help Metro Football editors build guide article blocks from instructions and attachments.

Return JSON blocks only. Supported types: paragraph, heading, quote, table, callout, separator.

When images show tables or lists of data:
- Rebuild the table with the exact cell values shown.
- Keep every row and column. Do not invent players, ranks, years, or stats.
- Preserve spelling, punctuation, en-dashes, and numbers exactly as written.
- First row is usually the header. Set header true when the first row is column labels.
- stickyCols should be 0 unless the instruction asks for sticky columns.
- Put each cell value in rows as plain text strings. No HTML.
- Blank cells in a screenshot mean the value is missing. Use an empty string for those cells.
- Never invent a zero for a blank cell. Zero is only valid when the screenshot or a verified source shows zero.

Voice for any prose you write: We or Metro Football. Never lone first person I.
Copy: clear enough for a 10 year old. No exclamation marks.
Do not add marketing filler. Prefer exact extraction over rewriting.

If multiple screenshots are parts of one table, merge them into one table in order.
If the instruction asks for headings or paragraphs around a table, include those too.
Unused fields must still be present: text "", level 2, title "", cite "", header true, stickyCols 0, rows [].
TXT;

        if (!$researchEnabled) {
            return $base;
        }

        return $base . <<<'TXT'


Web research mode is on. Verified source snippets are supplied in the user message.
Rules for research:
- Use only facts supported by the supplied research snippets.
- Prefer Premier League and club official sources, then Transfermarkt, FBref, BBC, Sky Sports, The Guardian, Reuters, AP, The Athletic.
- Never invent stats, rankings, dates, or quotes.
- Never invent zeros for missing clean sheets, goals, titles, or appearances.
- If a screenshot cell is blank, fill it only from research. If research does not contain the number, leave it empty rather than guessing.
- Famous career defenders almost never have zero Premier League clean sheets. Treat a zero as wrong unless a source explicitly states zero.
- If sources conflict, prefer tier1 official sources, then say the conflict briefly in a short paragraph or callout.
- When building tables from research, use exact numbers and spellings from the snippets.
- If the editor asked for sources, add a callout titled Sources with outlet names and URLs from the research pack.
- Do not invent URLs. Only use URLs present in the research pack.
TXT;
    }

    /**
     * @param list<array{name:string,text:string}> $textParts
     * @param array{enabled: bool, queries: list<string>, sources: list<array<string, mixed>>, tavily_calls: int} $researchPack
     */
    private static function userPrompt(
        string $instruction,
        array $textParts,
        int $imageCount,
        array $researchPack
    ): string {
        $parts = [
            'Editor instruction:',
            $instruction,
            '',
            'Attachment summary: ' . $imageCount . ' image(s), ' . count($textParts) . ' text file(s).',
            'Follow the instruction exactly.',
        ];

        if ($imageCount > 0 || $textParts !== []) {
            $parts[] = 'Prefer exact data from attachments when they are present.';
        }

        foreach ($textParts as $i => $file) {
            $parts[] = '';
            $parts[] = 'Text file ' . ($i + 1) . ' (' . $file['name'] . '):';
            $parts[] = $file['text'];
        }

        if (!empty($researchPack['enabled'])) {
            $parts[] = '';
            $parts[] = 'Verified web research (use only these snippets for web facts):';
            $parts[] = 'Search queries: ' . implode(' | ', $researchPack['queries'] ?? []);
            foreach (($researchPack['sources'] ?? []) as $i => $source) {
                if (!is_array($source)) {
                    continue;
                }
                $parts[] = '';
                $parts[] = 'Source ' . ($i + 1)
                    . ' [' . (string) ($source['tier'] ?? '') . '] '
                    . (string) ($source['outlet'] ?? '')
                    . ' | ' . (string) ($source['title'] ?? '');
                $parts[] = 'URL: ' . (string) ($source['url'] ?? '');
                $parts[] = 'Snippet: ' . (string) ($source['content'] ?? '');
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $block = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['paragraph', 'heading', 'quote', 'table', 'callout', 'separator'],
                ],
                'text' => ['type' => 'string'],
                'level' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'cite' => ['type' => 'string'],
                'header' => ['type' => 'boolean'],
                'stickyCols' => ['type' => 'integer'],
                'rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['type', 'text', 'level', 'title', 'cite', 'header', 'stickyCols', 'rows'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'blocks' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => $block,
                ],
            ],
            'required' => ['blocks'],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private static function normalizeBlock(array $raw): ?array
    {
        $type = (string) ($raw['type'] ?? '');
        $id = 'b' . bin2hex(random_bytes(4));

        switch ($type) {
            case 'paragraph':
                $text = trim((string) ($raw['text'] ?? ''));
                return $text === '' ? null : ['id' => $id, 'type' => 'paragraph', 'text' => $text];

            case 'heading':
                $text = trim((string) ($raw['text'] ?? ''));
                if ($text === '') {
                    return null;
                }
                $level = max(1, min(6, (int) ($raw['level'] ?? 2)));
                return ['id' => $id, 'type' => 'heading', 'level' => $level, 'text' => $text];

            case 'quote':
                $text = trim((string) ($raw['text'] ?? ''));
                if ($text === '') {
                    return null;
                }
                return [
                    'id' => $id,
                    'type' => 'quote',
                    'text' => $text,
                    'cite' => trim((string) ($raw['cite'] ?? '')),
                ];

            case 'callout':
                $text = trim((string) ($raw['text'] ?? ''));
                $title = trim((string) ($raw['title'] ?? ''));
                if ($text === '' && $title === '') {
                    return null;
                }
                return [
                    'id' => $id,
                    'type' => 'callout',
                    'title' => $title,
                    'text' => $text,
                ];

            case 'separator':
                return ['id' => $id, 'type' => 'separator'];

            case 'table':
                $rowsIn = $raw['rows'] ?? null;
                if (!is_array($rowsIn) || $rowsIn === []) {
                    return null;
                }
                $rows = [];
                $colCount = 1;
                foreach ($rowsIn as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $cells = [];
                    foreach ($row as $cell) {
                        $cells[] = trim((string) $cell);
                    }
                    if ($cells === []) {
                        continue;
                    }
                    $colCount = max($colCount, count($cells));
                    $rows[] = $cells;
                }
                if ($rows === []) {
                    return null;
                }
                foreach ($rows as &$row) {
                    while (count($row) < $colCount) {
                        $row[] = '';
                    }
                    if (count($row) > $colCount) {
                        $row = array_slice($row, 0, $colCount);
                    }
                }
                unset($row);

                $sticky = max(0, min(6, (int) ($raw['stickyCols'] ?? 0)));
                if ($sticky >= $colCount) {
                    $sticky = max(0, $colCount - 1);
                }

                return [
                    'id' => $id,
                    'type' => 'table',
                    'header' => ($raw['header'] ?? true) !== false,
                    'stickyCols' => $sticky,
                    'colWidths' => array_fill(0, $colCount, 0),
                    'rowHeights' => array_fill(0, count($rows), 0),
                    'rows' => $rows,
                ];

            default:
                return null;
        }
    }

    /**
     * @param list<array{mime?:string,data?:string,name?:string}> $images
     * @return list<array{mime:string,data:string,name:string}>
     */
    private static function normalizeImages(array $images): array
    {
        $allowed = [
            'image/jpeg' => true,
            'image/jpg' => true,
            'image/png' => true,
            'image/webp' => true,
            'image/gif' => true,
        ];
        $out = [];
        foreach ($images as $image) {
            if (count($out) >= self::MAX_IMAGES) {
                break;
            }
            if (!is_array($image)) {
                continue;
            }
            $mime = strtolower(trim((string) ($image['mime'] ?? '')));
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }
            $data = preg_replace('/\s+/', '', (string) ($image['data'] ?? '')) ?? '';
            if ($data === '' || !isset($allowed[$mime])) {
                continue;
            }
            if (str_starts_with($data, 'data:')) {
                if (!preg_match('#^data:([^;]+);base64,(.+)$#', $data, $m)) {
                    continue;
                }
                $mime = strtolower($m[1]);
                if ($mime === 'image/jpg') {
                    $mime = 'image/jpeg';
                }
                $data = $m[2];
            }
            $bin = base64_decode($data, true);
            if ($bin === false || strlen($bin) < 32 || strlen($bin) > self::MAX_IMAGE_BYTES) {
                continue;
            }
            if (!isset($allowed[$mime])) {
                continue;
            }
            $out[] = [
                'mime' => $mime,
                'data' => base64_encode($bin),
                'name' => trim((string) ($image['name'] ?? 'image')) ?: 'image',
            ];
        }

        return $out;
    }

    /**
     * @param list<array{name?:string,text?:string}> $texts
     * @return list<array{name:string,text:string}>
     */
    private static function normalizeTexts(array $texts): array
    {
        $out = [];
        foreach ($texts as $file) {
            if (count($out) >= self::MAX_TEXT_FILES) {
                break;
            }
            if (!is_array($file)) {
                continue;
            }
            $text = trim((string) ($file['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
                $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
            }
            $out[] = [
                'name' => trim((string) ($file['name'] ?? 'notes.txt')) ?: 'notes.txt',
                'text' => $text,
            ];
        }

        return $out;
    }
}
