<?php
/**
 * Sidebar.
 *
 * Nav items are plain anchors and nothing intercepts their clicks — the old
 * build called preventDefault() on every one of these in collapsed mode,
 * which silently killed navigation until the sidebar was expanded again.
 *
 * Expects $nav_groups and $page['active'] from head.php.
 */
$active    = $page['active'] ?? '';
$logoDark  = setting('logo_image');        // for light UI
$logoLight = setting('logo_image_light');  // for the navy sidebar
$sidebarLogo = $logoLight ?: $logoDark;
?>
<aside class="sidebar" id="sidebar">
    <a href="<?= url('index') ?>" class="sidebar__brand">
        <?php if ($sidebarLogo): ?>
            <img src="<?= e(upload_url($sidebarLogo)) ?>" alt="Silk Naviora" class="sidebar__logo-img">
        <?php else: ?>
            <span class="sidebar__mark" aria-hidden="true">SN</span>
            <span class="sidebar__wordmark">Silk Naviora</span>
        <?php endif; ?>
    </a>

    <nav class="sidebar__nav" aria-label="Main">
        <?php foreach ($nav_groups as $group): ?>
            <div class="sidebar__group">
                <p class="sidebar__group-label"><span><?= e($group['label']) ?></span></p>
                <ul class="sidebar__list">
                    <?php foreach ($group['items'] as $item):
                        $isActive = $active === $item['key'];
                        $count    = (int) ($item['count'] ?? 0);
                    ?>
                        <li>
                            <a href="<?= e($item['href']) ?>"
                               class="nav-item<?= $isActive ? ' is-active' : '' ?>"
                               data-tip="<?= e($item['label']) ?>"
                               <?= $isActive ? 'aria-current="page"' : '' ?>>
                                <i class="nav-item__icon <?= e($item['icon']) ?>" aria-hidden="true"></i>
                                <span class="nav-item__label"><?= e($item['label']) ?></span>
                                <?php if ($count > 0): ?>
                                    <span class="nav-item__count">
                                        <?= $count > 99 ? '99+' : $count ?>
                                        <span class="sr-only">unhandled</span>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__footer">
        <a href="<?= e(public_site_url()) ?>"
           class="nav-item" target="_blank" rel="noopener" data-tip="View live site">
            <i class="nav-item__icon ri-external-link-line" aria-hidden="true"></i>
            <span class="nav-item__label">View live site</span>
        </a>
    </div>
</aside>
