<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

/*
 * The dashboard answers two questions, in this order:
 *   1. What needs me right now?      (inbox triage)
 *   2. What is happening next?       (the published catalogue)
 *
 * The old version showed four counters, one of which was a plain inventory
 * count ("Guides: 4"), and silently omitted Private Requests even though the
 * sidebar badged it. Every tile here is a queue you can act on, and the set
 * matches the sidebar exactly.
 */

$upcoming = db_all(
    "SELECT t.id, t.title_en, t.title_ru, t.slug, t.poster, t.start_date, t.end_date, t.status,
            (SELECT COUNT(*) FROM registration_groups r WHERE r.tour_id = t.id) AS regs
       FROM tours t
      WHERE t.status = 'upcoming'
      ORDER BY t.start_date IS NULL, t.start_date, t.sort_order
      LIMIT 5"
);

$draftCount = (int) db_val("SELECT COUNT(*) FROM tours WHERE status = 'draft'");

$recent = db_all(
    "SELECT * FROM (
        SELECT 'registration' AS kind, g.id, g.created_at, g.status,
               COALESCE((SELECT p.full_name FROM registration_people p
                          WHERE p.group_id = g.id ORDER BY p.is_primary DESC, p.id LIMIT 1), 'Unknown') AS who,
               COALESCE(t.title_en, t.title_ru, 'General interest') AS detail,
               (SELECT COUNT(*) FROM registration_people p WHERE p.group_id = g.id) AS extra
          FROM registration_groups g
          LEFT JOIN tours t ON t.id = g.tour_id
        UNION ALL
        SELECT 'message', m.id, m.created_at,
               IF(m.status = 'unanswered', 'new', 'handled'),
               CONCAT(m.first_name, ' ', m.last_name),
               COALESCE(NULLIF(m.topic, ''), 'No topic'), 1
          FROM contact_messages m
        UNION ALL
        SELECT 'private', r.id, r.created_at, r.status, r.name,
               COALESCE(NULLIF(r.dates_info, ''), 'Custom trip'), 1
          FROM private_tour_requests r
     ) feed
     ORDER BY created_at DESC
     LIMIT 8"
);

$kinds = [
    'registration' => ['Registration', 'ri-group-line',       url('registrations')],
    'message'      => ['Message',      'ri-question-answer-line', url('messages')],
    'private'      => ['Private request', 'ri-vip-diamond-line', url('private-requests')],
];

