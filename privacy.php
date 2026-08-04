<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

$privacy = db_one("SELECT * FROM pages WHERE `key`='privacy'");
if (!$privacy) {
    db_run(
        "INSERT INTO pages (`key`, title_en, title_ru, body_en_html, body_ru_html) VALUES (?, ?, ?, ?, ?)",
        [
            'privacy',
            'Privacy Policy',
            'Политика конфиденциальности',
            '<p>Our privacy policy details will be published here soon.</p>',
            '<p>Информация о политике конфиденциальности скоро появится здесь.</p>',
        ]
    );
    $privacy = db_one("SELECT * FROM pages WHERE `key`='privacy'");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    db_run(
        "UPDATE pages SET title_en=?, title_ru=?, body_en_html=?, body_ru_html=? WHERE `key`='privacy'",
        [
            trim((string) input('title_en', 'Privacy Policy')),
            trim((string) input('title_ru', 'Политика конфиденциальности')),
            sanitize_html((string) input('body_en', '')),
            sanitize_html((string) input('body_ru', '')),
        ]
    );

    $uploadErrors = [];
    foreach (['en', 'ru'] as $l) {
        if (input("remove_privacy_pdf_$l") == '1') {
            delete_upload(setting("privacy_pdf_$l"));
            set_setting("privacy_pdf_$l", '');
        } elseif (!empty($_FILES["privacy_pdf_$l"]['name'])) {
            [$ok, $res] = save_pdf($_FILES["privacy_pdf_$l"], 'docs', 30);
            if ($ok) {
                delete_upload(setting("privacy_pdf_$l"));
                set_setting("privacy_pdf_$l", $res);
            } else {
                $uploadErrors[] = strtoupper($l) . ' PDF: ' . $res;
            }
        }
    }

    // The old guard checked $_SESSION['flash'] while flash() writes to
    // $_SESSION['_flash'], so a failed upload showed "saved successfully" AND
    // an error at the same time. Report one honest outcome instead.
    if ($uploadErrors) {
        flash('error', 'Text saved, but the file upload failed — ' . implode(' ', $uploadErrors));
    } else {
        flash('success', 'Privacy Policy saved.');
    }
    redirect('privacy');
}

$page = [
    'title'      => 'Pages',
    'subtitle'   => 'The three standing pages on your website.',
    'active'     => 'pages',
    'tabs'       => admin_pages_tabs('privacy'),
    'vendor_css' => quill_vendor_css(),
    'actions'    => ui_btn('View on site', [
        'href'  => public_site_url('privacy.php'),
        'icon'  => 'ri-external-link-line',
        'attrs' => ['target' => '_blank', 'rel' => 'noopener'],
    ]),
];
require __DIR__ . '/partials/head.php';
?>

<form method="post" action="<?= url('privacy') ?>" enctype="multipart/form-data" data-guard>
    <?= csrf_field() ?>
    <div class="row">
        <div class="col-12 col-xl-9">
            <section class="card">
                <div class="card__head">
                    <div>
                        <h2 class="card__title">Privacy Policy</h2>
                        <p class="card__sub">Published at <?= e(public_site_url('privacy.php')) ?></p>
                    </div>
                </div>
                <div class="card__body">
                    <?php ui_lang_tabs('privacy', function ($l) use ($privacy) {
                        $curPdf = setting("privacy_pdf_$l"); ?>

                        <?php ui_field("title_$l", 'Heading (' . strtoupper($l) . ')', [
                            'value'    => $privacy["title_$l"] ?? '',
                            'required' => true,
                        ]); ?>

                        <div class="field">
                            <label class="label">Body (<?= strtoupper($l) ?>)</label>
                            <?php ui_editor("body_$l", $privacy["body_{$l}_html"] ?? '', 'Data collection, storage and visitor rights…'); ?>
                        </div>

                        <div class="form-section">
                            <p class="form-section__title">Official PDF (<?= strtoupper($l) ?>)</p>
                            <p class="form-section__desc">Optional. Uploading one adds a download banner to the live page.</p>

                            <?php if ($curPdf): ?>
                                <div class="repeat-row mb-3">
                                    <i class="ri-file-pdf-2-line t-danger" style="font-size:1.3rem" aria-hidden="true"></i>
                                    <a href="<?= e(upload_url($curPdf)) ?>" target="_blank" rel="noopener"
                                       class="t-sm t-truncate" style="flex:1"><?= e(basename($curPdf)) ?></a>
                                    <label class="check">
                                        <input type="checkbox" name="remove_privacy_pdf_<?= $l ?>" value="1">
                                        <span class="check__text t-sm t-danger">Remove</span>
                                    </label>
                                </div>
                            <?php endif; ?>

                            <?php ui_upload("privacy_pdf_$l", $curPdf ? 'Replace the PDF' : 'Upload a PDF', [
                                'accept' => 'application/pdf,.pdf',
                                'hint'   => 'PDF · max 30 MB',
                                'kind'   => 'pdf',
                            ]); ?>
                        </div>
                    <?php }); ?>
                </div>
            </section>
        </div>
    </div>

    <?php ui_sticky_actions('Save Privacy Policy'); ?>
</form>

<?php
$page['vendor_js'] = quill_vendor_js();
require __DIR__ . '/partials/foot.php';
?>
