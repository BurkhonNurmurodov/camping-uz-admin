<?php
/**
 * Admin sidebar (Urbix .pe-app-sidebar look) with our own nav.
 * Expects $page['active'] and $nav_* badge counts from head.php.
 */
$active = $page['active'] ?? '';
$is = static fn(string $k): string => $active === $k ? ' active' : '';
$logoImage = setting('logo_image');
$logoLight = setting('logo_image_light');
?>
<aside class="pe-app-sidebar" id="sidebar">
    <div class="pe-app-sidebar-logo px-6 d-flex align-items-center position-relative">
        <a href="<?= BASE_PATH ?>/index" class="d-flex align-items-center logo-main text-decoration-none" style="max-width:100%; overflow:hidden;">
            <?php if ($logoImage || $logoLight): ?>
                <?php if ($logoImage): ?>
                    <img src="<?= e(upload_url($logoImage)) ?>" alt="Logo" class="logo-dark" style="max-height: 34px; max-width: 100%; object-fit: contain;">
                <?php endif; ?>
                <?php if ($logoLight): ?>
                    <img src="<?= e(upload_url($logoLight)) ?>" alt="Logo" class="logo-light" style="max-height: 34px; max-width: 100%; object-fit: contain;">
                <?php endif; ?>
                <?php if (!$logoLight && $logoImage): ?>
                    <img src="<?= e(upload_url($logoImage)) ?>" alt="Logo" class="logo-light" style="max-height: 34px; max-width: 100%; object-fit: contain;">
                <?php endif; ?>
                <?php if (!$logoImage && $logoLight): ?>
                    <img src="<?= e(upload_url($logoLight)) ?>" alt="Logo" class="logo-dark" style="max-height: 34px; max-width: 100%; object-fit: contain;">
                <?php endif; ?>
            <?php else: ?>
                <span class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded fw-bold flex-shrink-0"
                      style="width:34px;height:34px;">SN</span>
                <span class="text-body-emphasis fw-bolder mb-0 ms-2 fs-16 text-truncate">Silk&nbsp;Naviora</span>
            <?php endif; ?>
        </a>
    </div>

    <nav class="pe-app-sidebar-menu nav nav-pills" data-simplebar id="sidebar-simplebar">
        <div class="d-flex align-items-start flex-column w-100">
            <ul class="pe-main-menu list-unstyled">

                <li class="pe-menu-title">Main</li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/index" class="pe-nav-link<?= $is('dashboard') ?>">
                        <i class="ri-dashboard-line pe-nav-icon"></i>
                        <span class="pe-nav-content"><?= e(t('admin_dashboard')) ?></span>
                    </a>
                </li>

                <li class="pe-menu-title">Content</li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/tours" class="pe-nav-link<?= $is('tours') ?>">
                        <i class="ri-route-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Tours</span>
                    </a>
                </li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/guides" class="pe-nav-link<?= $is('guides') ?>">
                        <i class="ri-user-star-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Guides</span>
                    </a>
                </li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/categories" class="pe-nav-link<?= $is('categories') ?>">
                        <i class="ri-price-tag-3-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Categories</span>
                    </a>
                </li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/testimonials" class="pe-nav-link<?= $is('testimonials') ?>">
                        <i class="ri-chat-quote-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Testimonials</span>
                    </a>
                </li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/about" class="pe-nav-link<?= $is('about') ?>">
                        <i class="ri-information-line pe-nav-icon"></i>
                        <span class="pe-nav-content">About page</span>
                    </a>
                </li>

                <li class="pe-menu-title">Inbox</li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/registrations" class="pe-nav-link<?= $is('registrations') ?>">
                        <i class="ri-group-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Registrations</span>
                        <?php if (!empty($nav_new_regs)): ?>
                            <span class="badge bg-primary rounded-pill ms-auto"><?= (int) $nav_new_regs ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/private-requests" class="pe-nav-link<?= $is('private-requests') ?>">
                        <i class="ri-vip-diamond-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Private Requests</span>
                        <?php if (!empty($nav_new_privates)): ?>
                            <span class="badge bg-warning rounded-pill ms-auto text-dark"><?= (int) $nav_new_privates ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/messages" class="pe-nav-link<?= $is('messages') ?>">
                        <i class="ri-mail-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Messages</span>
                        <?php if (!empty($nav_unread_msgs)): ?>
                            <span class="badge bg-danger rounded-pill ms-auto"><?= (int) $nav_unread_msgs ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="pe-menu-title">System</li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/settings" class="pe-nav-link<?= $is('settings') ?>">
                        <i class="ri-settings-3-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Settings</span>
                    </a>
                </li>
                <li class="pe-slide">
                    <a href="<?= BASE_PATH ?>/logout" class="pe-nav-link">
                        <i class="ri-logout-box-line pe-nav-icon"></i>
                        <span class="pe-nav-content"><?= e(t('admin_logout')) ?></span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>
</aside>
