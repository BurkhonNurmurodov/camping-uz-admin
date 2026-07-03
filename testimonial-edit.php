<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';

$id = (int) input('id', 0);
$t  = $id ? db_one('SELECT * FROM testimonials WHERE id=?', [$id]) : null;
if ($id && !$t) { flash('error', 'Not found.'); redirect('testimonials'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim((string) input('author_name', ''));
    $cEn  = sanitize_html((string) input('comment_en', ''));
    $cRu  = sanitize_html((string) input('comment_ru', ''));
    $vis  = input('is_visible') ? 1 : 0;

    if ($name === '') {
        flash('error', 'Author name is required.');
    } else {
        $avatar = $t['avatar'] ?? null;
        if (input('remove_avatar')) { delete_upload($avatar); $avatar = null; }
        elseif ($async = input('async_avatar')) { delete_upload($avatar); $avatar = $async; }
        elseif (!empty($_FILES['avatar']['name'])) {
            [$ok, $res] = save_image($_FILES['avatar'], 'avatars', 4);
            if ($ok) { delete_upload($avatar); $avatar = $res; } else { flash('error', 'Avatar: ' . $res); }
        }
        if ($t) {
            db_run('UPDATE testimonials SET author_name=?, avatar=?, comment_en_html=?, comment_ru_html=?, is_visible=? WHERE id=?',
                [$name, $avatar, $cEn, $cRu, $vis, $id]);
        } else {
            db_run('INSERT INTO testimonials (author_name, avatar, comment_en_html, comment_ru_html, is_visible) VALUES (?,?,?,?,?)',
                [$name, $avatar, $cEn, $cRu, $vis]);
        }
        flash('success', 'Testimonial saved.');
        redirect('testimonials');
    }
}

$page = [
    'title' => $t ? 'Edit testimonial' : 'New testimonial',
    'section' => 'Testimonials', 'active' => 'testimonials',
    'vendor_css' => quill_vendor_css(),
];
require __DIR__ . '/partials/head.php';
?>
<form method="post" enctype="multipart/form-data" action="<?= url('testimonial-edit' . ($id ? '?id=' . $id : '')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Comment</h5></div>
                <div class="card-body">
                    <?php lang_tabs('tm', function ($l) use ($t) {
                        editor_field("comment_$l", $t["comment_{$l}_html"] ?? '', 'What the client said…');
                    }); ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Author</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="author_name" class="form-control" value="<?= e($t['author_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3 text-center">
                        <label class="form-label d-block text-start">Avatar <span class="text-muted fs-12">(optional)</span></label>
                        <?php $hasAv = $t && $t['avatar']; ?>
                        <label class="dnd-upload-wrap <?= $hasAv ? 'has-preview' : '' ?>">
                            <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                            <div class="dnd-upload-text">Drag and drop or press to upload</div>
                            <div class="dnd-upload-subtext">JPG, PNG, WebP</div>
                            <input type="file" name="avatar" accept="image/*" id="avInput" data-remove-target="rmAv">
                            <div class="dnd-preview-container">
                                <?php if ($hasAv): ?>
                                    <img src="<?= e(upload_url($t['avatar'])) ?>" class="dnd-preview-img rounded-circle" style="width:84px;height:84px;object-fit:cover;">
                                <?php endif; ?>
                            </div>
                            <div class="dnd-loader">
                                <div class="spinner-border text-primary" role="status"></div>
                            </div>
                        </label>
                        <?php if ($hasAv): ?>
                            <div class="form-check mt-2 text-start d-none">
                                <input class="form-check-input" type="checkbox" name="remove_avatar" id="rmAv" value="1">
                                <label class="form-check-label fs-13" for="rmAv">Remove avatar</label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="is_visible" id="vis" value="1" <?= !$t || $t['is_visible'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="vis">Visible on site</label>
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button class="btn btn-primary"><i class="ri-save-line me-1"></i> Save</button>
                <a href="<?= url('testimonials') ?>" class="btn btn-light">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php
$page['vendor_js'] = quill_vendor_js();
$page['inline_js'] = '
var av=document.getElementById("avInput");
av && av.addEventListener("change",function(){var f=av.files[0];if(!f)return;var p=document.getElementById("avPreview");p.src=URL.createObjectURL(f);p.style.display="";});
';
require __DIR__ . '/partials/foot.php';
?>
