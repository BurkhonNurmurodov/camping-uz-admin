<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) input('id');
    if (input('action') === 'delete') {
        $t = db_one('SELECT poster FROM tours WHERE id=?', [$id]);
        if ($t) { delete_upload($t['poster']); db_run('DELETE FROM tours WHERE id=?', [$id]); flash('success', 'Tour deleted.'); }
    } elseif (input('action') === 'status') {
        $s = input('status');
        if (in_array($s, ['draft', 'upcoming', 'past'], true)) {
            db_run('UPDATE tours SET status=? WHERE id=?', [$s, $id]);
        }
    }
    redirect('tours');
}

$filter = input('status');
$where  = in_array($filter, ['draft', 'upcoming', 'past'], true) ? 'WHERE status = ' . db()->quote($filter) : '';
$tours = db_all(
    "SELECT t.*,
            (SELECT COUNT(*) FROM tour_guides tg WHERE tg.tour_id=t.id) AS guides,
            (SELECT COUNT(*) FROM registration_groups r WHERE r.tour_id=t.id) AS regs
       FROM tours t $where ORDER BY t.sort_order, t.start_date IS NULL, t.start_date, t.id DESC"
);
$counts = [];
foreach (db_all("SELECT status, COUNT(*) c FROM tours GROUP BY status") as $r) { $counts[$r['status']] = $r['c']; }

$page = ['title' => 'Tours', 'section' => 'Content', 'active' => 'tours'];
require __DIR__ . '/partials/head.php';

$tabs = ['' => 'All', 'upcoming' => 'Upcoming', 'past' => 'Past', 'draft' => 'Draft'];
?>
<div class="d-flex align-items-center mb-3 flex-wrap gap-2">
    <ul class="nav nav-pills">
        <?php foreach ($tabs as $k => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= (string) $filter === $k ? 'active' : '' ?>" href="<?= url('tours' . ($k ? '?status=' . $k : '')) ?>">
                    <?= $label ?>
                    <?php $c = $k === '' ? array_sum($counts) : ($counts[$k] ?? 0); ?>
                    <span class="badge bg-light text-dark ms-1"><?= (int) $c ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <a href="<?= url('tour-edit') ?>" class="btn btn-primary ms-auto"><i class="ri-add-line me-1"></i> New tour</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$tours): ?>
            <div class="text-center text-muted py-5"><i class="ri-route-line fs-1 d-block mb-2"></i>No tours here yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Tour</th><th>Dates</th><th class="text-center">Guides</th><th class="text-center">Regs</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($tours as $t):
                        $badge = ['draft' => 'secondary', 'upcoming' => 'primary', 'past' => 'dark'][$t['status']] ?? 'secondary'; ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($t['poster']): ?>
                                        <img src="<?= e(upload_url($t['poster'])) ?>" style="width:60px;height:45px;object-fit:cover;border-radius:6px">
                                    <?php else: ?>
                                        <span class="d-flex align-items-center justify-content-center bg-secondary-subtle text-secondary rounded" style="width:60px;height:45px"><i class="ri-image-line"></i></span>
                                    <?php endif; ?>
                                    <div>
                                        <span class="fw-semibold"><?= e($t['title_en'] ?: $t['title_ru'] ?: 'Untitled') ?></span>
                                        <div class="fs-12 text-muted">/<?= e($t['slug']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="fs-13"><?= e(format_tour_dates($t['start_date'], $t['end_date']) ?: '—') ?></td>
                            <td class="text-center"><span class="badge bg-light text-dark"><?= (int) $t['guides'] ?></span></td>
                            <td class="text-center"><span class="badge bg-light text-dark"><?= (int) $t['regs'] ?></span></td>
                            <td>
                                <form method="post" action="tours" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <select name="status" class="form-select form-select-sm w-auto d-inline" onchange="this.form.submit()">
                                        <?php foreach (['draft', 'upcoming', 'past'] as $s): ?>
                                            <option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td class="text-end">
                                <a href="<?= url('tour-edit/' . (int) $t['id']) ?>" class="btn btn-sm btn-light"><i class="ri-pencil-line"></i></a>
                                <form method="post" action="tours" class="d-inline js-delete">
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
    Swal.fire({title:'Delete this tour?', text:'Registrations linked to it will be kept but unlinked.', icon:'warning', showCancelButton:true, confirmButtonText:'Delete', confirmButtonColor:'#d33'})
      .then(function(r){ if(r.isConfirmed) f.submit(); }); });
});
JS;
require __DIR__ . '/partials/foot.php';
?>
