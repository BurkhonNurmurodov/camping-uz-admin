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

<form method="post" enctype="multipart/form-data" action="settings" class="pb-5">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="general">
    
    <!-- Page Title & Header Actions -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4 pb-3 border-bottom">
        <div>
            <h4 class="mb-1 fw-bold">General Site Settings &amp; Branding</h4>
            <p class="text-body-secondary mb-0 fs-14">Configure agency titles, localization defaults, media showcases, and public contact profiles.</p>
        </div>
        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
            <i class="ri-save-3-line fs-16"></i> Save Settings
        </button>
    </div>

    <!-- Section 1: Identity & Localization -->
    <div class="row g-4 mb-5">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-store-2-line text-primary fs-20"></i> Agency Identity
                </h6>
                <p class="text-body-secondary fs-13 mb-0">
                    Define your travel agency's official trade name and brand taglines across supported languages, and select the default presentation language for first-time visitors.
                </p>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border rounded-3 h-100">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Agency Name (English)</label>
                            <input type="text" name="agency_name_en" class="form-control" value="<?= e(setting('agency_name_en', '')) ?>" placeholder="e.g. Silk Naviora">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Agency Name (Russian)</label>
                            <input type="text" name="agency_name_ru" class="form-control" value="<?= e(setting('agency_name_ru', '')) ?>" placeholder="e.g. Silk Naviora RU">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Motto / Slogan (English)</label>
                            <input type="text" name="moto_en" class="form-control" value="<?= e(setting('moto_en', '')) ?>" placeholder="e.g. Discover Authentic Adventures">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Motto / Slogan (Russian)</label>
                            <input type="text" name="moto_ru" class="form-control" value="<?= e(setting('moto_ru', '')) ?>" placeholder="e.g. Откройте подлинные приключения">
                        </div>
                    </div>

                    <hr class="my-4 border-secondary-subtle opacity-50">

                    <div>
                        <label class="form-label fw-medium fs-13">Default Website Language</label>
                        <select name="default_lang" class="form-select w-auto min-w-200px">
                            <?php foreach (['en' => 'English (EN)', 'ru' => 'Русский (RU)'] as $c => $l): ?>
                                <option value="<?= $c ?>" <?= setting('default_lang', 'en') === $c ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text fs-12 text-body-secondary">Primary display language rendered when a user navigates to the homepage without a language prefix.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Hero Showcase Background -->
    <div class="row g-4 mb-5">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-image-edit-line text-success fs-20"></i> Hero Showcase
                </h6>
                <p class="text-body-secondary fs-13 mb-0">
                    Customize the primary background visual featured on the main portal. Switch effortlessly between a lightweight static photograph or an atmospheric looping video canvas.
                </p>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border rounded-3">
                <div class="card-body p-4">
                    <div class="mb-4 pb-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <span class="fw-semibold text-body-secondary fs-13 text-uppercase">Active Display Medium</span>
                        <div>
                            <div class="form-check form-check-inline me-4">
                                <input class="form-check-input cursor-pointer" type="radio" name="hero_type" id="ht_img" value="image" <?= $heroType !== 'video' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium cursor-pointer" for="ht_img"><i class="ri-image-line me-1 text-primary"></i> Static Photograph</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input cursor-pointer" type="radio" name="hero_type" id="ht_vid" value="video" <?= $heroType === 'video' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium cursor-pointer" for="ht_vid"><i class="ri-video-line me-1 text-success"></i> Looping Video</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-medium fs-13">Hero Cover Image <span class="text-body-secondary fs-12">(JPG, PNG, or WebP &middot; Max 10MB)</span></label>
                            <label class="dnd-upload-wrap <?= $heroImage ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop image or press to select</div>
                                <div class="dnd-upload-subtext">Recommended resolution: 1920 &times; 1080px (≤10MB)</div>
                                <input type="checkbox" name="remove_hero_image" id="rm_hero" value="1" class="d-none">
                                <input type="file" name="hero_image" accept="image/*" data-remove-target="rm_hero">
                                <div class="dnd-preview-container">
                                    <?php if ($heroImage): ?>
                                        <img src="<?= e(upload_url($heroImage)) ?>" class="dnd-preview-img" alt="Hero background">
                                    <?php endif; ?>
                                </div>
                                <div class="dnd-loader">
                                    <div class="spinner-border text-primary" role="status"></div>
                                </div>
                            </label>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium fs-13">Hero Background Video <span class="text-body-secondary fs-12">(MP4 or WebM &middot; Max 60MB)</span></label>
                            <label class="dnd-upload-wrap <?= $heroVideo ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop video or press to select</div>
                                <div class="dnd-upload-subtext">Optimized MP4 or WebM encoding recommended for streaming</div>
                                <input type="checkbox" name="remove_hero_video" id="rm_hero_vid" value="1" class="d-none">
                                <input type="file" name="hero_video" accept="video/*" data-remove-target="rm_hero_vid">
                                <div class="dnd-preview-container">
                                    <?php if ($heroVideo): ?>
                                        <div class="p-3 bg-success-subtle text-success-emphasis border border-success-subtle rounded-3 text-center fw-medium fs-13">
                                            <i class="ri-check-double-line me-1 fs-16 align-middle"></i> Active video asset uploaded and deployed
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
        </div>
    </div>

    <!-- Section 3: Site Logos & Favicon -->
    <div class="row g-4 mb-5">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-vip-diamond-line text-warning fs-20"></i> Logos &amp; Iconography
                </h6>
                <p class="text-body-secondary fs-13 mb-0">
                    Upload transparent brand mark variations. The Primary Logo renders against light navigation bars, while the Light Overlay variant appears across dark hero canvas sections and footer regions.
                </p>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border rounded-3">
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-medium fs-13">Primary Logo <span class="text-body-secondary fs-12">(Dark typography &middot; Default navigation)</span></label>
                            <label class="dnd-upload-wrap <?= setting('logo_image') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">PNG or WebP with transparent background</div>
                                <input type="checkbox" name="remove_logo_image" id="rm_logo" value="1" class="d-none">
                                <input type="file" name="logo_image" accept="image/*" data-remove-target="rm_logo">
                                <div class="dnd-preview-container">
                                    <?php if ($logoImage = setting('logo_image')): ?>
                                        <div class="p-3 bg-body-tertiary rounded-3 d-inline-block border">
                                            <img src="<?= e(upload_url($logoImage)) ?>" style="max-height:48px" alt="Primary Logo">
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
                            <label class="form-label fw-medium fs-13">Light Overlay Logo <span class="text-body-secondary fs-12">(White typography &middot; Dark headers &amp; footers)</span></label>
                            <label class="dnd-upload-wrap <?= setting('logo_image_light') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">PNG or WebP with transparent background</div>
                                <input type="checkbox" name="remove_logo_image_light" id="rm_logo_light" value="1" class="d-none">
                                <input type="file" name="logo_image_light" accept="image/*" data-remove-target="rm_logo_light">
                                <div class="dnd-preview-container">
                                    <?php if ($logoLight = setting('logo_image_light')): ?>
                                        <div class="p-3 bg-dark rounded-3 d-inline-block shadow-sm">
                                            <img src="<?= e(upload_url($logoLight)) ?>" style="max-height:48px" alt="Light Logo">
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
                            <label class="form-label fw-medium fs-13">Browser Favicon <span class="text-body-secondary fs-12">(Tab &amp; bookmark icon)</span></label>
                            <label class="dnd-upload-wrap <?= setting('favicon') ? 'has-preview' : '' ?>">
                                <i class="ri-upload-cloud-2-line dnd-upload-icon"></i>
                                <div class="dnd-upload-text">Drag and drop or press to upload</div>
                                <div class="dnd-upload-subtext">ICO, PNG, or WebP square icon (e.g. 32 &times; 32px)</div>
                                <input type="checkbox" name="remove_favicon" id="rm_favicon" value="1" class="d-none">
                                <input type="file" name="favicon" accept="image/*,.ico" data-remove-target="rm_favicon">
                                <div class="dnd-preview-container">
                                    <?php if ($favicon = setting('favicon')): ?>
                                        <div class="p-2 bg-body-tertiary rounded-3 d-inline-block border">
                                            <img src="<?= e(upload_url($favicon)) ?>" style="max-height:32px" alt="Favicon">
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

    <!-- Section 4: Social Media Profiles -->
    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-share-forward-box-line text-info fs-20"></i> Social Presence
                </h6>
                <p class="text-body-secondary fs-13 mb-0">
                    Connect your public communication handles and social network pages. These hyperlinks integrate directly into public contact regions and promotional footers.
                </p>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border rounded-3">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <?php foreach ([
                            'social_instagram' => ['Instagram Handle or URL', 'ri-instagram-line', 'https://instagram.com/your_profile'],
                            'social_telegram'  => ['Telegram Channel or Bot', 'ri-telegram-line', 'https://t.me/your_channel'],
                            'social_facebook'  => ['Facebook Page URL', 'ri-facebook-circle-line', 'https://facebook.com/your_page'],
                            'social_whatsapp'  => ['WhatsApp Direct Link', 'ri-whatsapp-line', 'https://wa.me/998900000000'],
                        ] as $k => [$label, $icon, $placeholder]): ?>
                            <div class="col-md-6">
                                <label class="form-label fw-medium fs-13 d-flex align-items-center">
                                    <i class="<?= $icon ?> me-2 text-body-secondary fs-16"></i> <?= $label ?>
                                </label>
                                <input type="text" name="<?= $k ?>" class="form-control fs-13" placeholder="<?= $placeholder ?>" value="<?= e(setting($k, '')) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Save Confirmation -->
    <div class="d-flex justify-content-end align-items-center pt-4 mt-5 border-top">
        <button type="submit" class="btn btn-primary px-5 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
            <i class="ri-save-3-line fs-18"></i> Save General Settings
        </button>
    </div>
</form>

<?php require __DIR__ . '/partials/foot.php'; ?>
