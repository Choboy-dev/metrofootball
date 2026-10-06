<?php

declare(strict_types=1);

use App\Mail\MailService;

/** @var array $user */
/** @var array<string, mixed>|null $compose */

$userId = mail_user_id($user);
$compose = is_array($compose ?? null) ? $compose : [];
$signatures = MailService::listSignatures($userId);
$open = $compose !== [] || mail_compose_is_open($_GET);
$panelTitle = (string) ($compose['title'] ?? 'New message');
$scheduleOptions = MailService::scheduleOptions();

$closeQuery = $_GET;
unset($closeQuery['compose'], $closeQuery['source'], $closeQuery['draft']);
$closeUrl = mail_url() . ($closeQuery !== [] ? '?' . http_build_query($closeQuery) : '');
?>
<div
    class="mail-compose<?= $open ? ' is-open' : '' ?>"
    data-mail-compose
    <?= $open ? '' : 'hidden' ?>
>
    <header class="mail-compose__header">
        <h2 class="mail-compose__title"><?= mail_h($panelTitle) ?></h2>
        <button type="button" class="mail-compose__chrome-btn" data-mail-compose-minimize aria-label="Minimize">
            <?= mail_icon('minimize', '', 20) ?>
        </button>
        <button type="button" class="mail-compose__chrome-btn" data-mail-compose-fullscreen aria-label="Full screen">
            <?= mail_icon('maximize', '', 20) ?>
        </button>
        <a class="mail-compose__chrome-btn" href="<?= mail_h($closeUrl) ?>" data-mail-compose-close aria-label="Save and close">
            <?= mail_icon('close', '', 20) ?>
        </a>
    </header>

    <form
        class="mail-compose__form"
        method="post"
        action="<?= mail_h(mail_url('compose')) ?>"
        enctype="multipart/form-data"
        data-mail-compose-form
    >
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="compose_mode" value="<?= mail_h((string) ($compose['mode'] ?? 'new')) ?>">
        <input type="hidden" name="draft_id" value="<?= mail_h((string) ($compose['draft_id'] ?? '')) ?>">
        <input type="hidden" name="in_reply_to" value="<?= mail_h((string) ($compose['in_reply_to'] ?? '')) ?>">
        <input type="hidden" name="return_to" value="<?= mail_h((string) ($_SERVER['REQUEST_URI'] ?? mail_url())) ?>">
        <input type="hidden" name="html" value="">
        <input type="hidden" name="scheduled_at" value="">

        <div class="mail-compose__body">
            <div class="mail-recipients" data-mail-recipients>
                <div class="mail-compose__fields">
                    <div class="mail-compose__field" data-mail-field="to">
                        <span class="mail-compose__label">To</span>
                        <input
                            id="mail-compose-to"
                            class="mail-compose__input"
                            type="text"
                            name="to"
                            value="<?= mail_h((string) ($compose['to'] ?? '')) ?>"
                            autocomplete="off"
                        >
                        <div class="mail-compose__toggles">
                            <button type="button" class="mail-compose__toggle" data-mail-toggle-cc<?= !empty($compose['show_cc']) ? ' hidden' : '' ?>>Cc</button>
                            <button type="button" class="mail-compose__toggle" data-mail-toggle-bcc<?= !empty($compose['show_bcc']) ? ' hidden' : '' ?>>Bcc</button>
                        </div>
                    </div>

                    <div class="mail-compose__field" data-mail-field="cc"<?= empty($compose['show_cc']) ? ' hidden' : '' ?>>
                        <span class="mail-compose__label">Cc</span>
                        <input
                            id="mail-compose-cc"
                            class="mail-compose__input"
                            type="text"
                            name="cc"
                            value="<?= mail_h((string) ($compose['cc'] ?? '')) ?>"
                            autocomplete="off"
                        >
                    </div>

                    <div class="mail-compose__field" data-mail-field="bcc"<?= empty($compose['show_bcc']) ? ' hidden' : '' ?>>
                        <span class="mail-compose__label">Bcc</span>
                        <input
                            id="mail-compose-bcc"
                            class="mail-compose__input"
                            type="text"
                            name="bcc"
                            value="<?= mail_h((string) ($compose['bcc'] ?? '')) ?>"
                            autocomplete="off"
                        >
                    </div>

                    <div class="mail-compose__field" data-mail-field="subject">
                        <span class="mail-compose__label">Subject</span>
                        <input
                            id="mail-compose-subject"
                            class="mail-compose__input"
                            type="text"
                            name="subject"
                            value="<?= mail_h((string) ($compose['subject'] ?? '')) ?>"
                            autocomplete="off"
                        >
                    </div>
                </div>

                <button type="button" class="mail-recipients__summary">
                    <span data-mail-recipients-summary-text></span>
                    <span class="mail-recipients__chip" data-mail-recipients-bcc-chip hidden></span>
                    <span data-mail-recipients-more hidden></span>
                </button>
            </div>

            <div class="mail-editor-wrap is-toolbar-hidden">
                <?php
                $toolbarId = 'mail-compose-toolbar';
                $editorId = 'mail-compose-editor';
                require __DIR__ . '/editor-toolbar.php';
                ?>
                <div
                    id="<?= mail_h($editorId) ?>"
                    class="mail-editor"
                    contenteditable="true"
                    role="textbox"
                    aria-multiline="true"
                    aria-label="Message body"
                    data-mail-editor
                ><?= (string) ($compose['html'] ?? '') ?></div>
            </div>

            <ul class="mail-attach-list" data-mail-attach-list hidden></ul>
        </div>

        <footer class="mail-compose__footer">
            <div class="mail-compose__send-wrap">
                <button type="submit" class="mail-compose__send" data-mail-compose-send>
                    Send
                </button>
                <button type="button" class="mail-compose__send-caret" data-mail-schedule-open aria-label="Schedule send">
                    <?= mail_icon('chevron-down', '', 18) ?>
                </button>

                <div class="mail-schedule" data-mail-schedule hidden>
                    <h3 class="mail-schedule__title">Schedule send</h3>
                    <?php foreach ($scheduleOptions as $option): ?>
                        <button
                            type="button"
                            class="mail-schedule__option"
                            data-mail-schedule-preset="<?= mail_h((string) $option['value']) ?>"
                        >
                            <?= mail_h((string) $option['label']) ?>
                        </button>
                    <?php endforeach; ?>
                    <div class="mail-schedule__custom">
                        <input type="datetime-local" data-mail-schedule-custom aria-label="Custom schedule time">
                        <div class="mail-schedule__actions">
                            <button type="button" class="mail-schedule__btn mail-schedule__btn--ghost" data-mail-schedule-cancel>Cancel</button>
                            <button type="button" class="mail-schedule__btn mail-schedule__btn--primary" data-mail-schedule-apply>Schedule</button>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="mail-compose__icon-btn" data-mail-formatting-toggle aria-label="Formatting options">
                <?= mail_icon('format', '', 20) ?>
            </button>

            <label class="mail-compose__icon-btn" aria-label="Attach files">
                <?= mail_icon('attach', '', 20) ?>
                <input type="file" name="attachments[]" class="mail-compose__attach-input" multiple data-mail-attach-input>
            </label>

            <button type="button" class="mail-compose__icon-btn" data-mail-insert-link aria-label="Insert link">
                <?= mail_icon('link', '', 20) ?>
            </button>

            <button type="button" class="mail-compose__icon-btn" data-mail-insert-signature aria-label="Insert signature">
                <?= mail_icon('signature', '', 20) ?>
            </button>

            <button type="button" class="mail-compose__icon-btn" data-mail-save-draft aria-label="Save draft">
                <?= mail_icon('drafts', '', 20) ?>
            </button>

            <button type="button" class="mail-compose__icon-btn mail-compose__icon-btn--danger" data-mail-discard aria-label="Discard draft">
                <?= mail_icon('trash', '', 20) ?>
            </button>
        </footer>
    </form>

    <div class="mail-pop" data-mail-link-pop hidden>
        <div class="mail-pop__field">
            <label for="mail-compose-link-url">Link URL</label>
            <input id="mail-compose-link-url" type="url" placeholder="https://" name="url">
        </div>
        <button type="button" class="mail-pop__item" data-mail-link-apply>Insert link</button>
    </div>

    <div class="mail-pop" data-mail-signature-pop hidden>
        <?php if ($signatures === []): ?>
            <p class="mail-pop__item">No signatures yet.</p>
        <?php else: ?>
            <?php foreach ($signatures as $signature): ?>
                <button
                    type="button"
                    class="mail-pop__item"
                    data-mail-signature-item="<?= (int) $signature['id'] ?>"
                >
                    <?= mail_h((string) $signature['name']) ?>
                </button>
                <template data-mail-signature-html="<?= (int) $signature['id'] ?>"><?= (string) $signature['body_html'] ?></template>
            <?php endforeach; ?>
        <?php endif; ?>
        <a class="mail-pop__item mail-pop__item--link" href="<?= mail_h(mail_url('settings')) ?>">Manage signatures</a>
    </div>
</div>
