<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

$stats = [
    'tours_total'    => (int) db_val("SELECT COUNT(*) FROM tours"),
    'tours_upcoming' => (int) db_val("SELECT COUNT(*) FROM tours WHERE status='upcoming'"),
    'guides'         => (int) db_val("SELECT COUNT(*) FROM guides"),
    'testimonials'   => (int) db_val("SELECT COUNT(*) FROM testimonials"),
];
$recent_regs = db_all(
    "SELECT g.id, g.created_at, g.status,
            (SELECT full_name FROM registration_people p WHERE p.group_id=g.id ORDER BY is_primary DESC, id LIMIT 1) AS lead,
            (SELECT COUNT(*) FROM registration_people p WHERE p.group_id=g.id) AS people,
            t.title_en AS tour
       FROM registration_groups g
       LEFT JOIN tours t ON t.id=g.tour_id
      ORDER BY g.created_at DESC LIMIT 6"
);
$recent_msgs = db_all(
    "SELECT id, first_name, last_name, topic, status, created_at
       FROM contact_messages ORDER BY created_at DESC LIMIT 6"
);

$page = ['title' => t('admin_dashboard'), 'active' => 'dashboard'];
require __DIR__ . '/partials/head.php';

$cards = [
    ['Upcoming tours', $stats['tours_upcoming'], 'ri-route-line',      'primary', 'tours.php'],
    ['New registrations', $nav_new_regs,         'ri-group-line',      'success', 'registrations.php'],
    ['Unanswered messages', $nav_unread_msgs,    'ri-mail-line',       'danger',  'messages.php'],
    ['Guides',          $stats['guides'],         'ri-user-star-line',  'info',    'guides.php'],
];
?>
<div class="row g-3 mb-2">
    <?php foreach ($cards as [$label, $value, $icon, $color, $href]): ?>
        <div class="col-6 col-xl-3">
            <a href="<?= e($href) ?>" class="text-decoration-none">
                <div class="card mb-0 h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="avatar-md d-flex align-items-center justify-content-center rounded bg-<?= $color ?>-subtle text-<?= $color ?> fs-22 flex-shrink-0">
                            <i class="<?= $icon ?>"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 fw-bold"><?= (int) $value ?></h3>
                            <p class="text-muted mb-0 fs-13"><?= e($label) ?></p>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <h5 class="card-title mb-0">Recent registrations</h5>
                <a href="<?= url('registrations') ?>" class="btn btn-sm btn-light ms-auto">View all</a>
            </div>
            <div class="card-body">
                <?php if (!$recent_regs): ?>
                    <p class="text-muted mb-0">No registrations yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <tbody>
                            <?php foreach ($recent_regs as $r): ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold"><?= e($r['lead'] ?: 'Unknown') ?></span>
                                        <?php if ($r['people'] > 1): ?>
                                            <span class="badge bg-light text-dark">+<?= (int) $r['people'] - 1 ?></span>
                                        <?php endif; ?>
                                        <div class="fs-12 text-muted"><?= e($r['tour'] ?: 'General interest') ?></div>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($r['status'] === 'new'): ?>
                                            <span class="badge bg-primary-subtle text-primary">New</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted">Handled</span>
                                        <?php endif; ?>
                                        <div class="fs-12 text-muted"><?= e(date('M j, H:i', strtotime($r['created_at']))) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <h5 class="card-title mb-0">Recent messages</h5>
                <a href="<?= url('messages') ?>" class="btn btn-sm btn-light ms-auto">View all</a>
            </div>
            <div class="card-body">
                <?php if (!$recent_msgs): ?>
                    <p class="text-muted mb-0">No messages yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <tbody>
                            <?php foreach ($recent_msgs as $m): ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></span>
                                        <div class="fs-12 text-muted"><?= e($m['topic'] ?: '—') ?></div>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($m['status'] === 'unanswered'): ?>
                                            <span class="badge bg-danger-subtle text-danger">Unanswered</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success">Answered</span>
                                        <?php endif; ?>
                                        <div class="fs-12 text-muted"><?= e(date('M j, H:i', strtotime($m['created_at']))) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
