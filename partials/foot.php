<?php
/**
 * Close the layout shell + scripts. Expects optional:
 *   $page['vendor_js'] => string[]  (srcs relative to admin/assets)
 *   $page['inline_js'] => string    (raw JS appended at the end)
 */
$A = BASE_PATH . '/assets';
$page['vendor_js'] = $page['vendor_js'] ?? [];
?>
        </div><!-- /.container-fluid -->
    </main>

    <footer class="app-footer">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center py-3 fs-13 text-muted">
                <span>&copy; <?= date('Y') ?> Camping Uzbekistan</span>
                <span class="d-none d-md-block">Admin panel</span>
            </div>
        </div>
    </footer>
</div><!-- /#layout-wrapper -->

<!-- scroll to top -->
<div class="progress-wrap d-flex align-items-center justify-content-center cursor-pointer h-40px w-40px position-fixed" id="progress-scroll">
    <svg class="progress-circle w-100 h-100 position-absolute" viewBox="0 0 100 100">
        <circle cx="50" cy="50" r="45" class="progress"></circle>
    </svg>
    <i class="ri-arrow-up-line fs-16 z-1 position-relative text-primary"></i>
</div>
<div id="sidebar-backdrop"></div>

<!-- core scripts -->
<script src="<?= $A ?>/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= $A ?>/libs/simplebar/simplebar.min.js"></script>
<script src="<?= $A ?>/js/scroll-top.init.js"></script>
<script src="<?= $A ?>/js/admin-extra.js"></script>

<?php foreach ($page['vendor_js'] as $src): ?>
    <script src="<?= $A ?>/<?= e($src) ?>"></script>
<?php endforeach; ?>

<?php if (!empty($page['inline_js'])): ?>
    <script><?= $page['inline_js'] ?></script>
<?php endif; ?>
</body>
</html>
