<?php
/**
 * Admin top header (Urbix .app-header look), trimmed to what we use:
 * sidebar toggle, inbox bell with live unread count, dark-mode toggle, profile.
 * Expects $nav_* counts from head.php.
 */
$me = admin_user();
?>
<header class="app-header" id="appHeader">
    <div class="container-fluid w-100">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-inline-flex align-items-center gap-2">
                <button type="button" class="vertical-toggle btn header-btn" id="toggleSidebar" aria-label="Toggle Sidebar">
                    <i class="bi bi-arrow-bar-left header-icon"></i>
                </button>
                <h1 class="fs-16 fw-semibold mb-0 d-none d-md-block"><?= e($me['display_name'] ?? 'Welcome') ?></h1>
            </div>

            <div class="flex-shrink-0 d-flex align-items-center gap-3">
                <!-- Inbox -->
                <div class="dropdown pe-dropdown-mega">
                    <button class="btn header-btn position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Inbox">
                        <i class="bi bi-bell"></i>
                        <?php if (!empty($nav_inbox_total)): ?><div class="icon-dot"></div><?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end header-dropdown-menu p-0" style="min-width:280px;">
                        <div class="p-3 border-bottom d-flex align-items-center">
                            <h6 class="mb-0">Inbox</h6>
                            <span class="badge bg-primary-subtle text-primary ms-auto"><?= (int) $nav_inbox_total ?> new</span>
                        </div>
                        <ul class="list-unstyled mb-0 p-2">
                            <li>
                                <a class="dropdown-item d-flex align-items-center rounded py-2" href="<?= BASE_PATH ?>/registrations">
                                    <i class="ri-group-line fs-18 me-2 text-primary"></i>
                                    <span>New registrations</span>
                                    <span class="badge bg-primary rounded-pill ms-auto"><?= (int) $nav_new_regs ?></span>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item d-flex align-items-center rounded py-2" href="<?= BASE_PATH ?>/messages">
                                    <i class="ri-mail-line fs-18 me-2 text-danger"></i>
                                    <span>Unanswered messages</span>
                                    <span class="badge bg-danger rounded-pill ms-auto"><?= (int) $nav_unread_msgs ?></span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Dark mode -->
                <div class="dark-mode-btn" id="toggleMode">
                    <button class="btn header-btn active" id="lightModeBtn" type="button" aria-label="Light mode">
                        <i class="bi bi-brightness-high"></i>
                    </button>
                    <button class="btn header-btn" id="darkModeBtn" type="button" aria-label="Dark mode">
                        <i class="bi bi-moon-stars"></i>
                    </button>
                </div>

                <!-- Profile -->
                <div class="dropdown">
                    <button class="header-profile-btn btn gap-1 text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="d-none d-xl-block pe-2">
                            <span class="d-block mb-0 fs-12 fw-semibold"><?= e($me['display_name'] ?? $me['username'] ?? 'Admin') ?></span>
                            <span class="d-block mb-0 fs-10 text-muted">@<?= e($me['username'] ?? 'admin') ?></span>
                        </div>
                        <span class="header-btn btn position-relative">
                            <i class="bi bi-person-circle fs-20"></i>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end header-dropdown-menu p-2">
                        <a class="dropdown-item rounded" href="<?= BASE_PATH ?>/settings"><i class="bi bi-gear me-2"></i> Settings</a>
                        <a class="dropdown-item rounded" href="<?= BASE_PATH ?>/logout"><i class="bi bi-box-arrow-right me-2"></i> <?= e(t('admin_logout')) ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
