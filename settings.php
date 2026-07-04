<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';

$me = admin_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('action', 'general');

    if ($action === 'general') {
        // Hero media
        $heroType = input('hero_type') === 'video' ? 'video' : 'image';
        set_setting('hero_type', $heroType);

        if (input('remove_hero_image') == '1') {
            delete_upload(setting('hero_image')); set_setting('hero_image', '');
        } elseif ($async = input('async_hero_image')) {
            delete_upload(setting('hero_image')); set_setting('hero_image', $async);
        } elseif (!empty($_FILES['hero_image']['name'])) {
            [$ok, $res] = save_image($_FILES['hero_image'], 'hero', 10);
            if ($ok) { delete_upload(setting('hero_image')); set_setting('hero_image', $res); }
            else { flash('error', 'Hero image: ' . $res); }
        }
        
        if (input('remove_hero_video') == '1') {
            delete_upload(setting('hero_video')); set_setting('hero_video', '');
        } elseif ($async = input('async_hero_video')) {
            delete_upload(setting('hero_video')); set_setting('hero_video', $async);
        } elseif (!empty($_FILES['hero_video']['name'])) {
            [$ok, $res] = save_video($_FILES['hero_video'], 'hero', 60);
            if ($ok) { delete_upload(setting('hero_video')); set_setting('hero_video', $res); }
            else { flash('error', 'Hero video: ' . $res); }
        }

        if (input('remove_logo_image') == '1') {
            delete_upload(setting('logo_image')); set_setting('logo_image', '');
        } elseif ($async = input('async_logo_image')) {
            delete_upload(setting('logo_image')); set_setting('logo_image', $async);
        } elseif (!empty($_FILES['logo_image']['name'])) {
            [$ok, $res] = save_image($_FILES['logo_image'], 'logo', 5);
            if ($ok) { delete_upload(setting('logo_image')); set_setting('logo_image', $res); }
            else { flash('error', 'Logo image: ' . $res); }
        }

        if (input('remove_logo_image_light') == '1') {
            delete_upload(setting('logo_image_light')); set_setting('logo_image_light', '');
        } elseif ($async = input('async_logo_image_light')) {
            delete_upload(setting('logo_image_light')); set_setting('logo_image_light', $async);
        } elseif (!empty($_FILES['logo_image_light']['name'])) {
            [$ok, $res] = save_image($_FILES['logo_image_light'], 'logo', 5);
            if ($ok) { delete_upload(setting('logo_image_light')); set_setting('logo_image_light', $res); }
            else { flash('error', 'Logo Light: ' . $res); }
        }

        if (input('remove_favicon') == '1') {
            delete_upload(setting('favicon')); set_setting('favicon', '');
        } elseif ($async = input('async_favicon')) {
            delete_upload(setting('favicon')); set_setting('favicon', $async);
        } elseif (!empty($_FILES['favicon']['name'])) {
            [$ok, $res] = save_image($_FILES['favicon'], 'favicon', 2);
            if ($ok) { delete_upload(setting('favicon')); set_setting('favicon', $res); }
            else { flash('error', 'Favicon: ' . $res); }
        }

        foreach ([
            'agency_name_en', 'agency_name_ru', 'moto_en', 'moto_ru',
            'social_instagram', 'social_telegram', 'social_facebook', 'social_whatsapp',
            'telegram_bot_token', 'telegram_chat_id', 'google_maps_api_key',
        ] as $k) {
            set_setting($k, trim((string) input($k, '')));
        }
        $dl = input('default_lang');
        set_setting('default_lang', in_array($dl, supported_langs(), true) ? $dl : DEFAULT_LANG);

        flash('success', 'Settings saved.');
        redirect('settings');
    }

    if ($action === 'credentials') {
        $cur = (string) input('current_password', '');
        $newUser = trim((string) input('new_username', ''));
        $newPass = (string) input('new_password', '');
        $confirm = (string) input('confirm_password', '');

        $row = db_one('SELECT password_hash FROM admins WHERE id = ?', [$me['id']]);
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif ($newUser === '') {
            flash('error', 'Username cannot be empty.');
        } elseif ($newPass !== '' && $newPass !== $confirm) {
            flash('error', 'New passwords do not match.');
        } elseif ($newPass !== '' && strlen($newPass) < 6) {
            flash('error', 'New password must be at least 6 characters.');
        } else {
            admin_update_credentials((int) $me['id'], $newUser, $newPass !== '' ? $newPass : null);
            flash('success', 'Login updated.');
        }
        redirect('settings');
    }

    if ($action === 'test_telegram') {
        $r = telegram_send('🔔 <b>Silk Naviora</b> — test notification. If you can read this, notifications are configured correctly.');
        if ($r['ok']) {
            flash('success', 'Test message sent — check your Telegram.');
        } else {
            flash('error', 'Telegram test failed: ' . $r['error']);
        }
        redirect('settings');
    }
}

