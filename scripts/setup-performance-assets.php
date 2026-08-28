<?php

declare(strict_types=1);

/**
 * Download self-hosted fonts, Motion bundle, and WebP logos.
 * Usage: php scripts/setup-performance-assets.php
 * Web (one-time): /setup-perf-assets.php?token=mf-perf-20260828
 */

$root = dirname(__DIR__);
$public = $root . '/public';
$fontsDir = $public . '/fonts';
$vendorDir = $public . '/js/vendor';
$imagesDir = $public . '/images';

$downloads = [
    $fontsDir . '/inter-latin.woff2' => 'https://fonts.gstatic.com/s/inter/v20/UcCo3FwrK3iLTcviYwY.woff2',
    $fontsDir . '/inter-latin-ext.woff2' => 'https://fonts.gstatic.com/s/inter/v20/UcCo3FwrK3iLTcvsYwYL8g.woff2',
    $fontsDir . '/google-sans-400.woff' => 'https://fonts.cdnfonts.com/s/149575/GoogleSans-Regular.woff',
    $fontsDir . '/google-sans-500.woff' => 'https://fonts.cdnfonts.com/s/149575/GoogleSans-Medium.woff',
    $fontsDir . '/google-sans-600.woff' => 'https://fonts.cdnfonts.com/s/149575/GoogleSans-SemiBold.woff',
    $fontsDir . '/google-sans-700.woff' => 'https://fonts.cdnfonts.com/s/149575/GoogleSans-Bold.woff',
    $vendorDir . '/motion.umd.js' => 'https://cdn.jsdelivr.net/npm/motion@12.23.12/dist/motion.js',
];

foreach ([$fontsDir, $vendorDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Could not create directory: {$dir}\n");
        exit(1);
    }
}

function download_file(string $url, string $dest): void
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 120,
            'header' => "User-Agent: MetroFootballSetup/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || $data === '') {
        throw new RuntimeException("Download failed: {$url}");
    }

    if (file_put_contents($dest, $data) === false) {
        throw new RuntimeException("Write failed: {$dest}");
    }

    echo 'saved ' . basename($dest) . ' (' . number_format(strlen($data)) . " bytes)\n";
}

function crop_image_to_content(GdImage $image): GdImage
{
    $width = imagesx($image);
    $height = imagesy($image);
    $minX = $width;
    $minY = $height;
    $maxX = 0;
    $maxY = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            $red = ($rgba >> 16) & 0xFF;
            $green = ($rgba >> 8) & 0xFF;
            $blue = $rgba & 0xFF;

            if ($alpha >= 120) {
                continue;
            }
            if ($red < 8 && $green < 8 && $blue < 8) {
                continue;
            }

            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
    }

    if ($maxX <= $minX || $maxY <= $minY) {
        return $image;
    }

    $cropW = $maxX - $minX + 1;
    $cropH = $maxY - $minY + 1;
    $cropped = imagecrop($image, ['x' => $minX, 'y' => $minY, 'width' => $cropW, 'height' => $cropH]);
    if ($cropped === false) {
        return $image;
    }

    imagedestroy($image);
    echo "  cropped to {$cropW}x{$cropH}\n";

    return $cropped;
}

function fix_motion_imports(string $jsDir): int
{
    $fixed = 0;
    foreach (glob($jsDir . '/*.js') ?: [] as $file) {
        if (basename($file) === 'motion.js') {
            continue;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }
        $new = preg_replace(
            "/from\\s+['\"]https:\\/\\/cdn\\.jsdelivr\\.net\\/npm\\/motion@[^'\"]+['\"]/",
            "from './motion.js'",
            $content
        );
        if ($new !== null && $new !== $content) {
            file_put_contents($file, $new);
            echo 'fixed motion import: ' . basename($file) . "\n";
            $fixed++;
        }
    }

    return $fixed;
}

function patch_html_heads(string $publicDir): int
{
    $patched = 0;
    foreach (glob($publicDir . '/*.html') ?: [] as $file) {
        $html = file_get_contents($file);
        if ($html === false) {
            continue;
        }
        $original = $html;

        $html = preg_replace(
            '/\\s*<link rel="preconnect" href="https:\\/\\/fonts\\.googleapis\\.com">\\s*/i',
            "\n",
            $html
        );
        $html = preg_replace(
            '/\\s*<link rel="preconnect" href="https:\\/\\/fonts\\.gstatic\\.com" crossorigin>\\s*/i',
            "\n",
            $html
        );
        $html = preg_replace(
            '/\\s*<link rel="preconnect" href="https:\\/\\/fonts\\.cdnfonts\\.com" crossorigin>\\s*/i',
            "\n",
            $html
        );
        $html = preg_replace(
            '/@import\\s+url\\([\'"]https?:\\/\\/fonts\\.(googleapis|cdnfonts)[^\'"]+[\'"]\\)\\s*;\\s*/i',
            '',
            $html
        );

        if (!str_contains($html, 'perf-head.js')) {
            $html = preg_replace(
                '/(<link rel="stylesheet" href="\\.\\/css\\/)/i',
                '<script src="./js/perf-head.js"></script>' . "\n  " . '$1',
                $html,
                1
            );
        }

        if ($html !== $original) {
            file_put_contents($file, $html);
            echo 'patched html: ' . basename($file) . "\n";
            $patched++;
        }
    }

    return $patched;
}

foreach ($downloads as $dest => $url) {
    try {
        download_file($url, $dest);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

$logoPairs = [
    'metro-football-logo-dark.png' => 'metro-football-logo-dark.webp',
    'metro-football-logo-light.png' => 'metro-football-logo-light.webp',
];

foreach ($logoPairs as $pngName => $webpName) {
    $png = $imagesDir . '/' . $pngName;
    $webp = $imagesDir . '/' . $webpName;
    if (!is_file($png)) {
        echo "skip logo (missing png): {$pngName}\n";
        continue;
    }

    echo "converting {$pngName}...\n";
    $image = @imagecreatefrompng($png);
    if ($image === false) {
        fwrite(STDERR, "Could not read PNG: {$png}\n");
        continue;
    }

    imagepalettetotruecolor($image);
    imagealphablending($image, true);
    imagesavealpha($image, true);
    $image = crop_image_to_content($image);

    if (!imagewebp($image, $webp, 80)) {
        imagedestroy($image);
        fwrite(STDERR, "WebP conversion failed: {$pngName}\n");
        continue;
    }

    imagedestroy($image);
    echo 'saved ' . $webpName . ' (' . number_format(filesize($webp)) . " bytes)\n";
}

$appCss = $public . '/css/app.css';
if (is_file($appCss)) {
    $css = file_get_contents($appCss);
    if ($css !== false) {
        $stripped = preg_replace(
            '/@import\\s+url\\([\'"]https?:\\/\\/fonts\\.(googleapis|cdnfonts)[^\'"]+[\'"]\\)\\s*;\\s*/i',
            '',
            $css
        );
        if ($stripped !== $css) {
            file_put_contents($appCss, $stripped);
            echo "stripped external font @imports from app.css\n";
        }
    }
}

$motionFixed = fix_motion_imports($public . '/js');
$htmlPatched = patch_html_heads($public);

echo "Done. Fixed {$motionFixed} JS files, patched {$htmlPatched} HTML files.\n";
echo "Fonts, Motion (motion.umd.js), and WebP logos are ready.\n";
