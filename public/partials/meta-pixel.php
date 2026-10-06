<?php

declare(strict_types=1);

/** @var bool $skipMetaPixel */
$skipMetaPixel = $skipMetaPixel ?? false;
if ($skipMetaPixel) {
    return;
}

readfile(__DIR__ . '/meta-pixel.html');
