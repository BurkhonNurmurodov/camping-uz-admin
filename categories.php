<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require_once __DIR__ . '/app/ordering.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int) input('id');
    $action = input('action');

    if ($action === 'delete') {
        $c = db_one('SELECT title_en FROM categories WHERE id=?', [$id]);
        if ($c) {
            db_run('DELETE FROM categories WHERE id=?', [$id]);
            flash('success', '“' . $c['title_en'] . '” was deleted.');
        }
    } elseif ($action === 'move') {
        reorder_move('categories', $id, (string) input('dir'), 'id');
    }
    redirect('categories');
}

$categories = db_all(
    "SELECT c.*, (SELECT COUNT(*) FROM tour_categories tc WHERE tc.category_id = c.id) AS tours
       FROM categories c ORDER BY c.sort_order, c.id"
);
$last = count($categories) - 1;

$page = [
    'title'    => 'Categories',
    'subtitle' => 'Tags used to filter tours on the public site. A tour can carry up to four.',
    'active'   => 'categories',
    'actions'  => ui_btn('New category', ['href' => url('category-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line']),
];
require __DIR__ . '/partials/head.php';
?>

<div class="card">
    <?php if (!$categories): ?>
        <?php ui_empty(
            'ri-price-tag-3-line',
            'No categories yet',
            'Categories let visitors filter tours by the kind of trip — mountains, desert, city and so on.',
            ui_btn('New category', ['href' => url('category-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line'])
        ); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Russian title</th>
                        <th>Slug</th>
                        <th class="center">Tours</th>
                        <th class="shrink">Order</th>
                        <th class="shrink"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $i => $c):
                    $editUrl = url('category-edit?id=' . (int) $c['id']); ?>
                    <tr class="row-link">
                        <td class="cell-primary">
                            <a class="cell-media__title row-link__target" href="<?= e($editUrl) ?>"><?= e($c['title_en']) ?></a>
                        </td>
                        <td data-label="Russian title"><span class="t-muted"><?= e($c['title_ru']) ?></span></td>
                        <td data-label="Slug"><span><?= ui_badge('/' . $c['slug'], 'outline') ?></span></td>
                        <td class="center" data-label="Tours">
                            <span><?= ui_badge((string) (int) $c['tours'], (int) $c['tours'] ? 'primary' : 'outline') ?></span>
                        </td>
                        <td class="shrink" data-label="Order">
                            <div class="btn-group">
                                <?= ui_action_form(url('categories'), ['action' => 'move', 'id' => (int) $c['id'], 'dir' => 'up'],
                                    ui_icon_btn('ri-arrow-up-line', 'Move ' . $c['title_en'] . ' up', ['attrs' => $i === 0 ? ['disabled' => true] : []])) ?>
                                <?= ui_action_form(url('categories'), ['action' => 'move', 'id' => (int) $c['id'], 'dir' => 'down'],
                                    ui_icon_btn('ri-arrow-down-line', 'Move ' . $c['title_en'] . ' down', ['attrs' => $i === $last ? ['disabled' => true] : []])) ?>
                            </div>
                        </td>
                        <td class="shrink">
                            <div class="row-actions">
                                <?= ui_icon_btn('ri-pencil-line', 'Edit ' . $c['title_en'], ['href' => $editUrl]) ?>
                                <?= ui_action_form(
                                    url('categories'),
                                    ['action' => 'delete', 'id' => (int) $c['id']],
                                    ui_icon_btn('ri-delete-bin-line', 'Delete ' . $c['title_en'], ['variant' => 'danger-ghost']),
                                    [
                                        'confirm'      => 'Delete “' . $c['title_en'] . '”?',
                                        'confirm_text' => (int) $c['tours'] > 0
                                            ? 'It will be removed from ' . (int) $c['tours'] . ' tour(s). The tours themselves are kept.'
                                            : 'This cannot be undone.',
                                        'confirm_label'=> 'Delete category',
                                    ]
                                ) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
