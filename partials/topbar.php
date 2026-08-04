<?php
/**
 * Topbar.
 *
 * Deliberately holds no page title. The old build made the operator's own
 * name the page <h1>, which pushed the actual page identity down to an <h2>
 * smaller than its own breadcrumb. Here the topbar carries controls only and
 * the page names itself, once, in the content column.
 *
 * Expects $nav_* counts and $me from head.php.
 */
$inboxLinks = [
    ['href' => url('registrations'),    'label' => 'New registrations',  'icon' => 'ri-group-line',       'count' => $nav_new_regs],
    ['href' => url('private-requests'), 'label' => 'Private requests',   'icon' => 'ri-vip-diamond-line', 'count' => $nav_new_privates],
    ['href' => url('messages'),         'label' => 'Unanswered messages','icon' => 'ri-question-answer-line', 'count' => $nav_unread_msgs],
];
?>
<header class="topbar">
    <button type="button" class="icon-btn" id="navToggle"
            aria-controls="sidebar" aria-expanded="false" title="Toggle sidebar">
        <i class="ri-menu-line" aria-hidden="true"></i>
        <span class="sr-only">Toggle sidebar</span>
    </button>

    <div class="topbar__spacer"></div>

    <!-- Inbox. Lists every queue the sidebar badges, so the two can never
         disagree the way they used to. -->
    <div class="dropdown">
        <button type="button" class="icon-btn" data-menu aria-controls="inboxMenu" aria-expanded="false"
                title="Inbox">
            <i class="ri-notification-3-line" aria-hidden="true"></i>
            <?php if ($nav_inbox_total > 0): ?>
                <span class="icon-btn__dot"><?= $nav_inbox_total > 99 ? '99+' : (int) $nav_inbox_total ?></span>
            <?php endif; ?>
            <span class="sr-only">
                Inbox<?= $nav_inbox_total > 0 ? ', ' . (int) $nav_inbox_total . ' items need attention' : ', nothing pending' ?>
            </span>
        </button>

        <div class="menu menu--end" id="inboxMenu" style="min-width:288px">
            <div class="menu__head row-flex">
                <span class="t-semibold t-strong">Needs attention</span>
                <span class="push-end t-xs t-muted"><?= (int) $nav_inbox_total ?> total</span>
            </div>
            <div class="menu__sep"></div>
            <?php foreach ($inboxLinks as $link): ?>
                <a class="menu__item" href="<?= e($link['href']) ?>">
                    <i class="<?= e($link['icon']) ?>" aria-hidden="true"></i>
                    <span><?= e($link['label']) ?></span>
                    <span class="menu__count<?= $link['count'] > 0 ? ' menu__count--alert' : '' ?>">
                        <?= (int) $link['count'] ?>
                    </span>
                </a>
            <?php endforeach; ?>
            <div class="menu__sep"></div>
            <a class="menu__item" href="<?= url('email') ?>">
                <i class="ri-mail-line" aria-hidden="true"></i>
                <span>Open Mail</span>
            </a>
        </div>
    </div>

    <!-- Theme. Three explicit states: pressing "Light" always means light. -->
    <div class="theme-switch" role="group" aria-label="Colour theme">
        <button type="button" class="theme-switch__btn" data-set-theme="light" aria-pressed="false" title="Light theme">
            <i class="ri-sun-line" aria-hidden="true"></i><span class="sr-only">Light theme</span>
        </button>
        <button type="button" class="theme-switch__btn" data-set-theme="dark" aria-pressed="false" title="Dark theme">
            <i class="ri-moon-line" aria-hidden="true"></i><span class="sr-only">Dark theme</span>
        </button>
        <button type="button" class="theme-switch__btn" data-set-theme="system" aria-pressed="false" title="Match system">
            <i class="ri-computer-line" aria-hidden="true"></i><span class="sr-only">Match system theme</span>
        </button>
    </div>

    <div class="dropdown">
        <button type="button" class="user-btn" data-menu aria-controls="userMenu" aria-expanded="false">
            <?= ui_avatar($me['display_name'] ?? $me['username'] ?? 'A') ?>
            <span class="text-start hide-sm-down">
                <span class="user-btn__name d-block"><?= e($me['display_name'] ?? $me['username'] ?? 'Admin') ?></span>
                <span class="user-btn__role d-block">@<?= e($me['username'] ?? 'admin') ?></span>
            </span>
            <i class="ri-arrow-down-s-line t-muted hide-sm-down" aria-hidden="true"></i>
            <span class="sr-only">Account menu</span>
        </button>

        <div class="menu menu--end" id="userMenu">
            <a class="menu__item" href="<?= url('account') ?>">
                <i class="ri-shield-user-line" aria-hidden="true"></i> Account &amp; security
            </a>
            <a class="menu__item" href="<?= url('settings') ?>">
                <i class="ri-settings-3-line" aria-hidden="true"></i> Settings
            </a>
            <a class="menu__item" href="<?= e(public_site_url()) ?>" target="_blank" rel="noopener">
                <i class="ri-external-link-line" aria-hidden="true"></i> View live site
            </a>
            <div class="menu__sep"></div>
            <a class="menu__item menu__item--danger" href="<?= url('logout') ?>">
                <i class="ri-logout-box-line" aria-hidden="true"></i> Sign out
            </a>
        </div>
    </div>
</header>
