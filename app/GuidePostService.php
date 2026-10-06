<?php

declare(strict_types=1);

namespace App;

use App\FplGuides\ArticleLoader;
use App\FplGuides\GuideBlockRenderer;
use PDO;

/**
 * Database-backed FPL guide posts authored in admin.
 */
final class GuidePostService
{
    public const SCHEMA_TYPES = [
        'Article',
        'BlogPosting',
        'NewsArticle',
        'HowTo',
        'FAQPage',
    ];

    public const CATEGORIES = [
        'Basics',
        'Strategy',
        'Chips',
        'Transfers',
        'Economy',
        'Calendar',
    ];

    public static function ensureSchema(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS guide_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT \'\',
                category TEXT NOT NULL DEFAULT \'Basics\',
                schema_type TEXT NOT NULL DEFAULT \'Article\',
                featured_image TEXT NULL,
                body_blocks_json TEXT NOT NULL DEFAULT \'[]\',
                body_html TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'draft\',
                author_user_id INTEGER NULL,
                published_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_guide_posts_status_published ON guide_posts(status, published_at DESC)'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listPublished(): array
    {
        self::ensureSchema();
        $stmt = Database::pdo()->query(
            "SELECT * FROM guide_posts WHERE status = 'published' ORDER BY datetime(published_at) DESC, id DESC"
        );

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $rows[] = self::mapRow($row);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getPublishedBySlug(string $slug): ?array
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM guide_posts WHERE slug = ? AND status = 'published' LIMIT 1"
        );
        $stmt->execute([self::normalizeSlug($slug)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::mapRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function adminList(): array
    {
        self::ensureSchema();
        $stmt = Database::pdo()->query(
            'SELECT * FROM guide_posts ORDER BY datetime(updated_at) DESC, id DESC'
        );

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $rows[] = self::mapRow($row);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getById(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare('SELECT * FROM guide_posts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::mapRow($row) : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function save(array $input, ?int $authorUserId = null): array
    {
        self::ensureSchema();

        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required.');
        }

        $slug = self::normalizeSlug((string) ($input['slug'] ?? $title));
        if ($slug === '') {
            throw new \InvalidArgumentException('URL slug is required.');
        }

        if (self::slugTaken($slug, $id)) {
            throw new \InvalidArgumentException('This URL slug is already in use.');
        }

        $description = trim((string) ($input['description'] ?? ''));
        $category = self::normalizeCategory((string) ($input['category'] ?? 'Basics'));
        $schemaType = self::normalizeSchemaType((string) ($input['schema_type'] ?? 'Article'));
        $featuredImage = trim((string) ($input['featured_image'] ?? ''));
        $status = strtolower(trim((string) ($input['status'] ?? 'draft'))) === 'published' ? 'published' : 'draft';
        $blocks = self::normalizeBlocks($input['blocks'] ?? []);
        $bodyHtml = GuideBlockRenderer::toHtml($blocks);
        $now = gmdate('Y-m-d H:i:s');

        $pdo = Database::pdo();

        if ($id > 0) {
            $existing = self::getById($id);
            if ($existing === null) {
                throw new \InvalidArgumentException('Post not found.');
            }

            $publishedAt = $existing['published_at'];
            if ($status === 'published' && $publishedAt === null) {
                $publishedAt = $now;
            }
            if ($status === 'draft') {
                $publishedAt = null;
            }

            $stmt = $pdo->prepare(
                'UPDATE guide_posts SET
                    slug = ?, title = ?, description = ?, category = ?, schema_type = ?,
                    featured_image = ?, body_blocks_json = ?, body_html = ?, status = ?,
                    published_at = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $slug,
                $title,
                $description,
                $category,
                $schemaType,
                $featuredImage !== '' ? $featuredImage : null,
                json_encode($blocks, JSON_THROW_ON_ERROR),
                $bodyHtml,
                $status,
                $publishedAt,
                $now,
                $id,
            ]);

            return self::getById($id) ?? [];
        }

        $publishedAt = $status === 'published' ? $now : null;
        $stmt = $pdo->prepare(
            'INSERT INTO guide_posts (
                slug, title, description, category, schema_type, featured_image,
                body_blocks_json, body_html, status, author_user_id, published_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $slug,
            $title,
            $description,
            $category,
            $schemaType,
            $featuredImage !== '' ? $featuredImage : null,
            json_encode($blocks, JSON_THROW_ON_ERROR),
            $bodyHtml,
            $status,
            $authorUserId,
            $publishedAt,
            $now,
            $now,
        ]);

        return self::getById((int) $pdo->lastInsertId()) ?? [];
    }

    public static function delete(int $id): void
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare('DELETE FROM guide_posts WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function slugTaken(string $slug, int $ignoreId = 0): bool
    {
        self::ensureSchema();
        $slug = self::normalizeSlug($slug);

        $stmt = Database::pdo()->prepare(
            'SELECT id FROM guide_posts WHERE slug = ? AND id != ? LIMIT 1'
        );
        $stmt->execute([$slug, $ignoreId]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        return FplGuidesService::staticSlugExists($slug);
    }

    public static function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9-]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return $value;
    }

    public static function normalizeSchemaType(string $value): string
    {
        return in_array($value, self::SCHEMA_TYPES, true) ? $value : 'Article';
    }

    public static function normalizeCategory(string $value): string
    {
        return in_array($value, self::CATEGORIES, true) ? $value : 'Basics';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalizeBlocks(mixed $blocks): array
    {
        if (!is_array($blocks)) {
            return [['type' => 'paragraph', 'text' => '']];
        }

        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || !isset($block['type'])) {
                continue;
            }
            $out[] = $block;
        }

        return $out !== [] ? $out : [['type' => 'paragraph', 'text' => '']];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRow(array $row): array
    {
        $blocks = json_decode((string) ($row['body_blocks_json'] ?? '[]'), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'slug' => (string) ($row['slug'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'category' => (string) ($row['category'] ?? 'Basics'),
            'schema_type' => (string) ($row['schema_type'] ?? 'Article'),
            'featured_image' => (string) ($row['featured_image'] ?? ''),
            'blocks' => is_array($blocks) ? $blocks : [],
            'body_html' => (string) ($row['body_html'] ?? ''),
            'status' => (string) ($row['status'] ?? 'draft'),
            'author_user_id' => isset($row['author_user_id']) ? (int) $row['author_user_id'] : null,
            'published_at' => $row['published_at'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'read_mins' => ArticleLoader::readMins((string) ($row['body_html'] ?? '')),
            'source' => 'database',
        ];
    }
}
