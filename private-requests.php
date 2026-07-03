<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) input('id');
    if (input('action') === 'toggle') {
        db_run("UPDATE private_tour_requests SET status = IF(status='new','handled','new') WHERE id=?", [$id]);
    } elseif (input('action') === 'delete') {
        db_run('DELETE FROM private_tour_requests WHERE id=?', [$id]);
        flash('success', 'Request deleted.');
    }
    redirect('private-requests.php' . (input('filter') ? '?filter=' . urlencode((string) input('filter')) : ''));
}

$filter = input('filter') === 'new' ? 'new' : (input('filter') === 'handled' ? 'handled' : '');
$where  = $filter ? "WHERE status = " . db()->quote($filter) : '';
$requests = db_all("SELECT * FROM private_tour_requests $where ORDER BY created_at DESC");

$page = ['title' => 'Private Tour Requests', 'section' => 'Inbox', 'active' => 'private-requests'];
require __DIR__ . '/partials/head.php';
?>

<ul class="nav nav-pills mb-3">
    <?php foreach (['' => 'All', 'new' => 'New', 'handled' => 'Handled'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link <?= (string) $filter === $k ? 'active' : '' ?>" href="<?= url('private-requests' . ($k ? '?filter=' . $k : '')) ?>"><?= $l ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if (!$requests): ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><i class="ri-vip-diamond-line fs-1 d-block mb-2"></i>No private requests<?= $filter ? ' in this view' : ' yet' ?>.</div></div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($requests as $r): $isNew = $r['status'] === 'new'; ?>
            <div class="col-12 col-xl-6">
                <div class="card mb-0 h-100 <?= $isNew ? 'border-warning' : '' ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-start mb-3">
                            <div>
                                <span class="badge bg-<?= $isNew ? 'warning text-dark' : 'light text-muted' ?> mb-1"><?= $isNew ? 'New' : 'Handled' ?></span>
                                <div class="fw-semibold fs-16"><?= e($r['name']) ?></div>
                                <div class="fs-12 text-muted"><?= e(date('M j, Y H:i', strtotime($r['created_at']))) ?></div>
                            </div>
                            <div class="ms-auto d-flex gap-1">
                                <form method="post" action="private-requests" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="filter" value="<?= e((string) $filter) ?>">
                                    <button class="btn btn-sm btn-light" title="Mark <?= $isNew ? 'handled' : 'new' ?>"><i class="ri-check-double-line"></i></button>
                                </form>
                                <form method="post" action="private-requests" class="d-inline js-delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-light text-danger"><i class="ri-delete-bin-line"></i></button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="bg-light rounded p-3 mb-3">
                            <div class="row g-2 text-sm">
                                <div class="col-6">
                                    <div class="text-muted fs-12 text-uppercase fw-semibold">Email</div>
                                    <div><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted fs-12 text-uppercase fw-semibold">WhatsApp</div>
                                    <div><?= $r['whatsapp'] ? e($r['whatsapp']) : '<span class="text-muted">—</span>' ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted fs-12 text-uppercase fw-semibold">Group Size</div>
                                    <div><?= $r['group_size'] ? e($r['group_size']) : '<span class="text-muted">—</span>' ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted fs-12 text-uppercase fw-semibold">Dates</div>
                                    <div><?= $r['dates_info'] ? e($r['dates_info']) : '<span class="text-muted">—</span>' ?></div>
                                </div>
                            </div>
                        </div>

                        <?php 
                        $dests = json_decode((string) $r['destinations'], true);
                        if (!empty($dests) && is_array($dests)): 
                        ?>
                            <div class="mb-3">
                                <div class="text-muted fs-12 text-uppercase fw-semibold mb-1">Destinations / Vibes</div>
                                <?php foreach ($dests as $d): ?>
                                    <span class="badge bg-secondary me-1"><?= e($d) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($r['notes']): ?>
                            <div class="text-muted fs-12 text-uppercase fw-semibold mb-1">Notes</div>
                            <div class="p-2 border rounded bg-white text-dark" style="white-space: pre-wrap; font-size: 13px;"><?= e($r['notes']) ?></div>
                        <?php endif; ?>
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
    Swal.fire({title:'Delete this request?', icon:'warning', showCancelButton:true, confirmButtonText:'Delete', confirmButtonColor:'#d33'})
      .then(function(r){ if(r.isConfirmed) f.submit(); }); });
});
JS;
require __DIR__ . '/partials/foot.php';
?>
