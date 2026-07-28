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

        // Logos & Favicon
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

        // Identity and social links only
        foreach ([
            'agency_name_en', 'agency_name_ru', 'moto_en', 'moto_ru',
            'social_instagram', 'social_telegram', 'social_facebook', 'social_whatsapp'
        ] as $k) {
            set_setting($k, trim((string) input($k, '')));
        }

        $dl = input('default_lang');
        set_setting('default_lang', in_array($dl, supported_langs(), true) ? $dl : DEFAULT_LANG);

        flash('success', 'General branding and site settings saved successfully.');
        redirect('settings');
    }
}

$page = ['title' => 'General Settings', 'section' => 'System', 'active' => 'settings'];
require __DIR__ . '/partials/head.php';

$heroType  = setting('hero_type', 'image');
$heroImage = setting('hero_image');
$heroVideo = setting('hero_video');
?>

<style>
    .settings-card {
        border: 1px solid rgba(0,0,0,0.08);
        border-radius: 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        transition: transform 0.2s, box-shadow 0.2s;
        overflow: hidden;
    }
    .settings-header {
        background: #f8fafc;
        border-bottom: 1px solid rgba(0,0,0,0.06);
        padding: 18px 24px;
    }
</style>

<form method="post" enctype="multipart/form-data" action="settings">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="general">
    
    <div class="row g-4">
        <!-- Left Column: Identity & Social Profiles -->
        <div class="col-12 col-xl-6 d-flex flex-column gap-4">
            <!-- Agency Identity -->
            <div class="card settings-card">
                <div class="settings-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="ri-store-2-line fs-22"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-0 fw-bold">Agency Identity &amp; Localization</h5>
                            <span class="text-muted fs-13">Configure brand title, slogans, and site language</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Agency Name (English)</label>
                            <input type="text" name="agency_name_en" class="form-control" value="<?= e(setting('agency_name_en', '')) ?>" placeholder="Silk Naviora">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Agency Name (Russian)</label>
                            <input type="text" name="agency_name_ru" class="form-control" value="<?= e(setting('agency_name_ru', '')) ?>" placeholder="Silk Naviora RU">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Motto / Tagline (English)</label>
                            <input type="text" name="moto_en" class="form-control" value="<?= e(setting('moto_en', '')) ?>" placeholder="Discover Authentic Adventures">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Motto / Tagline (Russian)</label>
                            <input type="text" name="moto_ru" class="form-control" value="<?= e(setting('moto_ru', '')) ?>" placeholder="Откройте подлинные приключения">
                        </div>
                        <div class="col-12 pt-2 border-top mt-3">
                            <label class="form-label fw-medium fs-13">Default Website Language</label>
                            <select name="default_lang" class="form-select w-auto min-w-200px">
                                <?php foreach (['en' => 'English (EN)', 'ru' => 'Русский (RU)'] as $c => $l): ?>
                                    <option value="<?= $c ?>" <?= setting('default_lang', 'en') === $c ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text fs-12">Primary display language for first-time visitors.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Social Links -->
            <div class="card settings-card flex-grow-1">
                <div class="settings-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="ri-share-forward-box-line fs-22"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-0 fw-bold">Social Media Links</h5>
                            <span class="text-muted fs-13">Connect your brand across public communication channels</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <?php foreach ([
                            'social_instagram' => ['Instagram Profile', 'ri-instagram-line', '#e1306c', 'https://instagram.com/your_handle'],
                            'social_telegram'  => ['Telegram Channel/Bot', 'ri-telegram-line', '#0088cc', 'https://t.me/your_channel'],
                            'social_facebook'  => ['Facebook Page', 'ri-facebook-circle-line', '#1877f2', 'https://facebook.com/your_page'],
                            'social_whatsapp'  => ['WhatsApp Contact', 'ri-whatsapp-line', '#25d366', 'https://wa.me/998900000000'],
                        ] as $k => [$label, $icon, $color, $placeholder]): ?>
                            <div class="col-md-6">
                                <label class="form-label fw-medium fs-13 d-flex align-items-center">
                                    <i class="<?= $icon ?> me-2 fs-16" style="color: <?= $color ?>;"></i> <?= $label ?>
                                </label>
                                <input type="text" name="<?= $k ?>" class="form-control fs-13" placeholder="<?= $placeholder ?>" value="<?= e(setting($k, '')) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Branding Media (Hero Background & Site Logos) -->
        <div class="col-12 col-xl-6 d-flex flex-column gap-4">
            <!-- Hero Background -->
            <div class="card settings-card">
                <div class="settings-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="ri-image-edit-line fs-22"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-0 fw-bold">Hero Showcase Background</h5>
                            <span class="text-muted fs-13">Choose either a high-resolution image or immersive looping video</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3 p-3 bg-light-subtle rounded-3 border d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <span class="fw-medium text-dark fs-13">Hero Background Type:</span>
                        <div>
                            <div class="form-check form-check-inline me-4">
                                <input class="form-check-input cursor-pointer" type="radio" name="hero_type" id="ht_img" value="image" <?= $heroType !== 'video' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium cursor-pointer" for="ht_img"><i class="ri-image-line me-1 text-primary"></i> Static Image</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input cursor-pointer" type="radio" name="hero_type" id="ht_vid" value="video" <?= $heroType === 'video' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium cursor-pointer" for="ht_vid"><i class="ri-video-line me-1 text-success"></i> Looping Video</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium fs-13">Hero Cover Image <span class="text-muted fs-12">(JPG/PNG/WebP, ≤10MB)</span></label>
                            <label class="dnd-upload-wrap <?= $heroImage ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">Recommended resolution: 1920x1080px (≤10MB)</div>
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
                            <label class="form-label fw-medium fs-13">Hero Background Video <span class="text-muted fs-12">(MP4/WebM, ≤60MB)</span></label>
                            <label class="dnd-upload-wrap <?= $heroVideo ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload video</div>
                                <div class="dnd-upload-subtext">MP4, WebM (≤60MB, optimized for fast streaming)</div>
                                <input type="checkbox" name="remove_hero_video" id="rm_hero_vid" value="1" class="d-none">
                                <input type="file" name="hero_video" accept="video/*" data-remove-target="rm_hero_vid">
                                <div class="dnd-preview-container">
                                    <?php if ($heroVideo): ?>
                                        <div class="p-3 bg-success-subtle text-success border rounded-3 text-center fw-bold">
                                            <i class="ri-check-double-line me-1 fs-18"></i> Active background video uploaded and ready
                                        </div>
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

            <!-- Site Logos & Favicon -->
            <div class="card settings-card flex-grow-1">
                <div class="settings-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-warning bg-opacity-10 text-warning rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="ri-vip-diamond-line fs-22"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-0 fw-bold">Site Logos &amp; Favicon</h5>
                            <span class="text-muted fs-13">Upload logo variations for navigation menus and browser tabs</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <!-- Dark / Default Logo -->
                        <div class="col-12">
                            <label class="form-label fw-medium fs-13">Primary Logo <span class="text-muted fs-12">(Dark text, for light background headers)</span></label>
                            <label class="dnd-upload-wrap <?= setting('logo_image') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">PNG, WebP with transparent background</div>
                                <input type="checkbox" name="remove_logo_image" id="rm_logo" value="1" class="d-none">
                                <input type="file" name="logo_image" accept="image/*" data-remove-target="rm_logo">
                                <div class="dnd-preview-container">
                                    <?php if ($logoImage = setting('logo_image')): ?>
                                        <div class="p-2 bg-light rounded d-inline-block border">
                                            <img src="<?= e(upload_url($logoImage)) ?>" style="max-height:48px">
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

                        <!-- Light Logo -->
                        <div class="col-12">
                            <label class="form-label fw-medium fs-13">Light Logo <span class="text-muted fs-12">(White text, for dark hero overlays &amp; footers)</span></label>
                            <label class="dnd-upload-wrap <?= setting('logo_image_light') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">PNG, WebP with transparent background</div>
                                <input type="checkbox" name="remove_logo_image_light" id="rm_logo_light" value="1" class="d-none">
                                <input type="file" name="logo_image_light" accept="image/*" data-remove-target="rm_logo_light">
                                <div class="dnd-preview-container">
                                    <?php if ($logoLight = setting('logo_image_light')): ?>
                                        <div class="p-3 bg-dark rounded d-inline-block shadow-sm">
                                            <img src="<?= e(upload_url($logoLight)) ?>" style="max-height:48px">
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

                        <!-- Favicon -->
                        <div class="col-12">
                            <label class="form-label fw-medium fs-13">Favicon Icon <span class="text-muted fs-12">(Small icon for browser tabs &amp; bookmarks)</span></label>
                            <label class="dnd-upload-wrap <?= setting('favicon') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">ICO, PNG, WebP (e.g. 32x32px or 64x64px)</div>
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
    </div>

    <!-- Sticky action bar -->
    <div class="card mt-4 border-0 bg-dark text-white shadow-sm rounded-4">
        <div class="card-body py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-2">
                <i class="ri-information-fill text-primary fs-20"></i>
                <span class="fs-14 fw-medium">All visual brand updates reflect instantly on the user-facing website.</span>
            </div>
            <button type="submit" class="btn btn-primary px-5 rounded-pill fw-semibold shadow-sm">
                <i class="ri-save-line me-2"></i> Save General Settings
            </button>
        </div>
    </div>
</form>

<?php require __DIR__ . '/partials/foot.php'; ?>
