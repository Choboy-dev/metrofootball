<?php

declare(strict_types=1);

use App\Mail\MailService;

/** @var array $user */
/** @var string $pageTitle */
/** @var string $activeFolder */
/** @var array<string, int> $counts */
/** @var array|null $inboxState */
/** @var array|null $compose */
/** @var bool $includeCompose */

mail_send_security_headers();

$counts = $counts ?? MailService::folderCounts(mail_user_id($user));
$inboxState = $inboxState ?? MailService::inboxState(mail_user_id($user));
$includeCompose = $includeCompose ?? true;
$searchQuery = $searchQuery ?? '';
$folderForSearch = $folderForSearch ?? ($activeFolder ?? 'inbox');
$bodyClass = trim('mail-body ' . ($bodyClass ?? ''));
$latestId = (int) ($inboxState['latest_id'] ?? 0);
$unreadCount = (int) ($inboxState['unread'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <?php require __DIR__ . '/head-meta.php'; ?>
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/mail-dropdown.css?v=1')) ?>">
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/mail-modal.css?v=3')) ?>">
    <link rel="stylesheet" href="<?= mail_h(mail_asset('css/mail.css?v=13')) ?>">
</head>
<body
    class="<?= mail_h($bodyClass) ?>"
    data-mail-path="<?= mail_h(mail_path()) ?>"
    data-mail-latest-id="<?= $latestId ?>"
    data-mail-unread="<?= $unreadCount ?>"
>
<div class="mail-shell">
    <?php mail_render_sidebar($user, $activeFolder, $counts); ?>

    <div class="mail-main">
        <?php mail_render_topbar($user, $searchQuery, $folderForSearch); ?>

        <div class="mail-live-toast" data-mail-live-toast hidden role="status">New mail arrived</div>

        <?php mail_render_flash(); ?>

        <div class="mail-content">
