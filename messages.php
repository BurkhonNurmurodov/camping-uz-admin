<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

/*
 * Was a single-open accordion with no body preview: triaging eight messages
 * cost eight open/close cycles and you could never see two at once. Now every
 * row previews its first line, rows open independently, and several can be
 * handled together.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('action');

    if ($action === 'toggle') {
        db_run("UPDATE contact_messages SET status = IF(status='unanswered','answered','unanswered') WHERE id=?", [(int) input('id')]);
    } elseif ($action === 'delete') {
        db_run('DELETE FROM contact_messages WHERE id=?', [(int) input('id')]);
        flash('success', 'Message deleted.');
    } elseif ($action === 'bulk') {
        $ids = array_values(array_filter(array_map('intval', (array) input('ids', []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            if (input('bulk_action') === 'delete') {
                db_run("DELETE FROM contact_messages WHERE id IN ($in)", $ids);
                flash('success', count($ids) . ' message(s) deleted.');
            } else {
                $to = input('bulk_action') === 'unanswered' ? 'unanswered' : 'answered';
                db_run("UPDATE contact_messages SET status=? WHERE id IN ($in)", array_merge([$to], $ids));
                flash('success', count($ids) . ' message(s) marked ' . $to . '.');
            }
        }
    }
    redirect('messages' . (input('filter') ? '?filter=' . urlencode((string) input('filter')) : ''));
}

$filter = in_array(input('filter'), ['unanswered', 'answered'], true) ? (string) input('filter') : '';
$where  = $filter !== '' ? 'WHERE status = ' . db()->quote($filter) : '';
$msgs   = db_all("SELECT * FROM contact_messages $where ORDER BY created_at DESC");

$counts = ['' => 0, 'unanswered' => 0, 'answered' => 0];
foreach (db_all("SELECT status, COUNT(*) c FROM contact_messages GROUP BY status") as $r) {
    $counts[$r['status']] = (int) $r['c'];
    $counts[''] += (int) $r['c'];
}

$page = [
    'title'    => 'Messages',
    'subtitle' => 'Questions sent through the website contact form.',
    'active'   => 'messages',
];
require __DIR__ . '/partials/head.php';
?>

<div class="toolbar">
    <?php ui_seg(
        [
            ''           => ['label' => 'All',        'count' => $counts['']],
            'unanswered' => ['label' => 'Unanswered', 'count' => $counts['unanswered']],
            'answered'   => ['label' => 'Answered',   'count' => $counts['answered']],
        ],
        $filter,
        static fn($v) => url('messages' . ($v !== '' ? '?filter=' . $v : '')),
        'Filter messages'
    ); ?>
    <div class="toolbar__end">
        <?php ui_search('msgList', 'Search sender, topic or text…', ['empty_id' => 'msgNoResults']); ?>
    </div>
</div>

<div class="bulk-bar" id="msgBulk">
    <span class="bulk-bar__count">0 selected</span>
    <div class="bulk-bar__actions">
        <form method="post" action="<?= url('messages') ?>" class="btn-group">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <button class="btn btn--sm btn--secondary" name="bulk_action" value="answered" type="submit">
                <i class="ri-check-double-line" aria-hidden="true"></i><span>Mark answered</span>
            </button>
            <button class="btn btn--sm btn--secondary" name="bulk_action" value="unanswered" type="submit">
                <i class="ri-inbox-unarchive-line" aria-hidden="true"></i><span>Mark unanswered</span>
            </button>
        </form>
    </div>
</div>

<div class="card">
    <?php if (!$msgs): ?>
        <?php ui_empty(
            'ri-question-answer-line',
            $filter !== '' ? 'Nothing here' : 'No messages yet',
            $filter === 'unanswered'
                ? 'Every message has been answered.'
                : 'Enquiries from the website contact form will appear here.'
        ); ?>
    <?php else: ?>
        <div class="table-wrap" data-bulk="msgBulk">
            <table class="table table--stack" id="msgList">
                <thead>
                    <tr>
                        <th class="shrink">
                            <input type="checkbox" class="row-select" data-bulk-all aria-label="Select all messages">
                        </th>
                        <th>From</th>
                        <th>Message</th>
                        <th>Received</th>
                        <th>Status</th>
                        <th class="shrink"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($msgs as $m):
                    $name    = trim($m['first_name'] . ' ' . $m['last_name']);
                    $unread  = $m['status'] === 'unanswered';
                    $preview = mb_substr(trim(preg_replace('/\s+/', ' ', $m['message'])), 0, 110);
                    $subject = 'Re: ' . ($m['topic'] ?: 'Your message to Silk Naviora');
                ?>
                    <tr data-search-text="<?= e($name . ' ' . $m['email'] . ' ' . $m['topic'] . ' ' . $m['message']) ?>"
                        class="<?= $unread ? 'is-unread' : '' ?>">
                        <td class="shrink" data-label="Select">
                            <input type="checkbox" class="row-select" data-bulk-item value="<?= (int) $m['id'] ?>"
                                   aria-label="Select message from <?= e($name) ?>">
                        </td>

                        <td class="cell-primary">
                            <div class="cell-media">
                                <?= ui_avatar($name) ?>
                                <span>
                                    <button type="button" class="cell-media__title js-expand"
                                            aria-expanded="false" aria-controls="msg<?= (int) $m['id'] ?>"
                                            style="background:none;border:0;padding:0;cursor:pointer;text-align:start;font:inherit;color:inherit">
                                        <?= e($name) ?>
                                        <i class="ri-arrow-down-s-line t-muted" aria-hidden="true"></i>
                                    </button>
                                    <span class="cell-media__meta"><a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a></span>
                                </span>
                            </div>
                        </td>

                        <td data-label="Message">
                            <span class="t-sm t-medium d-block"><?= e($m['topic'] ?: 'No topic') ?></span>
                            <span class="t-xs t-muted t-clamp-2"><?= e($preview) ?><?= mb_strlen($m['message']) > 110 ? '…' : '' ?></span>
                        </td>

                        <td class="nowrap" data-label="Received"><span class="t-sm t-muted"><?= ui_time($m['created_at']) ?></span></td>

                        <td class="nowrap" data-label="Status">
                            <?= $unread ? ui_status('Unanswered', 'danger') : ui_status('Answered', 'success') ?>
                        </td>

                        <td class="shrink">
                            <div class="row-actions">
                                <?= ui_action_form(
                                    url('messages'),
                                    ['action' => 'toggle', 'id' => (int) $m['id'], 'filter' => $filter],
                                    ui_icon_btn(
                                        $unread ? 'ri-check-double-line' : 'ri-arrow-go-back-line',
                                        $unread ? 'Mark as answered' : 'Mark as unanswered'
                                    )
                                ) ?>
                                <?= ui_action_form(
                                    url('messages'),
                                    ['action' => 'delete', 'id' => (int) $m['id'], 'filter' => $filter],
                                    ui_icon_btn('ri-delete-bin-line', 'Delete message from ' . $name, ['variant' => 'danger-ghost']),
                                    [
                                        'confirm'      => 'Delete this message?',
                                        'confirm_text' => 'The message from ' . $name . ' will be removed permanently.',
                                        'confirm_label'=> 'Delete',
                                    ]
                                ) ?>
                            </div>
                        </td>
                    </tr>

                    <tr class="hide" id="msg<?= (int) $m['id'] ?>">
                        <td colspan="6" style="background:var(--surface-sunk)">
                            <p class="eyebrow mb-2">Full message</p>
                            <p class="t-sm" style="white-space:pre-wrap;max-width:78ch"><?= e($m['message']) ?></p>
                            <div class="row-flex row-flex--wrap mt-3">
                                <?= ui_btn('Reply by email', [
                                    'href'    => 'mailto:' . rawurlencode($m['email']) . '?subject=' . rawurlencode($subject),
                                    'variant' => 'primary',
                                    'size'    => 'sm',
                                    'icon'    => 'ri-external-link-line',
                                ]) ?>
                                <span class="t-xs t-muted">Opens your computer’s mail app.</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="msgNoResults" class="hide">
            <?php ui_empty('ri-search-line', 'No messages match your search', 'Try a name, an email address, or a word from the message.'); ?>
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
