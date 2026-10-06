<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Mail\MailService;

mail_send_security_headers();

$user = MailService::requireUser();
$userId = mail_user_id($user);

$signatures = MailService::listSignatures($userId);
$editSignatureId = (int) ($_GET['signature'] ?? 0);
$editingSignature = $editSignatureId > 0 ? MailService::getSignature($userId, $editSignatureId) : null;

$pageTitle = 'Settings';
$activeFolder = 'settings';
$compose = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    try {
        switch ($formAction) {
            case 'avatar_upload':
                $file = $_FILES['avatar'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException('Choose an image to upload.');
                }
                if ((int) ($file['size'] ?? 0) > 3145728) {
                    throw new \RuntimeException('Image must be 3 MB or smaller.');
                }
                MailService::storeAvatar($userId, (string) ($file['tmp_name'] ?? ''));
                mail_flash('success', 'Profile picture updated.');
                break;

            case 'avatar_remove':
                MailService::removeAvatar($userId);
                mail_flash('success', 'Profile picture removed.');
                break;

            case 'signature_save':
                MailService::saveSignature(
                    $userId,
                    (int) ($_POST['signature_id'] ?? 0) ?: null,
                    (string) ($_POST['name'] ?? ''),
                    (string) ($_POST['html'] ?? ''),
                    !empty($_POST['is_default'])
                );
                mail_flash('success', 'Signature saved.');
                break;

            case 'signature_delete':
                MailService::deleteSignature($userId, (int) ($_POST['signature_id'] ?? 0));
                mail_flash('success', 'Signature deleted.');
                break;

            case 'signature_default':
                MailService::setDefaultSignature($userId, (int) ($_POST['signature_id'] ?? 0));
                mail_flash('success', 'Default signature updated.');
                break;

            case 'signature_prefs':
                MailService::updateSignaturePreferences(
                    $userId,
                    !empty($_POST['signature_on_new']),
                    !empty($_POST['signature_on_reply'])
                );
                mail_flash('success', 'Signature preferences saved.');
                break;

            default:
                throw new \InvalidArgumentException('Unknown action.');
        }
    } catch (\Throwable $e) {
        mail_flash('error', $e->getMessage());
    }

    $redirect = mail_url('settings');
    if ($formAction === 'signature_save' && isset($_POST['signature_id']) && (int) $_POST['signature_id'] > 0) {
        $redirect .= '?signature=' . (int) $_POST['signature_id'];
    }
    header('Location: ' . $redirect);
    exit;
}

$user = MailService::currentUser() ?? $user;
$signatures = MailService::listSignatures($userId);
$avatarUrl = MailService::avatarUrl($user);

require __DIR__ . '/partials/layout-start.php';
?>

