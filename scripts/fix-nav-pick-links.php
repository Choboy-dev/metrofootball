<?php

declare(strict_types=1);

/**
 * Fix corrupted mega menu links where Rate My Team pointed at gameweek-recap.
 */

$public = dirname(__DIR__) . '/public';
$fixed = 0;

foreach (glob($public . '/*.html') ?: [] as $file) {
    $html = file_get_contents($file);
    if ($html === false) {
        continue;
    }

    $pattern = '/(<a class="list-item" href="\.\/gameweek-recap"(?:\s+aria-current="page")?\s*>\s*<span class="list-item__copy">\s*<span class="list-item__title">Rate My Team<\/span>)/';
    $replacement = '<a class="list-item" href="./pick" data-auth-tool><span class="list-item__copy"><span class="list-item__title">Rate My Team</span>';
    $new = preg_replace($pattern, $replacement, $html, -1, $count);

    if ($count > 0 && $new !== null && $new !== $html) {
        file_put_contents($file, $new);
        $fixed += $count;
        echo basename($file) . ": {$count}\n";
    }
}

echo "Fixed {$fixed} link(s)\n";
