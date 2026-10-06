<?php

declare(strict_types=1);

$toolbarId = $toolbarId ?? 'mail-editor-toolbar';
$editorId = $editorId ?? 'mail-compose-editor';
?>
<div class="mail-toolbar-editor" id="<?= mail_h($toolbarId) ?>" data-mail-toolbar="<?= mail_h($editorId) ?>" role="toolbar" aria-label="Formatting">
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="undo" title="Undo"><?= mail_icon('undo', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="redo" title="Redo"><?= mail_icon('redo', '', 20) ?></button>

    <span class="mail-toolbar-editor__divider" aria-hidden="true"></span>

    <select class="mail-toolbar-editor__select" data-font-name aria-label="Font family">
        <option value="Arial, Helvetica, sans-serif">Sans Serif</option>
        <option value="Georgia, serif">Serif</option>
        <option value="'Courier New', Courier, monospace">Fixed Width</option>
        <option value="'Google Sans', Inter, sans-serif">Google Sans</option>
        <option value="Inter, sans-serif">Inter</option>
        <option value="'Times New Roman', Times, serif">Times</option>
    </select>

    <select class="mail-toolbar-editor__select" data-font-size aria-label="Font size">
        <option value="2">Small</option>
        <option value="3" selected>Normal</option>
        <option value="5">Large</option>
        <option value="6">Huge</option>
    </select>

    <span class="mail-toolbar-editor__divider" aria-hidden="true"></span>

    <button type="button" class="mail-toolbar-editor__btn" data-cmd="bold" title="Bold"><?= mail_icon('bold', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="italic" title="Italic"><?= mail_icon('italic', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="underline" title="Underline"><?= mail_icon('underline', '', 20) ?></button>

    <span class="mail-toolbar-editor__swatches" aria-label="Text colours">
        <?php foreach (['#14161c', '#dc2626', '#9f1239', '#b45309', '#0f3d2e', '#2563eb', '#7c3aed', '#64748b'] as $color): ?>
            <button type="button" class="mail-toolbar-editor__swatch" data-fore-color="<?= mail_h($color) ?>" style="background: <?= mail_h($color) ?>" aria-label="Text colour"></button>
        <?php endforeach; ?>
    </span>

    <span class="mail-toolbar-editor__swatches" aria-label="Highlight colours">
        <?php foreach (['#fef08a', '#fde68a', '#bbf7d0', '#bfdbfe', '#fecaca', '#e8edf2'] as $color): ?>
            <button type="button" class="mail-toolbar-editor__swatch" data-hilite-color="<?= mail_h($color) ?>" style="background: <?= mail_h($color) ?>" aria-label="Highlight"></button>
        <?php endforeach; ?>
    </span>

    <span class="mail-toolbar-editor__divider" aria-hidden="true"></span>

    <button type="button" class="mail-toolbar-editor__btn" data-cmd="justifyLeft" title="Align left"><?= mail_icon('align-left', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="justifyCenter" title="Align center"><?= mail_icon('align-center', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="justifyRight" title="Align right"><?= mail_icon('align-right', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="justifyFull" title="Justify"><?= mail_icon('align-justify', '', 20) ?></button>

    <button type="button" class="mail-toolbar-editor__btn" data-cmd="insertOrderedList" title="Numbered list"><?= mail_icon('list-ordered', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="insertUnorderedList" title="Bulleted list"><?= mail_icon('list-unordered', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="indent" title="Indent"><?= mail_icon('indent', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="outdent" title="Outdent"><?= mail_icon('outdent', '', 20) ?></button>

    <button type="button" class="mail-toolbar-editor__btn" data-mail-insert-image title="Insert image"><?= mail_icon('image', '', 20) ?></button>
    <input type="file" accept="image/*" hidden data-mail-image-input>

    <button type="button" class="mail-toolbar-editor__btn" data-cmd="strikeThrough" title="Strikethrough"><?= mail_icon('strikethrough', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="formatBlock" data-cmd-value="blockquote" title="Quote"><?= mail_icon('quote', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="unlink" title="Remove link"><?= mail_icon('unlink', '', 20) ?></button>
    <button type="button" class="mail-toolbar-editor__btn" data-cmd="removeFormat" title="Clear formatting"><?= mail_icon('remove-format', '', 20) ?></button>
</div>
