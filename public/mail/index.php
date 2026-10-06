<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;

mail_send_security_headers();

$user = MailService::requireUser();
$userId = mail_user_id($user);

$folder = (string) ($_GET['folder'] ?? 'inbox');
$category = (string) ($_GET['category'] ?? 'primary');
$query = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$allowedFolders = ['inbox', 'starred', 'sent', 'drafts', 'scheduled', 'archive', 'spam', 'trash'];
if (!in_array($folder, $allowedFolders, true)) {
    $folder = 'inbox';
}

$allowedCategories = array_keys(mail_category_tabs());
if (!in_array($category, $allowedCategories, true)) {
    $category = 'primary';
}

$result = MailService::listMessages(
    $userId,
    $folder,
    $folder === 'inbox' && $query === '' ? $category : null,
    $query !== '' ? $query : null,
    $page
);

$messages = $result['items'] ?? [];
$total = (int) ($result['total'] ?? 0);
$perPage = (int) ($result['per_page'] ?? 25);
$totalPages = max(1, (int) ceil($total / $perPage));
$counts = MailService::folderCounts($userId);
$categoryUnreadCounts = MailService::inboxCategoryCounts($userId, true);
$categoryTotals = MailService::inboxCategoryCounts($userId);
$inboxState = MailService::inboxState($userId);
$compose = mail_compose_is_open($_GET) ? MailService::composeContext($user, $_GET) : null;

$folderLabels = [
    'inbox' => 'Inbox',
    'starred' => 'Starred',
    'sent' => 'Sent',
    'drafts' => 'Drafts',
    'scheduled' => 'Scheduled',
    'archive' => 'Archive',
    'spam' => 'Spam',
    'trash' => 'Trash',
];

$pageTitle = $folderLabels[$folder] ?? 'Mail';
$activeFolder = $folder;
$searchQuery = $query;
$folderForSearch = $folder;

$baseListUrl = mail_url() . '?' . http_build_query(array_filter([
    'folder' => $folder,
    'category' => ($folder === 'inbox' && $query === '') ? $category : null,
    'q' => $query !== '' ? $query : null,
]));

require __DIR__ . '/partials/layout-start.php';
?>

<form id="mail-bulk-form" class="mail-list-form" method="post" action="<?= mail_h(mail_url('actions')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= mail_h($_SERVER['REQUEST_URI'] ?? mail_url()) ?>">

    <?php require __DIR__ . '/partials/list-toolbar.php'; ?>

    <?php if ($folder === 'inbox' && $query === ''): ?>
        <nav class="mail-tabs" aria-label="Inbox categories">
            <?php foreach (mail_category_tabs() as $key => $label): ?>
                <?php
                $tabUrl = mail_url() . '?' . http_build_query(['folder' => 'inbox', 'category' => $key]);
                $isActive = $category === $key;
                $tabCount = (int) ($categoryUnreadCounts[$key] ?? 0);
                ?>
                <a class="mail-tabs__tab<?= $isActive ? ' is-active' : '' ?>" href="<?= mail_h($tabUrl) ?>">
                    <?= mail_h($label) ?>
                    <?php if ($tabCount > 0): ?>
                        <span class="mail-tabs__count"><?= $tabCount ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($messages === []): ?>
        <div class="mail-empty-state">
            <p class="mail-empty">No messages in this folder.</p>
            <?php if ($folder === 'inbox' && $query === '' && $category === 'primary'): ?>
                <?php
                $otherCount = (int) ($categoryTotals['social'] ?? 0)
                    + (int) ($categoryTotals['updates'] ?? 0)
                    + (int) ($categoryTotals['promotions'] ?? 0);
                ?>
                <?php if ($otherCount > 0): ?>
                    <p class="mail-empty__hint">Try the Social or Updates tabs for verification and notification mail.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="mail-list-wrap">
            <ul class="mail-list" data-mail-list>
                <?php foreach ($messages as $message): ?>
                    <?php
                    $messageId = (int) $message['id'];
                    $isUnread = empty($message['is_read']);
                    $isStarred = !empty($message['is_starred']);
                    $isScheduled = $folder === 'scheduled';

                    if ($folder === 'drafts') {
                        $rowHref = mail_url() . '?' . http_build_query([
                            'folder' => 'drafts',
                            'compose' => 'draft',
                            'draft' => $messageId,
                        ]);
                    } elseif ($isScheduled) {
                        $rowHref = '#';
                    } else {
                        $rowHref = mail_url('message') . '?id=' . $messageId;
                    }

                    $sender = MailService::displaySender($message);
                    $subject = trim((string) ($message['subject'] ?? ''));
                    if ($subject === '') {
                        $subject = '(no subject)';
                    }
                    $snippet = trim((string) ($message['snippet'] ?? ''));
                    $dateLabel = MailService::formatListDate((string) ($message['sent_at'] ?? $message['received_at'] ?? $message['created_at'] ?? ''));
                    ?>
                    <li
                        class="mail-row<?= $isUnread ? ' is-unread' : '' ?>"
                        data-mail-row
                        data-href="<?= mail_h($rowHref) ?>"
                    >
                        <label class="mail-row__check" onclick="event.stopPropagation()">
                            <input type="checkbox" name="ids[]" value="<?= $messageId ?>" data-mail-row-check aria-label="Select message">
                        </label>

                        <?php if ($isScheduled): ?>
                            <button
                                type="button"
                                class="mail-row__cancel"
                                data-mail-cancel-schedule="<?= $messageId ?>"
                                aria-label="Cancel scheduled send"
                                onclick="event.stopPropagation()"
                            >
                                <?= mail_icon('close', '', 18) ?>
                            </button>
                        <?php else: ?>
                            <button
                                type="button"
                                class="mail-row__star<?= $isStarred ? ' is-active' : '' ?>"
                                data-mail-star="<?= $messageId ?>"
                                aria-label="<?= $isStarred ? 'Unstar' : 'Star' ?>"
                                aria-pressed="<?= $isStarred ? 'true' : 'false' ?>"
                                onclick="event.stopPropagation()"
                            >
                                <?= mail_icon($isStarred ? 'star-filled' : 'star', '', 18) ?>
                            </button>
                        <?php endif; ?>

                        <span class="mail-row__sender"><?= mail_h($sender) ?></span>

                        <div class="mail-row__subject-wrap">
                            <span class="mail-row__subject"><?= mail_h($subject) ?></span>
                            <?php if ($snippet !== ''): ?>
                                <span class="mail-row__snippet"><?= mail_h($snippet) ?></span>
                            <?php endif; ?>
                        </div>

                        <span class="mail-row__time">
                            <?php if ($isScheduled): ?>
                                <span class="mail-row__schedule">
                                    <?= mail_icon('clock', '', 16) ?>
                                    <?= mail_h(MailService::formatScheduledAt((string) ($message['scheduled_at'] ?? ''))) ?>
                                </span>
                            <?php else: ?>
                                <?= mail_h($dateLabel) ?>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</form>

<?php
require __DIR__ . '/partials/layout-end.php';
