<?php

declare(strict_types=1);

/** @var bool $skipAnalytics */
$skipAnalytics = $skipAnalytics ?? false;
if ($skipAnalytics) {
    return;
}
?>
<script defer src="/js/gtag-config.js?v=2"></script>
