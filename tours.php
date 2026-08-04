<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int) input('id');
    $action = input('action');
    $back   = 'tours' . (input('status') && $action !== 'status' ? '?status=' . urlencode((string) input('status')) : '');

    if ($action === 'delete') {
        $t = db_one('SELECT poster, title_en, title_ru FROM tours WHERE id=?', [$id]);
        if ($t) {
            delete_upload($t['poster']);
            db_run('DELETE FROM tours WHERE id=?', [$id]);
            flash('success', '“' . ($t['title_en'] ?: $t['title_ru'] ?: 'Tour') . '” was deleted.');
        }
    } elseif ($action === 'status') {
        $s = input('new_status');
        if (in_array($s, ['draft', 'upcoming', 'past'], true)) {
            db_run('UPDATE tours SET status=? WHERE id=?', [$s, $id]);
            $t = db_one('SELECT title_en, title_ru FROM tours WHERE id=?', [$id]);
            $labels = ['draft' => 'Draft', 'upcoming' => 'Upcoming', 'past' => 'Past'];
            // The old build changed status silently, leaving the operator to
            // guess whether it saved. Always confirm.
            flash('success', '“' . ($t['title_en'] ?: $t['title_ru'] ?: 'Tour') . '” moved to ' . $labels[$s] . '.');
        }
        $back = 'tours' . (input('filter') ? '?status=' . urlencode((string) input('filter')) : '');
    }
    redirect($back);
}

$filter = in_array(input('status'), ['draft', 'upcoming', 'past'], true) ? (string) input('status') : '';
$where  = $filter !== '' ? 'WHERE t.status = ' . db()->quote($filter) : '';

$tours = db_all(
    "SELECT t.*,
            (SELECT COUNT(*) FROM tour_guides tg WHERE tg.tour_id = t.id) AS guides,
            (SELECT COUNT(*) FROM registration_groups r WHERE r.tour_id = t.id) AS regs
       FROM tours t $where
      ORDER BY t.sort_order, t.start_date IS NULL, t.start_date, t.id DESC"
);

$counts = ['' => 0];
foreach (db_all("SELECT status, COUNT(*) c FROM tours GROUP BY status") as $r) {
    $counts[$r['status']] = (int) $r['c'];
    $counts[''] += (int) $r['c'];
}

$page = [
    'title'    => 'Tours',
    'subtitle' => 'Trips shown on the public site. Drafts stay hidden until you publish them.',
    'active'   => 'tours',
    'actions'  => ui_btn('New tour', ['href' => url('tour-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line']),
];
require __DIR__ . '/partials/head.php';

$statusTone = ['draft' => 'muted', 'upcoming' => 'new', 'past' => 'muted'];
?>

<div class="toolbar">
    <?php ui_seg(
        [
            ''         => ['label' => 'All',      'count' => $counts[''] ?? 0],
            'upcoming' => ['label' => 'Upcoming', 'count' => $counts['upcoming'] ?? 0],
            'draft'    => ['label' => 'Draft',    'count' => $counts['draft'] ?? 0],
            'past'     => ['label' => 'Past',     'count' => $counts['past'] ?? 0],
        ],
        $filter,
        static fn($v) => url('tours' . ($v !== '' ? '?status=' . $v : '')),
        'Filter tours by status'
    ); ?>
    <div class="toolbar__end">
        <?php ui_search('tourList', 'Search tours…', ['empty_id' => 'tourNoResults']); ?>
    </div>
</div>

<div class="card">
    <?php if (!$tours): ?>
        <?php ui_empty(
            'ri-route-line',
            $filter !== '' ? 'No ' . $filter . ' tours' : 'No tours yet',
            $filter !== ''
                ? 'Nothing matches this filter. Try another one, or create a tour.'
                : 'Create your first tour to start taking registrations.',
            ui_btn('New tour', ['href' => url('tour-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line'])
        ); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--stack" id="tourList">
                <thead>
                    <tr>
                        <th>Tour</th>
                        <th>Dates</th>
                        <th class="center">Guides</th>
                        <th class="center">Registrations</th>
                        <th>Status</th>
                        <th class="shrink"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tours as $t):
                    $title = $t['title_en'] ?: $t['title_ru'] ?: 'Untitled tour';
                    $editUrl = url('tour-edit/' . (int) $t['id']);
                ?>
                    <tr class="row-link" data-search-text="<?= e($title . ' ' . $t['title_ru'] . ' ' . $t['slug']) ?>">
                        <td class="cell-primary">
                            <div class="cell-media">
                                <?= ui_thumb($t['poster'] ? upload_url($t['poster']) : null, 'ri-image-line', 'thumb--wide') ?>
                                <span>
                                    <a class="cell-media__title row-link__target" href="<?= e($editUrl) ?>"><?= e($title) ?></a>
                                    <span class="cell-media__meta">/<?= e($t['slug']) ?></span>
                                </span>
                            </div>
                        </td>

                        <td class="nowrap" data-label="Dates">
                            <span class="t-sm"><?= e(format_tour_dates($t['start_date'], $t['end_date']) ?: '—') ?></span>
                        </td>

                        <td class="center" data-label="Guides">
                            <span><?= ui_badge((string) (int) $t['guides'], (int) $t['guides'] ? '' : 'outline') ?></span>
                        </td>

                        <td class="center" data-label="Registrations">
                            <?php if ((int) $t['regs'] > 0): ?>
                                <a href="<?= url('registrations?tour=' . (int) $t['id']) ?>"
                                   title="View registrations for this tour">
                                    <?= ui_badge((string) (int) $t['regs'], 'primary') ?>
                                </a>
                            <?php else: ?>
                                <span><?= ui_badge('0', 'outline') ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="nowrap" data-label="Status">
                            <form method="post" action="<?= url('tours') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <input type="hidden" name="filter" value="<?= e($filter) ?>">
                                <label class="sr-only" for="st<?= (int) $t['id'] ?>">Status for <?= e($title) ?></label>
                                <select class="select" id="st<?= (int) $t['id'] ?>" name="new_status"
                                        data-autosubmit style="height:32px;font-size:var(--fs-sm);min-width:118px">
                                    <?php foreach (['draft' => 'Draft', 'upcoming' => 'Upcoming', 'past' => 'Past'] as $v => $l): ?>
                                        <option value="<?= $v ?>" <?= $t['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>

                        <td class="shrink">
                            <div class="row-actions">
                                <?= ui_icon_btn('ri-pencil-line', 'Edit ' . $title, ['href' => $editUrl]) ?>
                                <?= ui_action_form(
                                    url('tours'),
                                    ['action' => 'delete', 'id' => (int) $t['id'], 'status' => $filter],
                                    ui_icon_btn('ri-delete-bin-line', 'Delete ' . $title, ['variant' => 'danger-ghost']),
                                    [
                                        'confirm'       => 'Delete “' . $title . '”?',
                                        'confirm_text'  => 'Registrations linked to this tour are kept, but will no longer show which tour they were for. This cannot be undone.',
                                        'confirm_label' => 'Delete tour',
                                    ]
                                ) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="tourNoResults" class="hide">
            <?php ui_empty('ri-search-line', 'No tours match your search', 'Try a different word, or clear the search box.'); ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
