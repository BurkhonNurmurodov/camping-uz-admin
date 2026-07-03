<?php
/**
 * Admin <head> + open of the layout shell (ported from Urbix).
 * Expects optional $page:
 *   'title'      => string  (page + breadcrumb leaf)
 *   'section'    => string  (breadcrumb parent, e.g. "Content")
 *   'active'     => string  (sidebar key to highlight)
 *   'vendor_css' => string[] (hrefs relative to admin/assets)
 */
$page = ($page ?? []) + ['title' => 'Admin', 'section' => '', 'active' => '', 'vendor_css' => []];
$A = BASE_PATH . '/assets'; // admin asset base

// Unread badges shown in the sidebar + header (tolerate a not-yet-migrated DB).
try {
    $nav_new_regs = db_one("SELECT COUNT(*) as c FROM registration_groups WHERE status='new'")['c'] ?? 0;
    $nav_new_privates = db_one("SELECT COUNT(*) as c FROM private_tour_requests WHERE status='new'")['c'] ?? 0;
    $nav_unread_msgs = db_one("SELECT COUNT(*) as c FROM contact_messages WHERE status='unanswered'")['c'] ?? 0;
} catch (Throwable $e) {
    $nav_new_regs = $nav_new_privates = $nav_unread_msgs = 0;
}
$nav_inbox_total = $nav_new_regs + $nav_new_privates + $nav_unread_msgs;
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" data-layout="vertical" data-bs-theme="light"
      data-content-width="default" dir="ltr" data-sidebar-color="light" data-sidebar="default">
<head>
    <meta charset="utf-8">
    <title><?= e($page['title']) ?> | Silk Naviora Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">

    <!-- layout setup (theme / sidebar / dark-mode) -->
    <script type="module" src="<?= $A ?>/js/layout-setup.js"></script>

    <link rel="shortcut icon" href="<?= $A ?>/images/favicon.png">

    <!-- vendor css -->
    <link rel="stylesheet" href="<?= $A ?>/libs/simplebar/simplebar.min.css">
    <?php foreach ($page['vendor_css'] as $href): ?>
        <link rel="stylesheet" href="<?= $A ?>/<?= e($href) ?>">
    <?php endforeach; ?>

    <!-- theme css -->
    <link href="<?= $A ?>/css/bootstrap.min.css" id="bootstrap-style" rel="stylesheet">
    <link href="<?= $A ?>/css/icons.css" rel="stylesheet">
    <link href="<?= $A ?>/css/app.min.css" id="app-style" rel="stylesheet">
    <link href="<?= $A ?>/css/admin-extra.css" rel="stylesheet">
</head>
<body>
<div id="layout-wrapper">
    <?php require __DIR__ . '/header.php'; ?>
    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="app-wrapper">
        <div class="container-fluid">
            <div class="main-breadcrumb d-flex align-items-center my-3 position-relative">
                <h2 class="breadcrumb-title mb-0 flex-grow-1 fs-14"><?= e($page['title']) ?></h2>
                <div class="flex-shrink-0">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-end mb-0">
                            <li class="breadcrumb-item"><a href="<?= BASE_PATH ?>/">Admin</a></li>
                            <?php if ($page['section']): ?>
                                <li class="breadcrumb-item"><?= e($page['section']) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active" aria-current="page"><?= e($page['title']) ?></li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php foreach (['success', 'error'] as $ft): if ($fm = flash($ft)): ?>
                <div class="alert alert-<?= $ft === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                    <?= e($fm) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; endforeach; ?>
