<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';

$privacy = db_one("SELECT * FROM pages WHERE `key`='privacy'");
if (!$privacy) {
    db_run(
        "INSERT INTO pages (`key`, title_en, title_ru, body_en_html, body_ru_html) VALUES (?, ?, ?, ?, ?)",
        [
            'privacy',
            'Privacy Policy',
            'Политика конфиденциальности',
            '<p>Our privacy policy details will be published here soon.</p>',
            '<p>Информация о политике конфиденциальности скоро появится здесь.</p>'
        ]
    );
    $privacy = db_one("SELECT * FROM pages WHERE `key`='privacy'");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    db_run(
        "UPDATE pages SET title_en=?, title_ru=?, body_en_html=?, body_ru_html=? WHERE `key`='privacy'",
        [
            trim((string) input('title_en', 'Privacy Policy')),
            trim((string) input('title_ru', 'Политика конфиденциальности')),
            sanitize_html((string) input('body_en', '')),
            sanitize_html((string) input('body_ru', '')),
        ]
    );
    flash('success', 'Privacy Policy saved successfully.');
    redirect('privacy');
}

$page = ['title' => 'Privacy Policy', 'section' => 'Content', 'active' => 'privacy', 'vendor_css' => quill_vendor_css()];
require __DIR__ . '/partials/head.php';
?>
<form method="post" action="privacy">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="card-title mb-0"><i class="ri-shield-keyhole-line text-primary me-2"></i>Privacy Policy Content</h5>
            <a href="<?= e(str_replace('/admin', '', BASE_PATH)) ?>/privacy" target="_blank" class="btn btn-sm btn-soft-secondary">
                <i class="ri-external-link-line me-1"></i>View Live
            </a>
        </div>
        <div class="card-body">
            <p class="text-muted fs-13 mb-4">Format text (bold, italic, lists, links, headers) and adjust layout for your official Privacy Policy page.</p>
            <?php lang_tabs('privacy', function ($l) use ($privacy) { ?>
                <div class="mb-3">
                    <label class="form-label fw-medium">Title (<?= strtoupper($l) ?>)</label>
                    <input type="text" name="title_<?= $l ?>" class="form-control" value="<?= e($privacy["title_$l"] ?? '') ?>" required>
                </div>
                <label class="form-label fw-medium">Body Content (<?= strtoupper($l) ?>)</label>
                <?php editor_field("body_$l", $privacy["body_{$l}_html"] ?? '', 'Enter privacy policy guidelines, data collection disclosures, and rights…'); ?>
            <?php }); ?>
        </div>
    </div>
    <div class="mb-3">
        <button type="submit" class="btn btn-primary px-4"><i class="ri-save-line me-1"></i> Save Privacy Policy</button>
    </div>
</form>

<?php
$page['vendor_js'] = quill_vendor_js();
require __DIR__ . '/partials/foot.php';
?>
