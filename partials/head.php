<?php
/**
 * Document head + opening of the app shell.
 *
 * $page keys:
 *   title       string  page name — becomes the one <h1> and the tab title
 *   subtitle    string  optional one-line explanation under the title
 *   active      string  nav key to highlight
 *   actions     string  HTML for the page-level action buttons
 *   back        array   ['href' => …, 'label' => …]
 *   tabs        array   sibling views (Pages / Settings)
 *   bare        bool    render the page header ourselves? default true
 *   vendor_css  string[] extra stylesheets, relative to /assets
 */

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/nav.php';

$page = ($page ?? []) + [
    'title'      => 'Admin',
    'subtitle'   => '',
    'active'     => '',
    'actions'    => '',
    'back'       => null,
    'tabs'       => null,
    'bare'       => false,
    'vendor_css' => [],
];

$A  = BASE_PATH . '/assets';
$me = admin_user();

// Inbox counters drive both the sidebar badges and the topbar bell. One query
// set, one source of truth — the old panel disagreed with itself here.
try {
    $nav_new_regs     = (int) (db_one("SELECT COUNT(*) c FROM registration_groups WHERE status='new'")['c'] ?? 0);
    $nav_new_privates = (int) (db_one("SELECT COUNT(*) c FROM private_tour_requests WHERE status='new'")['c'] ?? 0);
    $nav_unread_msgs  = (int) (db_one("SELECT COUNT(*) c FROM contact_messages WHERE status='unanswered'")['c'] ?? 0);
} catch (Throwable $e) {
    $nav_new_regs = $nav_new_privates = $nav_unread_msgs = 0;
}
$nav_inbox_total = $nav_new_regs + $nav_new_privates + $nav_unread_msgs;

$nav_groups = admin_nav([
    'registrations' => $nav_new_regs,
    'private'       => $nav_new_privates,
    'messages'      => $nav_unread_msgs,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($page['title']) ?> · Silk Naviora Admin</title>

<?php if ($favicon = setting('favicon')): ?>
    <link rel="icon" href="<?= e(upload_url($favicon)) ?>">
<?php else: ?>
    <link rel="icon" href="<?= $A ?>/images/favicon.png">
<?php endif; ?>

<?php /* Applied before first paint so a dark-mode operator never sees a white flash. */ ?>
<script>
(function(){try{var m=localStorage.getItem('sn.theme');if(m&&m!=='system')document.documentElement.setAttribute('data-theme',m);
var s=localStorage.getItem('sn.sidebar.mini');if(s==='1'&&matchMedia('(min-width:992px)').matches)document.documentElement.classList.add('pre-mini');}catch(e){}})();
</script>
<style>html.pre-mini body{--sidebar-w:var(--sidebar-w-mini)}</style>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">

<?php /* Bootstrap supplies layout primitives only — every visual decision is ours. */ ?>
<link rel="stylesheet" href="<?= $A ?>/libs/bootstrap/css/bootstrap-reboot.min.css">
<link rel="stylesheet" href="<?= $A ?>/libs/bootstrap/css/bootstrap-grid.min.css">
<link rel="stylesheet" href="<?= $A ?>/libs/bootstrap/css/bootstrap-utilities.min.css">
<link rel="stylesheet" href="<?= $A ?>/libs/remixicon/fonts/remixicon.css">

<?php foreach ($page['vendor_css'] as $href): ?>
    <link rel="stylesheet" href="<?= $A ?>/<?= e($href) ?>">
<?php endforeach; ?>

<link rel="stylesheet" href="<?= $A ?>/css/tokens.css">
<link rel="stylesheet" href="<?= $A ?>/css/admin.css">
<script>window.SN_BASE = <?= json_encode(BASE_PATH) ?>;</script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>

<div class="app">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="scrim" id="navScrim"></div>

    <div class="main">
        <?php require __DIR__ . '/topbar.php'; ?>

        <main class="content" id="main-content">
            <?php if (!$page['bare']): ?>
                <?php ui_page_head($page['title'], [
                    'sub'     => $page['subtitle'],
                    'actions' => $page['actions'],
                    'back'    => $page['back'],
                ]); ?>
            <?php endif; ?>

            <?php if (!empty($page['tabs'])): ?>
                <?php ui_tab_nav($page['tabs']); ?>
            <?php endif; ?>

            <?php
            // Flash messages. Both legacy type names are accepted so a stray
            // flash('danger') can never again vanish without a trace.
            $flashMap = [
                'success' => ['success', 'ri-checkbox-circle-line'],
                'error'   => ['danger',  'ri-error-warning-line'],
                'danger'  => ['danger',  'ri-error-warning-line'],
                'warning' => ['warning', 'ri-alert-line'],
                'info'    => ['info',    'ri-information-line'],
            ];
            foreach ($flashMap as $type => [$tone, $icon]):
                if ($msg = flash($type)):
            ?>
                <div class="alert alert--<?= $tone ?>" role="<?= $tone === 'danger' ? 'alert' : 'status' ?>">
                    <i class="alert__icon <?= $icon ?>" aria-hidden="true"></i>
                    <div class="alert__body"><?= e($msg) ?></div>
                    <button type="button" class="alert__close" aria-label="Dismiss">
                        <i class="ri-close-line" aria-hidden="true"></i>
                    </button>
                </div>
            <?php endif; endforeach; ?>
