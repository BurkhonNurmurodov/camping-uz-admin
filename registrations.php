<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

/*
 * Was a ragged two-column card grid with a mini-table inside every card, which
 * made twelve registrations impossible to scan and offered no way to act on
 * more than one at a time. Now: one scannable list, expandable rows for the
 * people detail, bulk handling, search, and the admin note the schema has
 * always had but the UI never exposed.
 */

$backTo = static function (): string {
    $qs = array_filter([
        'filter' => input('filter'),
        'tour'   => input('tour'),
    ], static fn($v) => $v !== null && $v !== '');
    return 'registrations' . ($qs ? '?' . http_build_query($qs) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('action');

    if ($action === 'toggle') {
        db_run("UPDATE registration_groups SET status = IF(status='new','handled','new') WHERE id=?", [(int) input('id')]);
    } elseif ($action === 'delete') {
        db_run('DELETE FROM registration_groups WHERE id=?', [(int) input('id')]); // people cascade
        flash('success', 'Registration deleted.');
    } elseif ($action === 'note') {
        $note = trim((string) input('note', ''));
        db_run('UPDATE registration_groups SET note=? WHERE id=?', [$note !== '' ? $note : null, (int) input('id')]);
        flash('success', 'Note saved.');
    } elseif ($action === 'bulk') {
        $ids = array_values(array_filter(array_map('intval', (array) input('ids', []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            if (input('bulk_action') === 'delete') {
                db_run("DELETE FROM registration_groups WHERE id IN ($in)", $ids);
                flash('success', count($ids) . ' registration(s) deleted.');
            } else {
                $to = input('bulk_action') === 'new' ? 'new' : 'handled';
                db_run("UPDATE registration_groups SET status=? WHERE id IN ($in)", array_merge([$to], $ids));
                flash('success', count($ids) . ' registration(s) marked ' . $to . '.');
            }
        }
    }
    redirect($backTo());
}

$filter  = in_array(input('filter'), ['new', 'handled'], true) ? (string) input('filter') : '';
$tourId  = (int) input('tour', 0);

$where = [];
$args  = [];
if ($filter !== '') { $where[] = 'g.status = ?'; $args[] = $filter; }
if ($tourId)        { $where[] = 'g.tour_id = ?'; $args[] = $tourId; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$groups = db_all(
    "SELECT g.*, COALESCE(t.title_en, t.title_ru) AS tour_title, t.id AS tid
       FROM registration_groups g
       LEFT JOIN tours t ON t.id = g.tour_id
       $whereSql
      ORDER BY g.created_at DESC",
    $args
);

$people = [];
if ($groups) {
    $ids = implode(',', array_map(static fn($g) => (int) $g['id'], $groups));
    foreach (db_all("SELECT * FROM registration_people WHERE group_id IN ($ids) ORDER BY is_primary DESC, id") as $p) {
        $people[$p['group_id']][] = $p;
    }
}

$counts = ['' => 0, 'new' => 0, 'handled' => 0];
foreach (db_all("SELECT status, COUNT(*) c FROM registration_groups GROUP BY status") as $r) {
    $counts[$r['status']] = (int) $r['c'];
    $counts[''] += (int) $r['c'];
}

$tourFilterTitle = $tourId ? db_val('SELECT COALESCE(title_en, title_ru) FROM tours WHERE id=?', [$tourId]) : null;

$page = [
    'title'    => 'Registrations',
    'subtitle' => 'People who signed up for a tour through the website.',
    'active'   => 'registrations',
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
        static fn($v) => url('registrations' . ($v !== '' ? '?filter=' . $v : '')),
        'Filter registrations'
    ); ?>
    <div class="toolbar__end">
        <?php ui_search('regList', 'Search name, email or tour…', ['empty_id' => 'regNoResults']); ?>
    </div>
</div>

<?php if ($tourFilterTitle): ?>
    <div class="alert alert--info">
        <i class="alert__icon ri-filter-3-line" aria-hidden="true"></i>
        <div class="alert__body">
            Showing registrations for <strong><?= e($tourFilterTitle) ?></strong> only.
            <a href="<?= url('registrations') ?>">Show all registrations</a>.
        </div>
    </div>
<?php endif; ?>

<div class="bulk-bar" id="regBulk">
    <span class="bulk-bar__count">0 selected</span>
    <div class="bulk-bar__actions">
        <form method="post" action="<?= url('registrations') ?>" class="btn-group">
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
    <?php if (!$groups): ?>
        <?php ui_empty(
            'ri-group-line',
            $filter !== '' ? 'Nothing ' . $filter . ' right now' : 'No registrations yet',
            $filter === 'new'
                ? 'Everything has been handled. Nice work.'
                : 'Sign-ups from the website will appear here.'
        ); ?>
    <?php else: ?>
        <div class="table-wrap" data-bulk="regBulk" id="regListScope">
            <table class="table table--stack" id="regList">
                <thead>
                    <tr>
                        <th class="shrink">
                            <input type="checkbox" class="row-select" data-bulk-all aria-label="Select all registrations">
                        </th>
                        <th>Lead traveller</th>
                        <th>Tour</th>
                        <th class="center">People</th>
                        <th>Received</th>
                        <th>Status</th>
                        <th class="shrink"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $g):
                    $ppl   = $people[$g['id']] ?? [];
                    $lead  = $ppl[0] ?? null;
                    $isNew = $g['status'] === 'new';
                    $searchText = ($lead['full_name'] ?? '') . ' ' . ($lead['email'] ?? '') . ' ' . ($g['tour_title'] ?? '')
                                . ' ' . implode(' ', array_column($ppl, 'full_name'))
                                . ' ' . implode(' ', array_column($ppl, 'email'));
                ?>
                    <tr data-search-text="<?= e($searchText) ?>" class="<?= $isNew ? 'is-unread' : '' ?>">
                        <td class="shrink" data-label="Select">
                            <input type="checkbox" class="row-select" data-bulk-item
                                   value="<?= (int) $g['id'] ?>"
                                   aria-label="Select registration from <?= e($lead['full_name'] ?? 'unknown') ?>">
                        </td>

                        <td class="cell-primary">
                            <div class="cell-media">
                                <?= ui_avatar($lead['full_name'] ?? '?') ?>
                                <span>
                                    <button type="button" class="cell-media__title js-expand"
                                            aria-expanded="false" aria-controls="reg<?= (int) $g['id'] ?>"
                                            style="background:none;border:0;padding:0;cursor:pointer;text-align:start;font:inherit;color:inherit">
                                        <?= e($lead['full_name'] ?? 'Unknown') ?>
                                        <i class="ri-arrow-down-s-line t-muted" aria-hidden="true"></i>
                                    </button>
                                    <span class="cell-media__meta">
                                        <?php if ($lead && $lead['email']): ?>
                                            <a href="mailto:<?= e($lead['email']) ?>"><?= e($lead['email']) ?></a>
                                        <?php else: ?>—<?php endif; ?>
                                    </span>
                                </span>
                            </div>
                        </td>

                        <td data-label="Tour">
                            <?php if ($g['tid']): ?>
                                <a class="t-sm" href="<?= url('tour-edit/' . (int) $g['tid']) ?>"><?= e($g['tour_title']) ?></a>
                            <?php else: ?>
                                <span class="t-sm t-muted">General interest</span>
                            <?php endif; ?>
                        </td>

                        <td class="center" data-label="People">
                            <span><?= ui_badge((string) count($ppl), count($ppl) > 1 ? 'primary' : 'outline') ?></span>
                        </td>

                        <td class="nowrap" data-label="Received"><span class="t-sm t-muted"><?= ui_time($g['created_at']) ?></span></td>

                        <td class="nowrap" data-label="Status">
                            <?= $isNew ? ui_status('New', 'new') : ui_status('Handled', 'muted') ?>
                        </td>

                        <td class="shrink">
                            <div class="row-actions">
                                <?= ui_action_form(
                                    url('registrations'),
                                    ['action' => 'toggle', 'id' => (int) $g['id'], 'filter' => $filter, 'tour' => $tourId ?: ''],
                                    ui_icon_btn(
                                        $isNew ? 'ri-check-double-line' : 'ri-arrow-go-back-line',
                                        $isNew ? 'Mark as handled' : 'Move back to new'
                                    )
                                ) ?>
                                <?= ui_action_form(
                                    url('registrations'),
                                    ['action' => 'delete', 'id' => (int) $g['id'], 'filter' => $filter, 'tour' => $tourId ?: ''],
                                    ui_icon_btn('ri-delete-bin-line', 'Delete this registration', ['variant' => 'danger-ghost']),
                                    [
                                        'confirm'      => 'Delete this registration?',
                                        'confirm_text' => 'All ' . count($ppl) . ' traveller record(s) in this booking will be removed. This cannot be undone.',
                                        'confirm_label'=> 'Delete',
                                    ]
                                ) ?>
                            </div>
                        </td>
                    </tr>

                    <!-- Expandable detail: everyone in the party, plus the admin note. -->
                    <tr class="hide reg-detail" id="reg<?= (int) $g['id'] ?>">
                        <td colspan="7" style="background:var(--surface-sunk)">
                            <div class="row g-4">
                                <div class="col-12 col-lg-7">
                                    <p class="eyebrow mb-2">Travellers</p>
                                    <div class="table-wrap">
                                        <table class="table" style="font-size:var(--fs-sm)">
                                            <tbody>
                                            <?php foreach ($ppl as $p): ?>
                                                <tr>
                                                    <td>
                                                        <?= e($p['full_name']) ?>
                                                        <?= $p['is_primary'] ? ui_badge('lead', 'outline') : '' ?>
                                                    </td>
                                                    <td><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a></td>
                                                    <td>
                                                        <?php if ($p['whatsapp_phone']): ?>
                                                            <a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $p['whatsapp_phone'])) ?>"
                                                               target="_blank" rel="noopener"><?= e($p['whatsapp_phone']) ?></a>
                                                        <?php else: ?><span class="t-muted">—</span><?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-5">
                                    <p class="eyebrow mb-2">Internal note</p>
                                    <form method="post" action="<?= url('registrations') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="note">
                                        <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                                        <label class="sr-only" for="note<?= (int) $g['id'] ?>">Internal note</label>
                                        <textarea class="textarea mb-2" id="note<?= (int) $g['id'] ?>" name="note" rows="3"
                                                  placeholder="Only your team sees this — e.g. called, deposit received…"><?= e((string) $g['note']) ?></textarea>
                                        <?= ui_btn('Save note', ['size' => 'sm', 'icon' => 'ri-sticky-note-line']) ?>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="regNoResults" class="hide">
            <?php ui_empty('ri-search-line', 'No registrations match your search', 'Try a name, an email address, or a tour title.'); ?>
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
