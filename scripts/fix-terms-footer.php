<?php

declare(strict_types=1);

/**
 * One-off: point footer Terms links to ./terms across public HTML.
 * Run: php scripts/fix-terms-footer.php
 */

$root = dirname(__DIR__) . '/public';
$pattern = 'href="#">Terms</a>';
$replacement = 'href="./terms">Terms</a>';
$count = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'html') {
        continue;
    }
    $path = $file->getPathname();
    $html = file_get_contents($path);
    if ($html === false || !str_contains($html, $pattern)) {
        continue;
    }
    file_put_contents($path, str_replace($pattern, $replacement, $html));
    echo "fixed: {$path}\n";
    $count++;
}

echo "Updated {$count} file(s).\n";
