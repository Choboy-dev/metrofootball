<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;

mail_send_security_headers();

$user = MailService::requireUser();
$userId = mail_user_id($user);

$messageId = (int) ($_GET['id'] ?? 0);
if ($messageId <= 0) {
    mail_flash('error', 'Message not found.');
    header('Location: ' . mail_url());
    exit;
}

$message = MailService::getMessage($userId, $messageId);
if ($message === null) {
    mail_flash('error', 'Message not found.');
    header('Location: ' . mail_url());
    exit;
}

MailService::markRead($userId, [$messageId], true);
$attachments = MailService::getAttachments($messageId);
$compose = mail_compose_is_open($_GET) ? MailService::composeContext($user, $_GET) : null;

$pageTitle = trim((string) ($message['subject'] ?? '')) ?: '(no subject)';
$activeFolder = (string) ($message['folder'] ?? 'inbox');
$folderForSearch = $activeFolder;

$fromName = trim((string) ($message['from_name'] ?? ''));
$fromEmail = trim((string) ($message['from_email'] ?? ''));
$senderLine = $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;

$toList = MailService::decodeJsonList($message['to_emails'] ?? '[]');
$ccList = MailService::decodeJsonList($message['cc_emails'] ?? '[]');
$bccList = MailService::decodeJsonList($message['bcc_emails'] ?? '[]');
$recipientList = array_values(array_filter(
    array_merge($toList, $ccList, $bccList),
    static fn (string $email): bool => strcasecmp($email, $fromEmail) !== 0
));

$dateLabel = MailService::formatListDate((string) ($message['sent_at'] ?? $message['received_at'] ?? $message['created_at'] ?? ''));
$bodyHtml = trim((string) ($message['body_html'] ?? ''));
$bodyText = trim((string) ($message['body_text'] ?? ''));
$avatarUrl = MailService::avatarUrlForEmail($fromEmail);

$replyUrl = mail_url() . '?' . http_build_query(['compose' => 'reply', 'source' => $messageId]);
$replyAllUrl = mail_url() . '?' . http_build_query(['compose' => 'reply-all', 'source' => $messageId]);
$forwardUrl = mail_url() . '?' . http_build_query(['compose' => 'forward', 'source' => $messageId]);
$backUrl = mail_url() . '?folder=' . rawurlencode($activeFolder);

$shortRecipients = implode(', ', array_slice($recipientList, 0, 2));
$fullRecipients = implode(', ', $recipientList);
$hiddenCount = max(0, count($recipientList) - 2);

require __DIR__ . '/partials/layout-start.php';
?>

<article class="mail-message">
    <div class="mail-toolbar mail-toolbar--message">
        <a class="mail-message__back" href="<?= mail_h($backUrl) ?>">
            <?= mail_icon('back', '', 18) ?>
            <span>Back</span>
        </a>
    </div>

    <h1 class="mail-message__subject"><?= mail_h($pageTitle) ?></h1>

    <header class="mail-message__header">
        <?php if ($avatarUrl): ?>
            <img class="mail-avatar" src="<?= mail_h($avatarUrl) ?>" alt="" width="40" height="40" decoding="async">
        <?php else: ?>
            <span class="mail-avatar mail-avatar--initials" aria-hidden="true"><?= mail_h(MailService::userInitials(['display_name' => $fromName, 'email' => $fromEmail])) ?></span>
        <?php endif; ?>

        <div class="mail-message__meta">
            <div class="mail-message__from-line">
                <?php if ($fromName !== ''): ?>
                    <span class="mail-message__from-name"><?= mail_h($fromName) ?></span>
                    <span class="mail-message__from-email">&lt;<?= mail_h($fromEmail) ?>&gt;</span>
                <?php else: ?>
                    <span class="mail-message__from-name"><?= mail_h($fromEmail) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($recipientList !== []): ?>
                <p class="mail-message__recipients" data-mail-recipients-reveal>
                    <span data-mail-recipients-short>to <?= mail_h($shortRecipients) ?></span>
                    <span data-mail-recipients-full hidden>to <?= mail_h($fullRecipients) ?></span>
                    <?php if ($hiddenCount > 0): ?>
                        <button
                            type="button"
                            class="mail-message__recipients-toggle"
                            data-mail-recipients-toggle
                            data-more-label="(<?= $hiddenCount ?> more)"
                            aria-expanded="false"
                        >(<?= $hiddenCount ?> more)</button>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <time class="mail-message__time"><?= mail_h($dateLabel) ?></time>
        </div>
    </header>

    <?php if ($attachments !== []): ?>
        <div class="mail-attachments">
            <?php foreach ($attachments as $attachment): ?>
                <a
                    class="mail-attachment-chip"
                    href="<?= mail_h(mail_url('download') . '?id=' . (int) $attachment['id']) ?>"
                >
                    <?= mail_icon('paperclip', '', 16) ?>
                    <span><?= mail_h((string) $attachment['filename']) ?></span>
                    <small><?= mail_h(mail_format_bytes((int) ($attachment['size_bytes'] ?? 0))) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($bodyHtml !== ''): ?>
        <iframe
            class="mail-body-frame"
            title="Message body"
            sandbox="allow-same-origin allow-popups"
            srcdoc="<?= mail_h(MailService::htmlSandboxSrcdoc($bodyHtml)) ?>"
        ></iframe>
    <?php elseif ($bodyText !== ''): ?>
        <div class="mail-message-body"><?= nl2br(mail_h($bodyText), false) ?></div>
    <?php else: ?>
        <p class="mail-message-body">This message has no body.</p>
    <?php endif; ?>

    <div class="mail-message-actions">
        <a class="mail-action-btn mail-action-btn--primary" href="<?= mail_h($replyUrl) ?>">
            <?= mail_icon('reply', '', 18) ?> Reply
        </a>
        <a class="mail-action-btn" href="<?= mail_h($replyAllUrl) ?>">
            <?= mail_icon('reply-all', '', 18) ?> Reply all
        </a>
        <a class="mail-action-btn" href="<?= mail_h($forwardUrl) ?>">
            <?= mail_icon('forward', '', 18) ?> Forward
        </a>
    </div>
</article>

<?php
require __DIR__ . '/partials/layout-end.php';