$page = [
    'title'    => 'Dashboard',
    'subtitle' => 'What needs your attention, and what is coming up.',
    'active'   => 'dashboard',
    'actions'  => ui_btn('New tour', ['href' => url('tour-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line']),
];
require __DIR__ . '/partials/head.php';

// Every tile is a queue with an action behind it — and the set is identical
// to the sidebar badges, so the two can never tell different stories.
$tiles = [
    ['Registrations to review', $nav_new_regs,     'ri-group-line',            'primary', url('registrations?filter=new')],
    ['Private requests',        $nav_new_privates, 'ri-vip-diamond-line',      'warning', url('private-requests?filter=new')],
    ['Unanswered messages',     $nav_unread_msgs,  'ri-question-answer-line',  'danger',  url('messages?filter=unanswered')],
    ['Drafts not published',    $draftCount,       'ri-draft-line',            'info',    url('tours?status=draft')],
];
?>

<div class="stat-grid">
    <?php foreach ($tiles as [$label, $value, $icon, $tone, $href]): ?>
        <a class="stat stat--<?= $tone ?><?= $value === 0 ? ' is-zero' : '' ?>" href="<?= e($href) ?>">
            <span class="stat__icon"><i class="<?= $icon ?>" aria-hidden="true"></i></span>
            <span>
                <span class="stat__value"><?= (int) $value ?></span>
                <span class="stat__label"><?= e($label) ?></span>
            </span>
            <i class="stat__cta ri-arrow-right-line" aria-hidden="true"></i>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($nav_inbox_total === 0): ?>
    <div class="alert alert--success" role="status">
        <i class="alert__icon ri-checkbox-circle-line" aria-hidden="true"></i>
        <div class="alert__body">
            <span class="alert__title">Inbox clear.</span>
            Every registration, request and message has been handled.
        </div>
    </div>
<?php endif; ?>

<div class="split split--main-aside">
    <!-- Latest activity across all three queues, newest first. Each row is a
         link straight to the queue it came from — the old dashboard listed
         these as dead text. -->
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title">Latest activity</h2>
                <p class="card__sub">Registrations, requests and messages</p>
            </div>
        </div>

        <?php if (!$recent): ?>
            <?php ui_empty(
                'ri-inbox-line',
                'Nothing has come in yet',
                'New registrations, private requests and contact messages will appear here as they arrive.'
            ); ?>
        <?php else: ?>
            <ul class="feed">
                <?php foreach ($recent as $row):
                    [$kindLabel, $kindIcon, $kindHref] = $kinds[$row['kind']];
                    $isNew = $row['status'] === 'new';
                    $extra = (int) $row['extra'];
                ?>
                    <li class="feed__item<?= $isNew ? ' is-unread' : '' ?>">
                        <span class="thumb thumb--round" aria-hidden="true"><i class="<?= $kindIcon ?>"></i></span>
                        <div class="feed__body">
                            <a class="feed__title" href="<?= e($kindHref) ?>">
                                <?= e($row['who']) ?><?php if ($row['kind'] === 'registration' && $extra > 1): ?>
                                    <span class="badge">+<?= $extra - 1 ?></span>
                                <?php endif; ?>
                            </a>
                            <p class="feed__meta">
                                <?= e($kindLabel) ?> · <?= e($row['detail']) ?>
                            </p>
                        </div>
                        <div class="feed__end">
                            <?= $isNew ? ui_status('New', 'new') : ui_status('Handled', 'muted') ?>
                            <span class="feed__time"><?= ui_time($row['created_at']) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- The publishing half of the job. -->
    <aside class="aside-sticky">
        <section class="card">
            <div class="card__head">
                <div>
                    <h2 class="card__title">Next departures</h2>
                    <p class="card__sub">Tours currently published</p>
                </div>
                <div class="card__head-actions">
                    <?= ui_btn('All tours', ['href' => url('tours'), 'size' => 'sm', 'variant' => 'ghost']) ?>
                </div>
            </div>

            <?php if (!$upcoming): ?>
                <?php ui_empty(
                    'ri-route-line',
                    'No upcoming tours',
                    'Publish a tour to show it on the site.',
                    ui_btn('New tour', ['href' => url('tour-edit'), 'variant' => 'primary', 'icon' => 'ri-add-line', 'size' => 'sm'])
                ); ?>
            <?php else: ?>
                <ul class="feed">
                    <?php foreach ($upcoming as $t):
                        $title = $t['title_en'] ?: $t['title_ru'] ?: 'Untitled tour'; ?>
                        <li class="feed__item">
                            <?= ui_thumb($t['poster'] ? upload_url($t['poster']) : null, 'ri-image-line') ?>
                            <div class="feed__body">
                                <a class="feed__title" href="<?= url('tour-edit/' . (int) $t['id']) ?>"><?= e($title) ?></a>
                                <p class="feed__meta">
                                    <?= e(format_tour_dates($t['start_date'], $t['end_date']) ?: 'No dates set') ?>
                                </p>
                            </div>
                            <div class="feed__end">
                                <span class="badge<?= (int) $t['regs'] > 0 ? ' badge--primary' : '' ?>">
                                    <?= (int) $t['regs'] ?> registered
                                </span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__head"><h2 class="card__title">Quick actions</h2></div>
            <div class="card__body stack stack--sm">
                <?= ui_btn('Add a tour',        ['href' => url('tour-edit'),       'icon' => 'ri-route-line',      'block' => true]) ?>
                <?= ui_btn('Add a guide',       ['href' => url('guide-edit'),      'icon' => 'ri-user-star-line',  'block' => true]) ?>
                <?= ui_btn('Add a testimonial', ['href' => url('testimonial-edit'),'icon' => 'ri-chat-quote-line', 'block' => true]) ?>
                <?= ui_btn('Open Mail',         ['href' => url('email'),           'icon' => 'ri-mail-line',       'block' => true]) ?>
            </div>
        </section>
    </aside>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
