<?php

declare(strict_types=1);

/** @var string $folder */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var int $totalPages */

$toolbarActions = mail_list_toolbar_actions($folder);
$rangeStart = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$rangeEnd = $total > 0 ? min($page * $perPage, $total) : 0;
$rangeLabel = $total > 0
    ? $rangeStart . '-' . $rangeEnd . ' of ' . number_format($total)
    : '0 messages';
?>
<div class="mail-inbox-toolbar" data-mail-inbox-toolbar>
    <div class="mail-inbox-toolbar__left">
        <label class="mail-inbox-toolbar__check">
            <input type="checkbox" data-mail-select-all aria-label="Select all messages">
        </label>

        <button type="button" class="mail-inbox-toolbar__btn" data-mail-refresh aria-label="Refresh">
            <?= mail_icon('refresh', '', 20) ?>
        </button>

        <div class="mail-inbox-toolbar__bulk" data-mail-bulk-actions hidden>
            <?php foreach ($toolbarActions as $item): ?>
                <button
                    type="submit"
                    class="mail-inbox-toolbar__btn"
                    name="action"
                    value="<?= mail_h($item['action']) ?>"
                    aria-label="<?= mail_h($item['label']) ?>"
                    title="<?= mail_h($item['label']) ?>"
                >
                    <?= mail_icon($item['icon'], '', 20) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="mail-inbox-toolbar__more" data-mail-toolbar-more>
            <button
                type="button"
                class="mail-inbox-toolbar__btn"
                data-mail-more-trigger
                aria-label="More actions"
                aria-haspopup="menu"
                aria-expanded="false"
            >
                <?= mail_icon('more-vertical', '', 20) ?>
            </button>
            <div class="mail-inbox-menu" data-mail-more-menu hidden role="menu">
                <?php foreach ($toolbarActions as $item): ?>
                    <button
                        type="submit"
                        class="mail-inbox-menu__item"
                        name="action"
                        value="<?= mail_h($item['action']) ?>"
                        role="menuitem"
                    >
                        <?= mail_icon($item['icon'], 'mail-inbox-menu__icon', 18) ?>
                        <span><?= mail_h($item['label']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="mail-inbox-toolbar__right">
        <span class="mail-inbox-toolbar__range" data-mail-total><?= mail_h($rangeLabel) ?></span>
        <?php if ($totalPages > 1): ?>
            <div class="mail-inbox-toolbar__pager">
                <?php if ($page > 1): ?>
                    <a class="mail-inbox-toolbar__pager-btn" href="<?= mail_h($baseListUrl . '&page=' . ($page - 1)) ?>" aria-label="Previous page">
                        <?= mail_icon('back', '', 18) ?>
                    </a>
                <?php else: ?>
                    <span class="mail-inbox-toolbar__pager-btn is-disabled" aria-hidden="true">
                        <?= mail_icon('back', '', 18) ?>
                    </span>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="mail-inbox-toolbar__pager-btn" href="<?= mail_h($baseListUrl . '&page=' . ($page + 1)) ?>" aria-label="Next page">
                        <?= mail_icon('forward', '', 18) ?>
                    </a>
                <?php else: ?>
                    <span class="mail-inbox-toolbar__pager-btn is-disabled" aria-hidden="true">
                        <?= mail_icon('forward', '', 18) ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