$page = ['title' => 'Settings', 'section' => 'System', 'active' => 'settings'];
require __DIR__ . '/partials/head.php';

$heroType  = setting('hero_type', 'image');
$heroImage = setting('hero_image');
$heroVideo = setting('hero_video');
?>
<form method="post" enctype="multipart/form-data" action="settings">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="general">
    <div class="row g-3">
        <!-- Identity -->
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Agency identity</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name (EN)</label>
                            <input type="text" name="agency_name_en" class="form-control" value="<?= e(setting('agency_name_en', '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Name (RU)</label>
                            <input type="text" name="agency_name_ru" class="form-control" value="<?= e(setting('agency_name_ru', '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Moto / tagline (EN)</label>
                            <input type="text" name="moto_en" class="form-control" value="<?= e(setting('moto_en', '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Moto / tagline (RU)</label>
                            <input type="text" name="moto_ru" class="form-control" value="<?= e(setting('moto_ru', '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Default language</label>
                            <select name="default_lang" class="form-select">
                                <?php foreach (['en' => 'English', 'ru' => 'Русский'] as $c => $l): ?>
                                    <option value="<?= $c ?>" <?= setting('default_lang', 'en') === $c ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hero -->
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Hero background</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label d-block">Type</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="hero_type" id="ht_img" value="image" <?= $heroType !== 'video' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ht_img">Image</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="hero_type" id="ht_vid" value="video" <?= $heroType === 'video' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ht_vid">Video</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Hero image <span class="text-muted fs-12">(JPG/PNG/WebP, ≤10MB)</span></label>
                            <label class="dnd-upload-wrap <?= $heroImage ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">JPG, PNG, WebP (≤10MB)</div>
                                <input type="checkbox" name="remove_hero_image" id="rm_hero" value="1" class="d-none">
                                <input type="file" name="hero_image" accept="image/*" data-remove-target="rm_hero">
                                <div class="dnd-preview-container">
                                    <?php if ($heroImage): ?>
                                        <img src="<?= e(upload_url($heroImage)) ?>" class="dnd-preview-img">
                                    <?php endif; ?>
                                </div>
                                <div class="dnd-loader">
                                    <div class="spinner-border text-primary" role="status"></div>
                                </div>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Hero video <span class="text-muted fs-12">(MP4/WebM, ≤60MB)</span></label>
                            <label class="dnd-upload-wrap <?= $heroVideo ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">MP4, WebM (≤60MB)</div>
                                <input type="checkbox" name="remove_hero_video" id="rm_hero_vid" value="1" class="d-none">
                                <input type="file" name="hero_video" accept="video/*" data-remove-target="rm_hero_vid">
                                <div class="dnd-preview-container">
                                    <?php if ($heroVideo): ?>
                                        <span class="dnd-filename fw-bold text-success"><i class="ri-check-line"></i> Video uploaded</span>
                                    <?php endif; ?>
                                </div>
                                <div class="dnd-loader">
                                    <div class="spinner-border text-primary" role="status"></div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Logos -->
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Site Logos</h5></div>
                <div class="card-body">
                    <div class="row gy-4">
                        <div class="col-12">
                            <label class="form-label">Logo image (Dark text / Default) <span class="text-muted fs-12">(PNG/JPG/WebP, transparent bg recommended)</span></label>
                            <label class="dnd-upload-wrap <?= setting('logo_image') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">PNG, JPG, WebP</div>
                                <input type="checkbox" name="remove_logo_image" id="rm_logo" value="1" class="d-none">
                                <input type="file" name="logo_image" accept="image/*" data-remove-target="rm_logo">
                                <div class="dnd-preview-container">
                                    <?php if ($logoImage = setting('logo_image')): ?>
                                        <div class="p-2 bg-light rounded d-inline-block border">
                                            <img src="<?= e(upload_url($logoImage)) ?>" style="max-height:50px">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="dnd-loader">
                                    <div class="dnd-progress">
                                        <div class="dnd-progress-bar"></div>
                                        <div class="dnd-progress-text">0%</div>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Logo image (Light / White text) <span class="text-muted fs-12">Used on dark hero backgrounds</span></label>
                            <label class="dnd-upload-wrap <?= setting('logo_image_light') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">PNG, JPG, WebP</div>
                                <input type="checkbox" name="remove_logo_image_light" id="rm_logo_light" value="1" class="d-none">
                                <input type="file" name="logo_image_light" accept="image/*" data-remove-target="rm_logo_light">
                                <div class="dnd-preview-container">
                                    <?php if ($logoLight = setting('logo_image_light')): ?>
                                        <div class="p-2 bg-dark rounded d-inline-block">
                                            <img src="<?= e(upload_url($logoLight)) ?>" style="max-height:50px">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="dnd-loader">
                                    <div class="dnd-progress">
                                        <div class="dnd-progress-bar"></div>
                                        <div class="dnd-progress-text">0%</div>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Favicon <span class="text-muted fs-12">(ICO/PNG/WebP, small size)</span></label>
                            <label class="dnd-upload-wrap <?= setting('favicon') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">ICO, PNG, WebP</div>
                                <input type="checkbox" name="remove_favicon" id="rm_favicon" value="1" class="d-none">
                                <input type="file" name="favicon" accept="image/*,.ico" data-remove-target="rm_favicon">
                                <div class="dnd-preview-container">
                                    <?php if ($favicon = setting('favicon')): ?>
                                        <div class="p-2 bg-light rounded d-inline-block border">
                                            <img src="<?= e(upload_url($favicon)) ?>" style="max-height:32px">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="dnd-loader">
                                    <div class="dnd-progress">
                                        <div class="dnd-progress-bar"></div>
                                        <div class="dnd-progress-text">0%</div>
                                    </div>
                                </div>
                            </label>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Socials -->
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Social links</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ([
                            'social_instagram' => ['Instagram', 'ri-instagram-line'],
                            'social_telegram'  => ['Telegram', 'ri-telegram-line'],
                            'social_facebook'  => ['Facebook', 'ri-facebook-circle-line'],
                            'social_whatsapp'  => ['WhatsApp', 'ri-whatsapp-line'],
                        ] as $k => [$label, $icon]): ?>
                            <div class="col-md-6">
                                <label class="form-label"><i class="<?= $icon ?> me-1"></i><?= $label ?></label>
                                <input type="text" name="<?= $k ?>" class="form-control" placeholder="URL or @username" value="<?= e(setting($k, '')) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Integrations -->
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Integrations</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Telegram bot token</label>
                        <input type="text" name="telegram_bot_token" class="form-control" value="<?= e(setting('telegram_bot_token', '')) ?>" placeholder="123456:ABC-...">
                        <div class="form-text">New registrations &amp; messages ping this bot.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Telegram chat ID</label>
                        <input type="text" name="telegram_chat_id" class="form-control" value="<?= e(setting('telegram_chat_id', '')) ?>" placeholder="e.g. 12345678 or -100…">
                        <div class="form-text">
                            DM the bot (or add it to your group) first. Get the id from
                            <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a>.
                            <button type="submit" form="tgTestForm" class="btn btn-sm btn-outline-primary ms-2">
                                <i class="ri-send-plane-line"></i> Send test message
                            </button>
                            <span class="text-muted">(save first)</span>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Google Maps API Key</label>
                        <input type="text" name="google_maps_api_key" class="form-control" value="<?= e(setting('google_maps_api_key', '')) ?>">
                        <div class="form-text">Used for the tour route picker and public map.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="my-3">
        <button class="btn btn-primary"><i class="ri-save-line me-1"></i> Save settings</button>
    </div>
</form>

<!-- Standalone form for the Telegram test button (lives outside the main form) -->
<form id="tgTestForm" method="post" action="settings" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_telegram">
</form>

<!-- Change login -->
<div class="card">
    <div class="card-header"><h5 class="card-title mb-0">Change login</h5></div>
    <div class="card-body">
        <form method="post" action="settings" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="credentials">
            <div class="col-md-4">
                <label class="form-label">Current password <span class="text-danger">*</span></label>
                <input type="password" name="current_password" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">New username</label>
                <input type="text" name="new_username" class="form-control" value="<?= e($me['username']) ?>">
            </div>
            <div class="col-md-4"></div>
            <div class="col-md-4">
                <label class="form-label">New password <span class="text-muted fs-12">(leave blank to keep)</span></label>
                <input type="password" name="new_password" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Confirm new password</label>
                <input type="password" name="confirm_password" class="form-control">
            </div>
            <div class="col-12">
                <button class="btn btn-outline-primary"><i class="ri-lock-line me-1"></i> Update login</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
