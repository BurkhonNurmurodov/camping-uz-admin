<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';

$terms = db_one("SELECT * FROM pages WHERE `key`='terms'");
if (!$terms) {
    db_run(
        "INSERT INTO pages (`key`, title_en, title_ru, body_en_html, body_ru_html) VALUES (?, ?, ?, ?, ?)",
        [
            'terms',
            'Booking Terms & Conditions',
            'Условия бронирования',
            '<p>Our booking terms and conditions will be published here soon.</p>',
            '<p>Условия бронирования скоро появятся здесь.</p>'
        ]
    );
    $terms = db_one("SELECT * FROM pages WHERE `key`='terms'");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    db_run(
        "UPDATE pages SET title_en=?, title_ru=?, body_en_html=?, body_ru_html=? WHERE `key`='terms'",
        [
            trim((string) input('title_en', 'Booking Terms & Conditions')),
            trim((string) input('title_ru', 'Условия бронирования')),
            sanitize_html((string) input('body_en', '')),
            sanitize_html((string) input('body_ru', '')),
        ]
    );
    flash('success', 'Booking Terms saved successfully.');
    redirect('terms');
}

$page = ['title' => 'Booking Terms', 'section' => 'Content', 'active' => 'terms', 'vendor_css' => quill_vendor_css()];
require __DIR__ . '/partials/head.php';
?>
<form method="post" action="terms">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="card-title mb-0"><i class="ri-file-text-line text-primary me-2"></i>Booking Terms & Conditions Content</h5>
            <a href="<?= e(str_replace('/admin', '', BASE_PATH)) ?>/terms" target="_blank" class="btn btn-sm btn-soft-secondary">
                <i class="ri-external-link-line me-1"></i>View Live
            </a>
        </div>
        <div class="card-body">
            <p class="text-muted fs-13 mb-4">Format text (bold, italic, lists, links, headers) to specify booking rules, cancellation policies, and liability terms.</p>
            <?php lang_tabs('terms', function ($l) use ($terms) { ?>
                <div class="mb-3">
                    <label class="form-label fw-medium">Title (<?= strtoupper($l) ?>)</label>
                    <input type="text" name="title_<?= $l ?>" class="form-control" value="<?= e($terms["title_$l"] ?? '') ?>" required>
                </div>
                <label class="form-label fw-medium">Body Content (<?= strtoupper($l) ?>)</label>
                <?php editor_field("body_$l", $terms["body_{$l}_html"] ?? '', 'Enter booking procedures, payment schedules, refund & cancellation policies…'); ?>
            <?php }); ?>
        </div>
    </div>
    <div class="mb-3">
        <button type="submit" class="btn btn-primary px-4"><i class="ri-save-line me-1"></i> Save Booking Terms</button>
    </div>
</form>

<?php
$page['vendor_js'] = quill_vendor_js();
require __DIR__ . '/partials/foot.php';
?>
