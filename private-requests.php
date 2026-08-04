<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('action');

    if ($action === 'toggle') {
        db_run("UPDATE private_tour_requests SET status = IF(status='new','handled','new') WHERE id=?", [(int) input('id')]);
    } elseif ($action === 'delete') {
        db_run('DELETE FROM private_tour_requests WHERE id=?', [(int) input('id')]);
        flash('success', 'Request deleted.');
    } elseif ($action === 'bulk') {
        $ids = array_values(array_filter(array_map('intval', (array) input('ids', []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $to = input('bulk_action') === 'new' ? 'new' : 'handled';
            db_run("UPDATE private_tour_requests SET status=? WHERE id IN ($in)", array_merge([$to], $ids));
            flash('success', count($ids) . ' request(s) marked ' . $to . '.');
        }
    }
    redirect('private-requests' . (input('filter') ? '?filter=' . urlencode((string) input('filter')) : ''));
}

$filter   = in_array(input('filter'), ['new', 'handled'], true) ? (string) input('filter') : '';
$where    = $filter !== '' ? 'WHERE status = ' . db()->quote($filter) : '';
$requests = db_all("SELECT * FROM private_tour_requests $where ORDER BY created_at DESC");

$counts = ['' => 0, 'new' => 0, 'handled' => 0];
foreach (db_all("SELECT status, COUNT(*) c FROM private_tour_requests GROUP BY status") as $r) {
    $counts[$r['status']] = (int) $r['c'];
    $counts[''] += (int) $r['c'];
}

$page = [
    'title'    => 'Private requests',
    'subtitle' => 'Custom trip enquiries from the “Private tours” form.',
    'active'   => 'private-requests',
];
require __DIR__ . '/partials/head.php';
?>

<div class="toolbar">
    <?php ui_seg(
        [
            ''        => ['label' => 'All',     'count' => $counts['']],
            'new'     => ['label' => 'New',     'count' => $counts['new']],
            'handled' => ['label' => 'Handled', 'count' => $counts['handled']],
        ],
        $filter,
        static fn($v) => url('private-requests' . ($v !== '' ? '?filter=' . $v : '')),
        'Filter requests'
    ); ?>
    <div class="toolbar__end">
        <?php ui_search('prList', 'Search name, email or destination…', ['empty_id' => 'prNoResults']); ?>
    </div>
</div>

<div class="bulk-bar" id="prBulk">
    <span class="bulk-bar__count">0 selected</span>
    <div class="bulk-bar__actions">
        <form method="post" action="<?= url('private-requests') ?>" class="btn-group">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <button class="btn btn--sm btn--secondary" name="bulk_action" value="handled" type="submit">
                <i class="ri-check-double-line" aria-hidden="true"></i><span>Mark handled</span>
            </button>
            <button class="btn btn--sm btn--secondary" name="bulk_action" value="new" type="submit">
                <i class="ri-inbox-unarchive-line" aria-hidden="true"></i><span>Mark new</span>
            </button>
        </form>
    </div>
</div>

<div class="card">
    <?php if (!$requests): ?>
        <?php ui_empty(
            'ri-vip-diamond-line',
            $filter !== '' ? 'Nothing here' : 'No private requests yet',
            $filter === 'new'
                ? 'Every custom trip enquiry has been handled.'
                : 'Custom trip enquiries from the website will appear here.'
        ); ?>
    <?php else: ?>
        <div class="table-wrap" data-bulk="prBulk">
            <table class="table table--stack" id="prList">
                <thead>
                    <tr>
                        <th class="shrink">
                            <input type="checkbox" class="row-select" data-bulk-all aria-label="Select all requests">
                        </th>
                        <th>Enquirer</th>
                        <th>Group</th>
                        <th>Dates</th>
                        <th>Received</th>
                        <th>Status</th>
                        <th class="shrink"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r):
                    $isNew = $r['status'] === 'new';
                    $dests = json_decode((string) $r['destinations'], true);
                    $dests = is_array($dests) ? $dests : [];
                ?>
                    <tr data-search-text="<?= e($r['name'] . ' ' . $r['email'] . ' ' . implode(' ', $dests) . ' ' . $r['notes']) ?>"
                        class="<?= $isNew ? 'is-unread' : '' ?>">
                        <td class="shrink" data-label="Select">
                            <input type="checkbox" class="row-select" data-bulk-item value="<?= (int) $r['id'] ?>"
                                   aria-label="Select request from <?= e($r['name']) ?>">
                        </td>

                        <td class="cell-primary">
                            <div class="cell-media">
                                <?= ui_avatar($r['name']) ?>
                                <span>
                                    <button type="button" class="cell-media__title js-expand"
                                            aria-expanded="false" aria-controls="pr<?= (int) $r['id'] ?>"
                                            style="background:none;border:0;padding:0;cursor:pointer;text-align:start;font:inherit;color:inherit">
                                        <?= e($r['name']) ?>
                                        <i class="ri-arrow-down-s-line t-muted" aria-hidden="true"></i>
                                    </button>
                                    <span class="cell-media__meta"><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></span>
                                </span>
                            </div>
                        </td>

                        <td data-label="Group"><span class="t-sm"><?= e($r['group_size'] ?: '—') ?></span></td>
                        <td class="nowrap" data-label="Dates"><span class="t-sm"><?= e($r['dates_info'] ?: '—') ?></span></td>
                        <td class="nowrap" data-label="Received"><span class="t-sm t-muted"><?= ui_time($r['created_at']) ?></span></td>
                        <td class="nowrap" data-label="Status"><?= $isNew ? ui_status('New', 'warning') : ui_status('Handled', 'muted') ?></td>

                        <td class="shrink">
                            <div class="row-actions">
                                <?= ui_action_form(
                                    url('private-requests'),
                                    ['action' => 'toggle', 'id' => (int) $r['id'], 'filter' => $filter],
                                    ui_icon_btn(
                                        $isNew ? 'ri-check-double-line' : 'ri-arrow-go-back-line',
                                        $isNew ? 'Mark as handled' : 'Move back to new'
                                    )
                                ) ?>
                                <?= ui_action_form(
                                    url('private-requests'),
                                    ['action' => 'delete', 'id' => (int) $r['id'], 'filter' => $filter],
                                    ui_icon_btn('ri-delete-bin-line', 'Delete request from ' . $r['name'], ['variant' => 'danger-ghost']),
                                    [
                                        'confirm'      => 'Delete this request?',
                                        'confirm_text' => 'The enquiry from ' . $r['name'] . ' will be removed permanently.',
                                        'confirm_label'=> 'Delete',
                                    ]
                                ) ?>
                            </div>
                        </td>
                    </tr>

                    <tr class="hide" id="pr<?= (int) $r['id'] ?>">
                        <td colspan="7" style="background:var(--surface-sunk)">
                            <div class="row g-4">
                                <div class="col-12 col-md-5">
                                    <p class="eyebrow mb-2">Contact</p>
                                    <p class="t-sm mb-1">
                                        <i class="ri-mail-line t-muted" aria-hidden="true"></i>
                                        <a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a>
                                    </p>
                                    <p class="t-sm mb-3">
                                        <i class="ri-whatsapp-line t-muted" aria-hidden="true"></i>
                                        <?php if ($r['whatsapp']): ?>
                                            <a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $r['whatsapp'])) ?>"
                                               target="_blank" rel="noopener"><?= e($r['whatsapp']) ?></a>
                                        <?php else: ?><span class="t-muted">Not given</span><?php endif; ?>
                                    </p>

                                    <?php if ($dests): ?>
                                        <p class="eyebrow mb-2">Destinations &amp; vibes</p>
                                        <div class="row-flex row-flex--wrap" style="gap:var(--sp-1)">
                                            <?php foreach ($dests as $d): ?>
                                                <span class="chip"><?= e((string) $d) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 col-md-7">
                                    <p class="eyebrow mb-2">Notes from the enquirer</p>
                                    <?php if ($r['notes']): ?>
                                        <p class="t-sm" style="white-space:pre-wrap;max-width:72ch"><?= e($r['notes']) ?></p>
                                    <?php else: ?>
                                        <p class="t-sm t-muted">No additional notes.</p>
                                    <?php endif; ?>
                                    <div class="mt-3">
                                        <?= ui_btn('Reply by email', [
                                            'href'    => 'mailto:' . rawurlencode($r['email']) . '?subject=' . rawurlencode('Your private tour enquiry — Silk Naviora'),
                                            'variant' => 'primary',
                                            'size'    => 'sm',
                                            'icon'    => 'ri-external-link-line',
                                        ]) ?>
                                        <span class="t-xs t-muted ms-2">Opens your computer’s mail app.</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="prNoResults" class="hide">
            <?php ui_empty('ri-search-line', 'No requests match your search', 'Try a name, an email address, or a destination.'); ?>
        </div>
    <?php endif; ?>
</div>

<?php
$page['inline_js'] = <<<'JS'
document.querySelectorAll('.js-expand').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var row = document.getElementById(btn.getAttribute('aria-controls'));
    if (!row) return;
    var open = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', String(!open));
    row.classList.toggle('hide', open);
    var caret = btn.querySelector('i');
    if (caret) caret.className = open ? 'ri-arrow-down-s-line t-muted' : 'ri-arrow-up-s-line t-muted';
  });
});
JS;
require __DIR__ . '/partials/foot.php';
?>
