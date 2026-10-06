<?php

declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';

use App\FplGuidesService;

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex', true);
header('Cache-Control: public, max-age=3600');

$entries = FplGuidesService::sitemapEntries();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($entries as $entry) {
    $loc = htmlspecialchars((string) ($entry['loc'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $lastmod = htmlspecialchars((string) ($entry['lastmod'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    if ($loc === '') {
        continue;
    }
    echo "  <url>\n";
    echo '    <loc>' . $loc . "</loc>\n";
    if ($lastmod !== '') {
        echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    }
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
