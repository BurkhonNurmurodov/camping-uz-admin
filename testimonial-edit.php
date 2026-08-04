<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

$id = (int) input('id', 0);
$t  = $id ? db_one('SELECT * FROM testimonials WHERE id=?', [$id]) : null;
if ($id && !$t) { flash('error', 'That testimonial no longer exists.'); redirect('testimonials'); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim((string) input('author_name', ''));
    $cEn  = sanitize_html((string) input('comment_en', ''));
    $cRu  = sanitize_html((string) input('comment_ru', ''));
    $vis  = input('is_visible') ? 1 : 0;

    if ($name === '') { $errors['author_name'] = 'Who said this? A name is required.'; }

    $avatar = $t['avatar'] ?? null;
    if (input('remove_avatar')) { delete_upload($avatar); $avatar = null; }
    elseif ($async = input('async_avatar')) { delete_upload($avatar); $avatar = $async; }
    elseif (!empty($_FILES['avatar']['name'])) {
        [$ok, $res] = save_image($_FILES['avatar'], 'avatars', 4);
        if ($ok) { delete_upload($avatar); $avatar = $res; } else { $errors['avatar'] = 'Photo: ' . $res; }
    }

    if (!$errors) {
        if ($t) {
            db_run('UPDATE testimonials SET author_name=?, avatar=?, comment_en_html=?, comment_ru_html=?, is_visible=? WHERE id=?',
                [$name, $avatar, $cEn, $cRu, $vis, $id]);
        } else {
            $next = (int) db_val("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM testimonials");
            db_run('INSERT INTO testimonials (author_name, avatar, comment_en_html, comment_ru_html, is_visible, sort_order) VALUES (?,?,?,?,?,?)',
                [$name, $avatar, $cEn, $cRu, $vis, $next]);
        }
        flash('success', 'Testimonial saved.');
        redirect('testimonials');
    }

    flash('error', implode(' ', $errors));
}

$reposted = $_SERVER['REQUEST_METHOD'] === 'POST';
$pick = static fn(string $k, $stored) => $reposted ? (string) ($_POST[$k] ?? '') : (string) ($stored ?? '');
$visible = $reposted ? (bool) input('is_visible') : (bool) ($t['is_visible'] ?? 1);

$page = [
    'title'    => $t ? 'Edit testimonial' : 'New testimonial',
    'subtitle' => $t ? $t['author_name'] : 'Add a client quote for the “Our clients about us” carousel.',
    'active'   => 'testimonials',
    'back'     => ['href' => url('testimonials'), 'label' => 'All testimonials'],
    'vendor_css' => quill_vendor_css(),
];
require __DIR__ . '/partials/head.php';
?>

<form method="post" enctype="multipart/form-data" data-guard
      action="<?= url('testimonial-edit' . ($id ? '?id=' . $id : '')) ?>">
    <?= csrf_field() ?>

    <div class="split split--main-aside">
        <div class="stack">
            <section class="card">
                <div class="card__head">
                    <div>
                        <h2 class="card__title">The quote</h2>
                        <p class="card__sub">Write it in both languages where you can.</p>
                    </div>
                </div>
                <div class="card__body">
                    <?php ui_lang_tabs('tm', function ($l) use ($t, $pick) { ?>
                        <div class="field">
                            <label class="label">Comment (<?= strtoupper($l) ?>)</label>
                            <?php ui_editor("comment_$l", $pick("comment_$l", $t["comment_{$l}_html"] ?? ''), 'What the client said…'); ?>
                        </div>
                    <?php }); ?>
                </div>
            </section>
        </div>

        <aside class="stack">
            <section class="card">
                <div class="card__head"><h2 class="card__title">Author</h2></div>
                <div class="card__body">
                    <?php ui_field('author_name', 'Name', [
                        'value'       => $pick('author_name', $t['author_name'] ?? ''),
                        'required'    => true,
                        'placeholder' => 'e.g. Anna Petrova',
                        'attrs'       => isset($errors['author_name']) ? ['aria-invalid' => 'true'] : [],
                    ]); ?>
                    <?php if (isset($errors['author_name'])): ?>
                        <p class="error-text"><i class="ri-error-warning-line" aria-hidden="true"></i><?= e($errors['author_name']) ?></p>
                    <?php endif; ?>

                    <?php ui_upload('avatar', 'Photo', [
                        'accept'      => 'image/*',
                        'hint'        => 'Square works best · max 4 MB · optional',
                        'current'     => ($t && $t['avatar']) ? upload_url($t['avatar']) : '',
                        'remove_name' => 'remove_avatar',
                    ]); ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Visibility</h2></div>
                <div class="card__body">
                    <label class="switch">
                        <input type="checkbox" name="is_visible" value="1" <?= $visible ? 'checked' : '' ?>>
                        <span class="switch__track" aria-hidden="true"></span>
                        <span>
                            <span class="t-medium">Show on the site</span>
                            <span class="check__hint">Turn off to keep it saved but hidden.</span>
                        </span>
                    </label>
                </div>
            </section>
        </aside>
    </div>

    <?php ui_sticky_actions($t ? 'Save testimonial' : 'Create testimonial', ['cancel_href' => url('testimonials')]); ?>
</form>

<?php
$page['vendor_js'] = quill_vendor_js();
require __DIR__ . '/partials/foot.php';
?>
