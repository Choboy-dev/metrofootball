<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Mail\MailService;

mail_send_security_headers();

try {
    $user = MailService::requireAdmin();
} catch (\Throwable $e) {
    http_response_code(403);
    echo mail_h($e->getMessage());
    exit;
}

$users = MailService::listUsers();
$domain = MailService::domain();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    try {
        if ($formAction === 'create') {
            $local = strtolower(trim((string) ($_POST['local_part'] ?? '')));
            $email = $local . '@' . $domain;
            MailService::createUser(
                $email,
                (string) ($_POST['display_name'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['role'] ?? 'user'),
                true
            );
            mail_flash('success', 'Mailbox created.');
        } elseif ($formAction === 'update') {
            MailService::updateUser((int) ($_POST['user_id'] ?? 0), [
                'display_name' => (string) ($_POST['display_name'] ?? ''),
                'role' => (string) ($_POST['role'] ?? 'user'),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                'password' => trim((string) ($_POST['password'] ?? '')),
            ]);
            mail_flash('success', 'Mailbox updated.');
        } else {
            throw new \InvalidArgumentException('Unknown action.');
        }
    } catch (\Throwable $e) {
        mail_flash('error', $e->getMessage());
    }

    header('Location: ' . mail_url('admin/users'));
    exit;
}

$pageTitle = 'Users';
$activeFolder = 'admin';
$compose = null;

require dirname(__DIR__) . '/partials/layout-start.php';
?>

<section class="mail-page">
    <h1 class="mail-page__title">Mail users</h1>
    <p class="mail-page__lead">Create and manage @<?= mail_h($domain) ?> mailboxes.</p>

    <section class="mail-card">
        <h2 class="mail-card__title">Create mailbox</h2>
        <form method="post" action="<?= mail_h(mail_url('admin/users')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">

            <div class="mail-form-grid">
                <label class="mail-field">
                    <span class="mail-field__label">Email local part</span>
                    <div class="mail-email-input">
                        <input class="mail-field__input mail-email-input__local" type="text" name="local_part" required pattern="[a-z0-9._\-]+" autocomplete="off" placeholder="hello">
                        <span class="mail-email-input__domain">@<?= mail_h($domain) ?></span>
                    </div>
                </label>

                <label class="mail-field">
                    <span class="mail-field__label">Display name</span>
                    <input class="mail-field__input" type="text" name="display_name" required maxlength="120">
                </label>

                <label class="mail-field">
                    <span class="mail-field__label">Temporary password</span>
                    <input class="mail-field__input" type="password" name="password" required minlength="12" autocomplete="new-password">
                </label>

                <label class="mail-field">
                    <span class="mail-field__label">Role</span>
                    <select class="mail-field__input" name="role" data-mail-dropdown>
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </label>
            </div>

            <button type="submit" class="mf-btn mf-btn--primary">
                <span class="mf-btn__label">Create mailbox</span>
                <span class="mf-btn__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
            </button>
        </form>
    </section>

    <section class="mail-card">
        <h2 class="mail-card__title">All mailboxes</h2>

        <?php if ($users === []): ?>
            <p class="mail-page__empty">No mailboxes yet.</p>
        <?php else: ?>
            <div class="mail-table-wrap">
                <table class="mail-table">
                    <thead>
                        <tr>
                            <th scope="col">Email</th>
                            <th scope="col">Name</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $mailbox): ?>
                            <tr>
                                <td><?= mail_h((string) $mailbox['email']) ?></td>
                                <td><?= mail_h((string) ($mailbox['display_name'] ?? '')) ?></td>
                                <td><span class="mail-badge mail-badge--role"><?= mail_h((string) ($mailbox['role'] ?? 'user')) ?></span></td>
                                <td>
                                    <div class="mail-badge-row">
                                        <?php if (!empty($mailbox['is_active'])): ?>
                                            <span class="mail-badge mail-badge--success">Active</span>
                                        <?php else: ?>
                                            <span class="mail-badge mail-badge--muted">Disabled</span>
                                        <?php endif; ?>
                                        <?php if (!empty($mailbox['must_change_password'])): ?>
                                            <span class="mail-badge mail-badge--warn">Must change password</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="mail-table__edit"
                                        data-mail-edit-user
                                        data-user-id="<?= (int) $mailbox['id'] ?>"
                                        data-user-email="<?= mail_h((string) $mailbox['email']) ?>"
                                        data-user-name="<?= mail_h((string) ($mailbox['display_name'] ?? '')) ?>"
                                        data-user-role="<?= mail_h((string) ($mailbox['role'] ?? 'user')) ?>"
                                        data-user-active="<?= !empty($mailbox['is_active']) ? '1' : '0' ?>"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>

<div class="lb-modal-root lb-modal-root--panel" id="mail-user-modal" hidden aria-hidden="true">
    <button type="button" class="lb-modal-root__backdrop" data-mail-user-modal-close aria-label="Close dialog"></button>
    <div class="lb-modal lb-modal--form" role="dialog" aria-modal="true" aria-labelledby="mail-user-modal-title">
        <div class="lb-modal__header lb-modal__header--row">
            <div class="lb-modal__header-copy">
                <h2 class="lb-modal__title" id="mail-user-modal-title">Edit mailbox</h2>
                <p class="lb-modal__subtitle" data-mail-user-modal-email></p>
            </div>
            <button type="button" class="lb-modal__close" data-mail-user-modal-close aria-label="Close">
                <?= mail_icon('close', '', 20) ?>
            </button>
        </div>

        <form class="lb-modal__form" method="post" action="<?= mail_h(mail_url('admin/users')) ?>" data-mail-user-form>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="user_id" value="">

            <div class="lb-modal__fields">
                <label class="mail-field">
                    <span class="mail-field__label">Display name</span>
                    <input class="mail-field__input" type="text" name="display_name" required maxlength="120" autocomplete="off">
                </label>

                <label class="mail-field">
                    <span class="mail-field__label">Role</span>
                    <select class="mail-field__input" name="role" data-mail-dropdown>
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </label>

                <label class="mail-field mail-field--switch">
                    <span class="mail-field__label">Mailbox status</span>
                    <span class="mail-switch">
                        <input class="mail-switch__input" type="checkbox" name="is_active" value="1" id="mail-user-active">
                        <span class="mail-switch__track" aria-hidden="true"></span>
                        <span class="mail-switch__label">Active</span>
                    </span>
                </label>

                <label class="mail-field">
                    <span class="mail-field__label">New password</span>
                    <input class="mail-field__input" type="password" name="password" minlength="12" autocomplete="new-password" placeholder="Leave blank to keep current password">
                    <span class="mail-field__hint">At least 12 characters when changing password.</span>
                </label>
            </div>

            <div class="lb-modal__footer lb-modal__footer--form">
                <button type="button" class="lb-modal__btn lb-modal__btn--ghost" data-mail-user-modal-close>Cancel</button>
                <button type="submit" class="mf-btn mf-btn--primary mf-btn--sm">
                    <span class="mf-btn__label">Save changes</span>
                    <span class="mf-btn__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require dirname(__DIR__) . '/partials/layout-end.php';
