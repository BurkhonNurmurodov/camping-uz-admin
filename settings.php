<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Hero media
    $heroType = input('hero_type') === 'video' ? 'video' : 'image';
    set_setting('hero_type', $heroType);

    $errors = [];

    /** Handle one upload slot: remove flag → async path → direct file. */
    $media = static function (string $key, string $folder, int $maxMb, string $kind) use (&$errors): void {
        if (input("remove_$key") == '1') {
            delete_upload(setting($key));
            set_setting($key, '');
        } elseif ($async = input("async_$key")) {
            delete_upload(setting($key));
            set_setting($key, $async);
        } elseif (!empty($_FILES[$key]['name'])) {
            [$ok, $res] = $kind === 'video'
                ? save_video($_FILES[$key], $folder, $maxMb)
                : save_image($_FILES[$key], $folder, $maxMb);
            if ($ok) { delete_upload(setting($key)); set_setting($key, $res); }
            else { $errors[] = $res; }
        }
    };

    $media('hero_image', 'hero', 10, 'image');
    $media('hero_video', 'hero', 60, 'video');
    $media('logo_image', 'logo', 5, 'image');
    $media('logo_image_light', 'logo', 5, 'image');
    $media('favicon', 'favicon', 2, 'image');

    foreach ([
        'agency_name_en', 'agency_name_ru', 'moto_en', 'moto_ru', 'site_url',
        'social_instagram', 'social_telegram', 'social_facebook', 'social_whatsapp',
    ] as $k) {
        set_setting($k, trim((string) input($k, '')));
    }

    $dl = input('default_lang');
    set_setting('default_lang', in_array($dl, supported_langs(), true) ? $dl : DEFAULT_LANG);

    if ($errors) {
        flash('error', 'Settings saved, but some files were rejected — ' . implode(' ', $errors));
    } else {
        flash('success', 'Settings saved.');
    }
    redirect('settings');
}

$heroType = setting('hero_type', 'image');

$page = [
    'title'    => 'Settings',
    'subtitle' => 'Branding, languages and the links shown on your public site.',
    'active'   => 'settings',
    'tabs'     => admin_settings_tabs('settings'),
];
require __DIR__ . '/partials/head.php';
?>

