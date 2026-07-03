<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';

$about = db_one("SELECT * FROM pages WHERE `key`='about'");
if (!$about) {
    db_run("INSERT INTO pages (`key`, title_en, title_ru) VALUES ('about','About us','О нас')");
    $about = db_one("SELECT * FROM pages WHERE `key`='about'");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    db_run(
        "UPDATE pages SET title_en=?, title_ru=?, body_en_html=?, body_ru_html=? WHERE `key`='about'",
        [
            trim((string) input('title_en', 'About us')),
            trim((string) input('title_ru', 'О нас')),
            sanitize_html((string) input('body_en', '')),
            sanitize_html((string) input('body_ru', '')),
        ]
    );
    flash('success', 'About page saved.');
    redirect('about');
}

$page = ['title' => 'About page', 'section' => 'Content', 'active' => 'about', 'vendor_css' => quill_vendor_css()];
require __DIR__ . '/partials/head.php';
?>
<form method="post" action="about">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">About us content</h5></div>
        <div class="card-body">
            <p class="text-muted fs-13">Format text (bold, italic, underline, strike, quote, code, spoiler) and insert images/videos with adjustable size and position.</p>
            <?php lang_tabs('about', function ($l) use ($about) { ?>
                <div class="mb-3">
                    <label class="form-label">Title (<?= strtoupper($l) ?>)</label>
                    <input type="text" name="title_<?= $l ?>" class="form-control" value="<?= e($about["title_$l"] ?? '') ?>">
                </div>
                <label class="form-label">Body (<?= strtoupper($l) ?>)</label>
                <?php editor_field("body_$l", $about["body_{$l}_html"] ?? '', 'Tell visitors who you are…'); ?>
            <?php }); ?>
        </div>
    </div>
    <div class="mb-3">
        <button class="btn btn-primary"><i class="ri-save-line me-1"></i> Save About page</button>
    </div>
</form>

<?php
$page['vendor_js'] = quill_vendor_js();
require __DIR__ . '/partials/foot.php';
?>