<section class="mail-settings">
    <header class="mail-settings__header">
        <h1 class="mail-settings__title">Settings</h1>
    </header>

    <section class="mail-settings__section">
        <h2 class="mail-settings__heading">Profile picture</h2>
        <div class="mail-settings__avatar" data-mail-avatar-form>
            <div class="mail-settings__avatar-preview">
                <?php if ($avatarUrl): ?>
                    <img src="<?= mail_h($avatarUrl) ?>" alt="" width="96" height="96" decoding="async" data-mail-avatar-preview>
                <?php else: ?>
                    <span class="mail-settings__avatar-fallback" data-mail-avatar-preview><?= mail_h(MailService::userInitials($user)) ?></span>
                <?php endif; ?>
            </div>

            <form class="mail-settings__avatar-upload" method="post" enctype="multipart/form-data" action="<?= mail_h(mail_url('settings')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="avatar_upload">
                <label class="mf-btn mf-btn--ink mf-btn--sm">
                    <span class="mf-btn__label">Upload photo</span>
                    <span class="mf-btn__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp" hidden data-mail-avatar-input>
                </label>
            </form>

            <?php if ($avatarUrl): ?>
                <form method="post" action="<?= mail_h(mail_url('settings')) ?>" data-mail-avatar-remove-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="avatar_remove">
                    <button type="submit" class="mail-settings__link-btn" data-mail-avatar-remove>Remove photo</button>
                </form>
            <?php endif; ?>

            <p class="mail-settings__hint">PNG, JPEG, GIF, or WebP up to 3 MB. Shown in Metro Mail only.</p>
        </div>
    </section>

    <section class="mail-settings__section">
        <h2 class="mail-settings__heading">Signature preferences</h2>
        <form class="mail-settings__prefs" method="post" action="<?= mail_h(mail_url('settings')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="signature_prefs">

            <label class="mail-settings__check">
                <input type="checkbox" name="signature_on_new" value="1" <?= !empty($user['signature_on_new']) ? 'checked' : '' ?>>
                <span>Append default signature to new messages</span>
            </label>

            <label class="mail-settings__check">
                <input type="checkbox" name="signature_on_reply" value="1" <?= !empty($user['signature_on_reply']) ? 'checked' : '' ?>>
                <span>Append default signature to replies and forwards</span>
            </label>

            <button type="submit" class="mf-btn mf-btn--primary mf-btn--sm">
                <span class="mf-btn__label">Save preferences</span>
                <span class="mf-btn__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
            </button>
        </form>
    </section>

    <section class="mail-settings__section" data-mail-signatures>
        <div class="mail-settings__section-head">
            <h2 class="mail-settings__heading">Signatures</h2>
            <a class="mail-settings__link" href="<?= mail_h(mail_url('settings')) ?>">New signature</a>
        </div>

        <?php if ($signatures !== []): ?>
            <ul class="mail-settings__signature-list">
                <?php foreach ($signatures as $signature): ?>
                    <li class="mail-settings__signature-item">
                        <div class="mail-settings__signature-copy">
                            <strong><?= mail_h((string) $signature['name']) ?></strong>
                            <?php if (!empty($signature['is_default'])): ?>
                                <span class="mail-settings__badge">Default</span>
                            <?php endif; ?>
                            <div class="mail-settings__signature-preview"><?= (string) $signature['body_html'] ?></div>
                        </div>
                        <div class="mail-settings__signature-actions">
                            <a href="<?= mail_h(mail_url('settings') . '?signature=' . (int) $signature['id']) ?>">Edit</a>
                            <?php if (empty($signature['is_default'])): ?>
                                <form method="post" action="<?= mail_h(mail_url('settings')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form_action" value="signature_default">
                                    <input type="hidden" name="signature_id" value="<?= (int) $signature['id'] ?>">
                                    <button type="submit" class="mail-settings__link-btn">Make default</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= mail_h(mail_url('settings')) ?>" data-mail-signature-delete="<?= (int) $signature['id'] ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="signature_delete">
                                <input type="hidden" name="signature_id" value="<?= (int) $signature['id'] ?>">
                                <button type="submit" class="mail-settings__link-btn mail-settings__link-btn--danger">Delete</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form class="mail-settings__signature-form" method="post" action="<?= mail_h(mail_url('settings')) ?>" data-mail-signature-form>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="signature_save">
            <input type="hidden" name="signature_id" value="<?= mail_h((string) ($editingSignature['id'] ?? '')) ?>">
            <input type="hidden" name="html" value="" data-mail-signature-html-input>

            <label class="mail-field">
                <span class="mail-field__label">Signature name</span>
                <input
                    class="mail-field__input"
                    type="text"
                    name="name"
                    maxlength="120"
                    required
                    value="<?= mail_h((string) ($editingSignature['name'] ?? '')) ?>"
                >
            </label>

            <label class="mail-field">
                <span class="mail-field__label">Signature body</span>
            </label>

            <?php
            $toolbarId = 'mail-signature-toolbar';
            $editorId = 'mail-signature-editor';
            require __DIR__ . '/partials/editor-toolbar.php';
            ?>

            <div
                id="<?= mail_h($editorId) ?>"
                class="mail-settings__editor"
                contenteditable="true"
                role="textbox"
                aria-multiline="true"
                data-mail-editor
            ><?= (string) ($editingSignature['body_html'] ?? '') ?></div>

            <label class="mail-settings__check">
                <input type="checkbox" name="is_default" value="1" <?= !empty($editingSignature['is_default']) ? 'checked' : '' ?>>
                <span>Use as default signature</span>
            </label>

            <button type="submit" class="mf-btn mf-btn--primary">
                <span class="mf-btn__label"><?= $editingSignature ? 'Update signature' : 'Create signature' ?></span>
                <span class="mf-btn__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
            </button>
        </form>
    </section>
</section>

<?php
require __DIR__ . '/partials/layout-end.php';
