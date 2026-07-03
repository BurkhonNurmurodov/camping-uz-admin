<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) input('id');
    if (input('action') === 'delete') {
        $t = db_one('SELECT avatar FROM testimonials WHERE id=?', [$id]);
        if ($t) { delete_upload($t['avatar']); db_run('DELETE FROM testimonials WHERE id=?', [$id]); flash('success', 'Testimonial deleted.'); }
    } elseif (input('action') === 'toggle') {
        db_run('UPDATE testimonials SET is_visible = 1 - is_visible WHERE id=?', [$id]);
    }
    redirect('testimonials');
}

$items = db_all('SELECT * FROM testimonials ORDER BY sort_order, created_at DESC');

$page = ['title' => 'Testimonials', 'section' => 'Content', 'active' => 'testimonials'];
require __DIR__ . '/partials/head.php';
?>
<div class="d-flex align-items-center mb-3">
    <p class="text-muted mb-0">Client opinions shown in the “Our clients about us” carousel.</p>
    <a href="<?= url('testimonial-edit') ?>" class="btn btn-primary ms-auto"><i class="ri-add-line me-1"></i> New testimonial</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$items): ?>
            <div class="text-center text-muted py-5"><i class="ri-chat-quote-line fs-1 d-block mb-2"></i>No testimonials yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Author</th><th>Comment</th><th class="text-center">Visible</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $t): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($t['avatar']): ?>
                                        <img src="<?= e(upload_url($t['avatar'])) ?>" class="rounded-circle" style="width:38px;height:38px;object-fit:cover">
                                    <?php else: ?>
                                        <span class="avatar-sm d-flex align-items-center justify-content-center rounded-circle bg-secondary-subtle text-secondary"><i class="ri-user-line"></i></span>
                                    <?php endif; ?>
                                    <span class="fw-semibold"><?= e($t['author_name']) ?></span>
                                </div>
                            </td>
                            <td class="text-muted fs-13"><?= e(html_excerpt($t['comment_en_html'] ?: $t['comment_ru_html'], 80)) ?></td>
                            <td class="text-center">
                                <form method="post" action="testimonials" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button class="btn btn-sm <?= $t['is_visible'] ? 'btn-success' : 'btn-light text-muted' ?>">
                                        <i class="ri-<?= $t['is_visible'] ? 'eye-line' : 'eye-off-line' ?>"></i>
                                    </button>
                                </form>
                            </td>
                            <td class="text-end">
                                <a href="<?= url('testimonial-edit/' . (int) $t['id']) ?>" class="btn btn-sm btn-light"><i class="ri-pencil-line"></i></a>
                                <form method="post" action="testimonials" class="d-inline js-delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button class="btn btn-sm btn-light text-danger"><i class="ri-delete-bin-line"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$page['vendor_js'] = ['libs/sweetalert2/sweetalert2.all.min.js'];
$page['inline_js'] = <<<JS
document.querySelectorAll('form.js-delete').forEach(function(f){
  f.addEventListener('submit', function(e){ e.preventDefault();
    Swal.fire({title:'Delete this testimonial?', icon:'warning', showCancelButton:true, confirmButtonText:'Delete', confirmButtonColor:'#d33'})
      .then(function(r){ if(r.isConfirmed) f.submit(); }); });
});
JS;
require __DIR__ . '/partials/foot.php';
?>
