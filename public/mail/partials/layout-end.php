        </div><!-- /.mail-content -->
    </div><!-- /.mail-main -->
</div><!-- /.mail-shell -->

<?php if ($includeCompose): ?>
    <?php require __DIR__ . '/compose-panel.php'; ?>
<?php endif; ?>

<script src="<?= mail_h(mail_asset('js/mail-dropdown.js?v=1')) ?>" defer></script>
<script src="<?= mail_h(mail_asset('js/mail-modal.js?v=1')) ?>" defer></script>
<script src="<?= mail_h(mail_asset('js/mail.js?v=8')) ?>" defer></script>
</body>
</html>
