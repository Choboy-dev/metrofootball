<?php

declare(strict_types=1);

$mailAppName = \App\Mail\MailService::appName();
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#dc2626">
<base href="/">
<title><?= isset($pageTitle) ? mail_h($pageTitle) . ' · ' : '' ?><?= mail_h($mailAppName) ?></title>
<link rel="icon" href="/images/metro-football-favicon.png" type="image/png">
