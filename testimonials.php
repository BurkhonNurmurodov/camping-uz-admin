<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require_once __DIR__ . '/app/ordering.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int) input('id');
    $action = input('action');

    if ($action === 'delete') {
        $t = db_one('SELECT avatar, author_name FROM testimonials WHERE id=?', [$id]);
        if ($t) {
            delete_upload($t['avatar']);
            db_run('DELETE FROM testimonials WHERE id=?', [$id]);
            flash('success', 'Testimonial from ' . $t['author_name'] . ' was deleted.');
        }
    } elseif ($action === 'toggle') {
        db_run('UPDATE testimonials SET is_visible = 1 - is_visible WHERE id=?', [$id]);
        $t = db_one('SELECT author_name, is_visible FROM testimonials WHERE id=?', [$id]);
        if ($t) {
            flash('success', $t['author_name'] . ($t['is_visible'] ? ' is now shown on the site.' : ' is now hidden.'));
        }
    } elseif ($action === 'move') {
        reorder_move('testimonials', $id, (string) input('dir'), 'created_at DESC');
    }
    redirect('testimonials');
}

$items = db_all('SELECT * FROM testimonials ORDER BY sort_order, created_at DESC');
$last  = count($items) - 1;
$hidden = count(array_filter($items, static fn($t) => !$t['is_visible']));

$page = [
    'title'    => 'Testimonials',
    'subtitle' => 'Client quotes for the “Our clients about us” carousel. The order here is the carousel order.',
    'active'   => 'testimonials',
    'actions'  => ui_btn('New testimonial', ['href' => url('testimonial-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line']),
];
require __DIR__ . '/partials/head.php';
?>

<?php if ($items): ?>
    <div class="toolbar">
        <p class="t-sm t-muted mb-0">
            <?= count($items) ?> total<?= $hidden ? ' · ' . $hidden . ' hidden from the site' : '' ?>
        </p>
        <div class="toolbar__end">
            <?php ui_search('tmList', 'Search testimonials…', ['empty_id' => 'tmNoResults']); ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <?php if (!$items): ?>
        <?php ui_empty(
            'ri-chat-quote-line',
            'No testimonials yet',
            'Add a few client quotes to build trust on the home page.',
            ui_btn('New testimonial', ['href' => url('testimonial-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line'])
        ); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--stack" id="tmList">
                <thead>
                    <tr>
                        <th>Author</th>
                        <th>Quote</th>
                        <th>Shown</th>
                        <th class="shrink">Order</th>
                        <th class="shrink"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $i => $t):
                    $editUrl = url('testimonial-edit/' . (int) $t['id']);
                    $quote   = html_excerpt($t['comment_en_html'] ?: $t['comment_ru_html'], 90); ?>
                    <tr class="row-link" data-search-text="<?= e($t['author_name'] . ' ' . $quote) ?>">
                        <td class="cell-primary">
                            <div class="cell-media">
                                <?= $t['avatar']
                                    ? ui_thumb(upload_url($t['avatar']), 'ri-user-line', 'thumb--round')
                                    : ui_avatar($t['author_name']) ?>
                                <a class="cell-media__title row-link__target" href="<?= e($editUrl) ?>"><?= e($t['author_name']) ?></a>
                            </div>
                        </td>
                        <td data-label="Quote">
                            <span class="t-sm t-muted t-clamp-2"><?= e($quote) ?: '—' ?></span>
                        </td>
                        <td data-label="Shown">
                            <?= ui_action_form(
                                url('testimonials'),
                                ['action' => 'toggle', 'id' => (int) $t['id']],
                                ui_icon_btn(
                                    $t['is_visible'] ? 'ri-eye-line' : 'ri-eye-off-line',
                                    $t['is_visible'] ? 'Hide ' . $t['author_name'] . ' from the site' : 'Show ' . $t['author_name'] . ' on the site',
                                    ['variant' => $t['is_visible'] ? 'ghost' : 'secondary']
                                ) . '<span class="t-sm ms-2">' . ($t['is_visible'] ? 'Visible' : 'Hidden') . '</span>',
                                ['class' => 'align-items-center gap-1']
                            ) ?>
                        </td>
                        <td class="shrink" data-label="Order">
                            <div class="btn-group">
                                <?= ui_action_form(url('testimonials'), ['action' => 'move', 'id' => (int) $t['id'], 'dir' => 'up'],
                                    ui_icon_btn('ri-arrow-up-line', 'Move ' . $t['author_name'] . ' up', ['attrs' => $i === 0 ? ['disabled' => true] : []])) ?>
                                <?= ui_action_form(url('testimonials'), ['action' => 'move', 'id' => (int) $t['id'], 'dir' => 'down'],
                                    ui_icon_btn('ri-arrow-down-line', 'Move ' . $t['author_name'] . ' down', ['attrs' => $i === $last ? ['disabled' => true] : []])) ?>
                            </div>
                        </td>
                        <td class="shrink">
                            <div class="row-actions">
                                <?= ui_icon_btn('ri-pencil-line', 'Edit testimonial from ' . $t['author_name'], ['href' => $editUrl]) ?>
                                <?= ui_action_form(
                                    url('testimonials'),
                                    ['action' => 'delete', 'id' => (int) $t['id']],
                                    ui_icon_btn('ri-delete-bin-line', 'Delete testimonial from ' . $t['author_name'], ['variant' => 'danger-ghost']),
                                    [
                                        'confirm'      => 'Delete this testimonial?',
                                        'confirm_text' => 'The quote from ' . $t['author_name'] . ' will be removed permanently.',
                                        'confirm_label'=> 'Delete',
                                    ]
                                ) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="tmNoResults" class="hide">
            <?php ui_empty('ri-search-line', 'No testimonials match your search', 'Try a different word, or clear the search box.'); ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
