<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require_once __DIR__ . '/app/ordering.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int) input('id');
    $action = input('action');

    if ($action === 'delete') {
        $g = db_one('SELECT image, full_name FROM guides WHERE id = ?', [$id]);
        if ($g) {
            foreach (db_all('SELECT custom_icon FROM guide_socials WHERE guide_id = ?', [$id]) as $s) {
                delete_upload($s['custom_icon']);
            }
            delete_upload($g['image']);
            db_run('DELETE FROM guides WHERE id = ?', [$id]); // socials cascade
            flash('success', $g['full_name'] . ' was removed.');
        }
    } elseif ($action === 'move') {
        reorder_move('guides', $id, (string) input('dir'), 'full_name');
    }
    redirect('guides');
}

$guides = db_all(
    'SELECT g.*, (SELECT COUNT(*) FROM guide_socials s WHERE s.guide_id = g.id) AS socials,
            (SELECT COUNT(*) FROM tour_guides tg WHERE tg.guide_id = g.id) AS tours
       FROM guides g ORDER BY g.sort_order, g.full_name'
);
$last = count($guides) - 1;

$page = [
    'title'    => 'Guides',
    'subtitle' => 'People you attach to tours. The order here is the order shown on the site.',
    'active'   => 'guides',
    'actions'  => ui_btn('New guide', ['href' => url('guide-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line']),
];
require __DIR__ . '/partials/head.php';
?>

<?php if ($guides): ?>
    <div class="toolbar">
        <div class="toolbar__end">
            <?php ui_search('guideList', 'Search guides…', ['empty_id' => 'guideNoResults']); ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <?php if (!$guides): ?>
        <?php ui_empty(
            'ri-user-star-line',
            'No guides yet',
            'Add the people who lead your trips, then attach them to tours.',
            ui_btn('New guide', ['href' => url('guide-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line'])
        ); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--stack" id="guideList">
                <thead>
                    <tr>
                        <th>Guide</th>
                        <th class="center">Social links</th>
                        <th class="center">Tours</th>
                        <th class="shrink">Order</th>
                        <th class="shrink"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($guides as $i => $g):
                    $editUrl = url('guide-edit/' . (int) $g['id']); ?>
                    <tr class="row-link" data-search-text="<?= e($g['full_name'] . ' ' . strip_tags((string) $g['bio_en'])) ?>">
                        <td class="cell-primary">
                            <div class="cell-media">
                                <?= ui_thumb($g['image'] ? upload_url($g['image']) : null, 'ri-user-line', 'thumb--round') ?>
                                <span>
                                    <a class="cell-media__title row-link__target" href="<?= e($editUrl) ?>"><?= e($g['full_name']) ?></a>
                                    <span class="cell-media__meta"><?= e(html_excerpt($g['bio_en'], 70)) ?: 'No description yet' ?></span>
                                </span>
                            </div>
                        </td>
                        <td class="center" data-label="Social links">
                            <span><?= ui_badge((string) (int) $g['socials'], (int) $g['socials'] ? '' : 'outline') ?></span>
                        </td>
                        <td class="center" data-label="Tours">
                            <span><?= ui_badge((string) (int) $g['tours'], (int) $g['tours'] ? 'primary' : 'outline') ?></span>
                        </td>
                        <td class="shrink" data-label="Order">
                            <div class="btn-group">
                                <?= ui_action_form(url('guides'), ['action' => 'move', 'id' => (int) $g['id'], 'dir' => 'up'],
                                    ui_icon_btn('ri-arrow-up-line', 'Move ' . $g['full_name'] . ' up', [
                                        'attrs' => $i === 0 ? ['disabled' => true] : [],
                                    ])) ?>
                                <?= ui_action_form(url('guides'), ['action' => 'move', 'id' => (int) $g['id'], 'dir' => 'down'],
                                    ui_icon_btn('ri-arrow-down-line', 'Move ' . $g['full_name'] . ' down', [
                                        'attrs' => $i === $last ? ['disabled' => true] : [],
                                    ])) ?>
                            </div>
                        </td>
                        <td class="shrink">
                            <div class="row-actions">
                                <?= ui_icon_btn('ri-pencil-line', 'Edit ' . $g['full_name'], ['href' => $editUrl]) ?>
                                <?= ui_action_form(
                                    url('guides'),
                                    ['action' => 'delete', 'id' => (int) $g['id']],
                                    ui_icon_btn('ri-delete-bin-line', 'Delete ' . $g['full_name'], ['variant' => 'danger-ghost']),
                                    [
                                        'confirm'      => 'Remove ' . $g['full_name'] . '?',
                                        'confirm_text' => 'They will be detached from ' . (int) $g['tours'] . ' tour(s). This cannot be undone.',
                                        'confirm_label'=> 'Remove guide',
                                    ]
                                ) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="guideNoResults" class="hide">
            <?php ui_empty('ri-search-line', 'No guides match your search', 'Try a different word, or clear the search box.'); ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
