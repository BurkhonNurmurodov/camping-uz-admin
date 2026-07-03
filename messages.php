<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) input('id');
    if (input('action') === 'toggle') {
        db_run("UPDATE contact_messages SET status = IF(status='unanswered','answered','unanswered') WHERE id=?", [$id]);
    } elseif (input('action') === 'delete') {
        db_run('DELETE FROM contact_messages WHERE id=?', [$id]);
        flash('success', 'Message deleted.');
    }
    redirect('messages.php' . (input('filter') ? '?filter=' . urlencode((string) input('filter')) : ''));
}

$filter = input('filter') === 'unanswered' ? 'unanswered' : (input('filter') === 'answered' ? 'answered' : '');
$where  = $filter ? "WHERE status = " . db()->quote($filter) : '';
$msgs   = db_all("SELECT * FROM contact_messages $where ORDER BY created_at DESC");

$page = ['title' => 'Messages', 'section' => 'Inbox', 'active' => 'messages'];
require __DIR__ . '/partials/head.php';
?>
<ul class="nav nav-pills mb-3">
    <?php foreach (['' => 'All', 'unanswered' => 'Unanswered', 'answered' => 'Answered'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link <?= (string) $filter === $k ? 'active' : '' ?>" href="<?= url('messages' . ($k ? '?filter=' . $k : '')) ?>"><?= $l ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$msgs): ?>
            <div class="text-center text-muted py-5"><i class="ri-mail-line fs-1 d-block mb-2"></i>No messages<?= $filter ? ' in this view' : ' yet' ?>.</div>
        <?php else: ?>
            <div class="accordion accordion-flush" id="msgAcc">
                <?php foreach ($msgs as $m): $un = $m['status'] === 'unanswered'; ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#m<?= (int) $m['id'] ?>">
                                <span class="me-2 badge bg-<?= $un ? 'danger' : 'success' ?>-subtle text-<?= $un ? 'danger' : 'success' ?>"><?= $un ? 'Unanswered' : 'Answered' ?></span>
                                <span class="fw-semibold me-2"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></span>
                                <span class="text-muted fs-13 me-2"><?= e($m['topic'] ?: 'No topic') ?></span>
                                <span class="text-muted fs-12 ms-auto me-3"><?= e(date('M j, H:i', strtotime($m['created_at']))) ?></span>
                            </button>
                        </h2>
                        <div id="m<?= (int) $m['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#msgAcc">
                            <div class="accordion-body">
                                <p class="mb-2"><a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a></p>
                                <p class="mb-3" style="white-space:pre-wrap"><?= e($m['message']) ?></p>
                                <div class="d-flex gap-2">
                                    <a href="mailto:<?= e($m['email']) ?>?subject=<?= e(rawurlencode('Re: ' . ($m['topic'] ?: 'Your message'))) ?>" class="btn btn-sm btn-primary"><i class="ri-reply-line me-1"></i> Reply</a>
                                    <form method="post" action="<?= url('messages') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                        <input type="hidden" name="filter" value="<?= e((string) $filter) ?>">
                                        <button class="btn btn-sm btn-light">Mark as <?= $un ? 'answered' : 'unanswered' ?></button>
                                    </form>
                                    <form method="post" action="<?= url('messages') ?>" class="d-inline js-delete ms-auto">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                        <button class="btn btn-sm btn-light text-danger"><i class="ri-delete-bin-line"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$page['vendor_js'] = ['libs/sweetalert2/sweetalert2.all.min.js'];
$page['inline_js'] = <<<JS
document.querySelectorAll('form.js-delete').forEach(function(f){
  f.addEventListener('submit', function(e){ e.preventDefault();
    Swal.fire({title:'Delete this message?', icon:'warning', showCancelButton:true, confirmButtonText:'Delete', confirmButtonColor:'#d33'})
      .then(function(r){ if(r.isConfirmed) f.submit(); }); });
});
JS;
require __DIR__ . '/partials/foot.php';
?>
