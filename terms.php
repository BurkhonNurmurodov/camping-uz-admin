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
    
    foreach (['en', 'ru'] as $l) {
        if (input("remove_terms_pdf_$l") == '1') {
            delete_upload(setting("terms_pdf_$l"));
            set_setting("terms_pdf_$l", '');
        } elseif (!empty($_FILES["terms_pdf_$l"]['name'])) {
            [$ok, $res] = save_pdf($_FILES["terms_pdf_$l"], 'docs', 30);
            if ($ok) {
                delete_upload(setting("terms_pdf_$l"));
                set_setting("terms_pdf_$l", $res);
            } else {
                flash('error', "PDF ($l) upload failed: " . $res);
            }
        }
    }
    
    if (!isset($_SESSION['flash']['error'])) {
        flash('success', 'Booking Terms saved successfully.');
    }
    redirect('terms');
}

$page = ['title' => 'Booking Terms', 'section' => 'Content', 'active' => 'terms', 'vendor_css' => quill_vendor_css()];
require __DIR__ . '/partials/head.php';
?>
<form method="post" action="terms" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="card-title mb-0"><i class="ri-file-text-line text-primary me-2"></i>Booking Terms & Conditions Content</h5>
            <a href="<?= e(str_replace('/admin', '', BASE_PATH)) ?>/terms" target="_blank" class="btn btn-sm btn-soft-secondary">
                <i class="ri-external-link-line me-1"></i>View Live
            </a>
        </div>
        <div class="card-body">
            <p class="text-muted fs-13 mb-4">Format text (bold, italic, lists, links, headers) to specify booking rules and policies. You can also attach an official PDF document for visitors to download.</p>
            <?php lang_tabs('terms', function ($l) use ($terms) { ?>
                <div class="mb-3">
                    <label class="form-label fw-medium">Title (<?= strtoupper($l) ?>)</label>
                    <input type="text" name="title_<?= $l ?>" class="form-control" value="<?= e($terms["title_$l"] ?? '') ?>" required>
                </div>
                
                <div class="mb-4 p-3 bg-light rounded border">
                    <label class="form-label fw-medium d-flex align-items-center mb-2">
                        <i class="ri-file-pdf-2-line text-danger fs-18 me-2"></i>Official PDF Document (<?= strtoupper($l) ?>)
                    </label>
                    <?php if ($curPdf = setting("terms_pdf_$l")): ?>
                        <div class="d-flex align-items-center justify-content-between p-2 mb-3 bg-white rounded border shadow-sm">
                            <a href="<?= e(upload_url($curPdf)) ?>" target="_blank" class="text-primary text-truncate d-block fw-medium" style="max-width: 70%;">
                                <i class="ri-external-link-line me-1"></i><?= e(basename($curPdf)) ?>
                            </a>
                            <div class="form-check form-check-danger mb-0">
                                <input class="form-check-input" type="checkbox" name="remove_terms_pdf_<?= $l ?>" value="1" id="rem_terms_pdf_<?= $l ?>">
                                <label class="form-check-label text-danger fs-13 cursor-pointer" for="rem_terms_pdf_<?= $l ?>">Remove PDF</label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="terms_pdf_<?= $l ?>" accept="application/pdf,.pdf" class="form-control form-control-sm">
                    <small class="text-muted d-block mt-1">Optional (Max 30MB). Uploading a PDF displays a download banner on the live page.</small>
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
