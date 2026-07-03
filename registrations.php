<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) input('id');
    if (input('action') === 'toggle') {
        db_run("UPDATE registration_groups SET status = IF(status='new','handled','new') WHERE id=?", [$id]);
    } elseif (input('action') === 'delete') {
        db_run('DELETE FROM registration_groups WHERE id=?', [$id]); // people cascade
        flash('success', 'Registration deleted.');
    }
    redirect('registrations.php' . (input('filter') ? '?filter=' . urlencode((string) input('filter')) : ''));
}

$filter = input('filter') === 'new' ? 'new' : (input('filter') === 'handled' ? 'handled' : '');
$where  = $filter ? "WHERE g.status = " . db()->quote($filter) : '';
$groups = db_all(
    "SELECT g.*, t.title_en AS tour_en, t.title_ru AS tour_ru
       FROM registration_groups g LEFT JOIN tours t ON t.id = g.tour_id
       $where ORDER BY g.created_at DESC"
);
$people = [];
if ($groups) {
    $ids = implode(',', array_map(static fn($g) => (int) $g['id'], $groups));
    foreach (db_all("SELECT * FROM registration_people WHERE group_id IN ($ids) ORDER BY is_primary DESC, id") as $p) {
        $people[$p['group_id']][] = $p;
    }
}

$page = ['title' => 'Registrations', 'section' => 'Inbox', 'active' => 'registrations'];
require __DIR__ . '/partials/head.php';
?>
<ul class="nav nav-pills mb-3">
    <?php foreach (['' => 'All', 'new' => 'New', 'handled' => 'Handled'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link <?= (string) $filter === $k ? 'active' : '' ?>" href="<?= url('registrations' . ($k ? '?filter=' . $k : '')) ?>"><?= $l ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if (!$groups): ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><i class="ri-group-line fs-1 d-block mb-2"></i>No registrations<?= $filter ? ' in this view' : ' yet' ?>.</div></div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($groups as $g): $ppl = $people[$g['id']] ?? []; $isNew = $g['status'] === 'new'; ?>
            <div class="col-12 col-xl-6">
                <div class="card mb-0 h-100 <?= $isNew ? 'border-primary' : '' ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-start mb-2">
                            <div>
                                <span class="badge bg-<?= $isNew ? 'primary' : 'light text-muted' ?> mb-1"><?= $isNew ? 'New' : 'Handled' ?></span>
                                <div class="fw-semibold"><?= e($g['tour_en'] ?: $g['tour_ru'] ?: 'General interest') ?></div>
                                <div class="fs-12 text-muted"><?= count($ppl) ?> <?= count($ppl) === 1 ? 'person' : 'people' ?> · <?= e(date('M j, Y H:i', strtotime($g['created_at']))) ?></div>
                            </div>
                            <div class="ms-auto d-flex gap-1">
                                <form method="post" action="registrations" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                                    <input type="hidden" name="filter" value="<?= e((string) $filter) ?>">
                                    <button class="btn btn-sm btn-light" title="Mark <?= $isNew ? 'handled' : 'new' ?>"><i class="ri-check-double-line"></i></button>
                                </form>
                                <form method="post" action="registrations" class="d-inline js-delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                                    <button class="btn btn-sm btn-light text-danger"><i class="ri-delete-bin-line"></i></button>
                                </form>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <tbody>
                                <?php foreach ($ppl as $p): ?>
                                    <tr>
                                        <td><?= e($p['full_name']) ?> <?= $p['is_primary'] ? '<span class="badge bg-light text-muted">lead</span>' : '' ?></td>
                                        <td><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a></td>
                                        <td><?= $p['whatsapp_phone'] ? e($p['whatsapp_phone']) : '<span class="text-muted">—</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$page['vendor_js'] = ['libs/sweetalert2/sweetalert2.all.min.js'];
$page['inline_js'] = <<<JS
document.querySelectorAll('form.js-delete').forEach(function(f){
  f.addEventListener('submit', function(e){ e.preventDefault();
    Swal.fire({title:'Delete this registration?', icon:'warning', showCancelButton:true, confirmButtonText:'Delete', confirmButtonColor:'#d33'})
      .then(function(r){ if(r.isConfirmed) f.submit(); }); });
});
JS;
require __DIR__ . '/partials/foot.php';
?>