<form method="post" action="<?= url('settings') ?>" enctype="multipart/form-data" data-guard>
    <?= csrf_field() ?>

    <div class="row">
        <div class="col-12 col-xl-9">
            <div class="stack">

                <section class="card">
                    <div class="card__head">
                        <div>
                            <h2 class="card__title">Agency identity</h2>
                            <p class="card__sub">Your name and tagline, shown across the site.</p>
                        </div>
                    </div>
                    <div class="card__body">
                        <div class="form-grid form-grid--2 mb-4">
                            <?php ui_field('agency_name_en', 'Agency name (English)', [
                                'value' => setting('agency_name_en', ''), 'placeholder' => 'Silk Naviora',
                            ]); ?>
                            <?php ui_field('agency_name_ru', 'Agency name (Russian)', [
                                'value' => setting('agency_name_ru', ''), 'placeholder' => 'Silk Naviora',
                            ]); ?>
                            <?php ui_field('moto_en', 'Tagline (English)', [
                                'value' => setting('moto_en', ''), 'placeholder' => 'Real journeys across Central Asia',
                            ]); ?>
                            <?php ui_field('moto_ru', 'Tagline (Russian)', [
                                'value' => setting('moto_ru', ''), 'placeholder' => 'Настоящие путешествия…',
                            ]); ?>
                        </div>

                        <div class="form-section">
                            <?php ui_select('default_lang', 'Default website language', [
                                'en' => 'English', 'ru' => 'Русский',
                            ], setting('default_lang', 'en'), [
                                'hint' => 'Used when a visitor arrives without a language in the address.',
                            ]); ?>

                            <?php ui_field('site_url', 'Public website address', [
                                'type'        => 'url',
                                'value'       => setting('site_url', ''),
                                'placeholder' => rtrim(public_site_url(), '/'),
                                'optional'    => true,
                                'hint'        => 'Used by the “View on site” links. Leave blank to work it out automatically.',
                            ]); ?>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card__head">
                        <div>
                            <h2 class="card__title">Home page hero</h2>
                            <p class="card__sub">The full-width background at the top of the home page.</p>
                        </div>
                    </div>
                    <div class="card__body">
                        <!-- The choice now actually governs what you see below.
                             Previously both uploaders stayed visible whichever
                             option was selected, so people uploaded a video and
                             then wondered why the image was still showing. -->
                        <div class="field">
                            <span class="label">What should the hero show?</span>
                            <div class="radio-cards">
                                <label class="radio-card">
                                    <input type="radio" name="hero_type" value="image" data-hero-mode
                                           <?= $heroType !== 'video' ? 'checked' : '' ?>>
                                    <span>
                                        <span class="radio-card__title">A photograph</span>
                                        <span class="radio-card__desc">Loads fastest. Best for most sites.</span>
                                    </span>
                                </label>
                                <label class="radio-card">
                                    <input type="radio" name="hero_type" value="video" data-hero-mode
                                           <?= $heroType === 'video' ? 'checked' : '' ?>>
                                    <span>
                                        <span class="radio-card__title">A looping video</span>
                                        <span class="radio-card__desc">More atmospheric, but a heavier page.</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div id="heroImagePane" class="form-section">
                            <?php ui_upload('hero_image', 'Hero photograph', [
                                'accept'      => 'image/*',
                                'hint'        => '1920 × 1080 or larger · JPG, PNG or WebP · max 10 MB',
                                'current'     => setting('hero_image') ? upload_url(setting('hero_image')) : '',
                                'remove_name' => 'remove_hero_image',
                            ]); ?>
                        </div>

                        <div id="heroVideoPane" class="form-section">
                            <?php ui_upload('hero_video', 'Hero video', [
                                'accept'      => 'video/*',
                                'hint'        => 'MP4 or WebM · max 60 MB · keep it short and quiet',
                                'kind'        => 'video',
                                'has_file'    => (bool) setting('hero_video'),
                                'remove_name' => 'remove_hero_video',
                            ]); ?>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card__head">
                        <div>
                            <h2 class="card__title">Logo &amp; icon</h2>
                            <p class="card__sub">Transparent PNG or WebP works best.</p>
                        </div>
                    </div>
                    <div class="card__body">
                        <div class="form-grid form-grid--2">
                            <?php ui_upload('logo_image', 'Primary logo', [
                                'accept'      => 'image/*',
                                'hint'        => 'Dark version, for light backgrounds',
                                'current'     => setting('logo_image') ? upload_url(setting('logo_image')) : '',
                                'remove_name' => 'remove_logo_image',
                            ]); ?>
                            <?php ui_upload('logo_image_light', 'Light logo', [
                                'accept'      => 'image/*',
                                'hint'        => 'White version, for dark backgrounds',
                                'current'     => setting('logo_image_light') ? upload_url(setting('logo_image_light')) : '',
                                'remove_name' => 'remove_logo_image_light',
                                'dark'        => true,
                            ]); ?>
                            <div class="col-span-full">
                                <?php ui_upload('favicon', 'Browser icon', [
                                    'accept'      => 'image/*,.ico',
                                    'hint'        => 'Square · ICO, PNG or WebP · shown on the browser tab',
                                    'current'     => setting('favicon') ? upload_url(setting('favicon')) : '',
                                    'remove_name' => 'remove_favicon',
                                ]); ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card__head">
                        <div>
                            <h2 class="card__title">Social links</h2>
                            <p class="card__sub">Shown in the footer and contact areas. Leave blank to hide one.</p>
                        </div>
                    </div>
                    <div class="card__body">
                        <div class="form-grid form-grid--2">
                            <?php foreach ([
                                'social_instagram' => ['Instagram', 'https://instagram.com/your_profile'],
                                'social_telegram'  => ['Telegram',  'https://t.me/your_channel'],
                                'social_facebook'  => ['Facebook',  'https://facebook.com/your_page'],
                                'social_whatsapp'  => ['WhatsApp',  'https://wa.me/998900000000'],
                            ] as $k => [$label, $ph]): ?>
                                <?php ui_field($k, $label, [
                                    'value' => setting($k, ''), 'placeholder' => $ph, 'type' => 'url',
                                ]); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </div>

    <?php ui_sticky_actions('Save settings'); ?>
</form>

<?php
$page['inline_js'] = <<<'JS'
// Show only the uploader that matches the selected hero mode.
(function () {
  var img = document.getElementById('heroImagePane');
  var vid = document.getElementById('heroVideoPane');
  function sync() {
    var mode = document.querySelector('[data-hero-mode]:checked');
    var isVideo = mode && mode.value === 'video';
    img.classList.toggle('hide', isVideo);
    vid.classList.toggle('hide', !isVideo);
  }
  document.querySelectorAll('[data-hero-mode]').forEach(function (r) {
    r.addEventListener('change', sync);
  });
  sync();
})();
JS;
require __DIR__ . '/partials/foot.php';
?>
