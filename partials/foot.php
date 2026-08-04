<?php
/**
 * Closes the app shell and loads scripts.
 *
 * $page['vendor_js'] => string[]  srcs relative to /assets
 * $page['inline_js'] => string    raw JS appended last
 */
$A = BASE_PATH . '/assets';
$page['vendor_js'] = $page['vendor_js'] ?? [];
?>
        </main>

        <footer class="app-footer">
            <div class="row-flex row-flex--wrap">
                <span>&copy; <?= date('Y') ?> Silk Naviora</span>
                <span class="push-end hide-sm-down">Admin panel</span>
            </div>
        </footer>
    </div><!-- /.main -->
</div><!-- /.app -->

<div class="toast-region" role="status" aria-live="polite"></div>

<script src="<?= $A ?>/js/admin.js"></script>

<?php foreach ($page['vendor_js'] as $src): ?>
    <script src="<?= $A ?>/<?= e($src) ?>"></script>
<?php endforeach; ?>

<?php if (!empty($page['inline_js'])): ?>
    <script><?= $page['inline_js'] ?></script>
<?php endif; ?>
</body>
</html>
