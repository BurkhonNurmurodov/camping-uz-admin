<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'delete') {
    csrf_verify();
    $id = (int) input('id');
    $g = db_one('SELECT image FROM guides WHERE id = ?', [$id]);
    if ($g) {
        foreach (db_all('SELECT custom_icon FROM guide_socials WHERE guide_id = ?', [$id]) as $s) {
            delete_upload($s['custom_icon']);
        }
        delete_upload($g['image']);
        db_run('DELETE FROM guides WHERE id = ?', [$id]); // socials cascade
        flash('success', 'Guide deleted.');
    }
    redirect('guides');
}

$guides = db_all(
    'SELECT g.*, (SELECT COUNT(*) FROM guide_socials s WHERE s.guide_id = g.id) AS socials,
            (SELECT COUNT(*) FROM tour_guides tg WHERE tg.guide_id = g.id) AS tours
       FROM guides g ORDER BY g.sort_order, g.full_name'
);

$page = ['title' => 'Guides', 'section' => 'Content', 'active' => 'guides'];
require __DIR__ . '/partials/head.php';
?>
<div class="d-flex align-items-center mb-3">
    <p class="text-muted mb-0">Guides are created here and attached to tours.</p>
    <a href="<?= url('guide-edit') ?>" class="btn btn-primary ms-auto"><i class="ri-add-line me-1"></i> New guide</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$guides): ?>
            <div class="text-center text-muted py-5">
                <i class="ri-user-star-line fs-1 d-block mb-2"></i>
                No guides yet. <a href="<?= url('guide-edit') ?>">Create the first one</a>.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Guide</th>
                            <th class="text-center">Socials</th>
                            <th class="text-center">Tours</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($guides as $g): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($g['image']): ?>
                                        <img src="<?= e(upload_url($g['image'])) ?>" class="rounded" style="width:44px;height:44px;object-fit:cover">
                                    <?php else: ?>
                                        <span class="avatar-md d-flex align-items-center justify-content-center rounded bg-secondary-subtle text-secondary"><i class="ri-user-line"></i></span>
                                    <?php endif; ?>
                                    <div>
                                        <span class="fw-semibold"><?= e($g['full_name']) ?></span>
                                        <div class="fs-12 text-muted"><?= e(html_excerpt($g['bio_en'], 60)) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center"><span class="badge bg-light text-dark"><?= (int) $g['socials'] ?></span></td>
                            <td class="text-center"><span class="badge bg-light text-dark"><?= (int) $g['tours'] ?></span></td>
                            <td class="text-end">
                                <a href="<?= url('guide-edit/' . (int) $g['id']) ?>" class="btn btn-sm btn-light"><i class="ri-pencil-line"></i></a>
                                <form method="post" action="guides" class="d-inline js-delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
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
  f.addEventListener('submit', function(e){
    e.preventDefault();
    Swal.fire({title:'Delete this guide?', text:'This cannot be undone.', icon:'warning',
      showCancelButton:true, confirmButtonText:'Delete', confirmButtonColor:'#d33'})
      .then(function(r){ if(r.isConfirmed) f.submit(); });
  });
});
JS;
require __DIR__ . '/partials/foot.php';
?>
