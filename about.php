<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

$about = db_one("SELECT * FROM pages WHERE `key`='about'");
if (!$about) {
    db_run("INSERT INTO pages (`key`, title_en, title_ru) VALUES ('about','About us','О нас')");
    $about = db_one("SELECT * FROM pages WHERE `key`='about'");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    db_run(
        "UPDATE pages SET title_en=?, title_ru=?, body_en_html=?, body_ru_html=? WHERE `key`='about'",
        [
            trim((string) input('title_en', 'About us')),
            trim((string) input('title_ru', 'О нас')),
            sanitize_html((string) input('body_en', '')),
            sanitize_html((string) input('body_ru', '')),
        ]
    );
    flash('success', 'About page saved.');
    redirect('about');
}

$page = [
    'title'      => 'Pages',
    'subtitle'   => 'The three standing pages on your website.',
    'active'     => 'pages',
    'tabs'       => admin_pages_tabs('about'),
    'vendor_css' => quill_vendor_css(),
    'actions'    => ui_btn('View on site', [
        'href'  => public_site_url('index.php#about'),
        'icon'  => 'ri-external-link-line',
        'attrs' => ['target' => '_blank', 'rel' => 'noopener'],
    ]),
];
require __DIR__ . '/partials/head.php';
?>

<form method="post" action="<?= url('about') ?>" data-guard>
    <?= csrf_field() ?>
    <div class="row">
        <div class="col-12 col-xl-9">
            <section class="card">
                <div class="card__head">
                    <div>
                        <h2 class="card__title">About us</h2>
                        <p class="card__sub">Who you are, in both languages.</p>
                    </div>
                </div>
                <div class="card__body">
                    <?php ui_lang_tabs('about', function ($l) use ($about) { ?>
                        <?php ui_field("title_$l", 'Heading (' . strtoupper($l) . ')', [
                            'value' => $about["title_$l"] ?? '',
                        ]); ?>
                        <div class="field">
                            <label class="label">Body (<?= strtoupper($l) ?>)</label>
                            <?php ui_editor("body_$l", $about["body_{$l}_html"] ?? '', 'Tell visitors who you are…'); ?>
                            <p class="hint">
                                You can format text and insert images or video. Select an image after inserting it to resize or align it.
                            </p>
                        </div>
                    <?php }); ?>
                </div>
            </section>
        </div>
    </div>

    <?php ui_sticky_actions('Save About page'); ?>
</form>

<?php
$page['vendor_js'] = quill_vendor_js();
require __DIR__ . '/partials/foot.php';
?>
