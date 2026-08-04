<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

$id  = (int) input('id', 0);
$cat = $id ? db_one("SELECT * FROM categories WHERE id=?", [$id]) : null;
if ($id && !$cat) { flash('error', 'That category no longer exists.'); redirect('categories'); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title_en = trim((string) input('title_en'));
    $title_ru = trim((string) input('title_ru'));
    $slug     = trim((string) input('slug'));

    if ($title_en === '') { $errors['title_en'] = 'An English title is required.'; }
    if ($title_ru === '') { $errors['title_ru'] = 'A Russian title is required.'; }

    $slug = $slug !== '' ? slugify($slug) : slugify($title_en ?: $title_ru);
    if ($slug === '' || $slug === 'item') {
        $errors['slug'] = 'A URL slug is required.';
    } elseif (db_val('SELECT 1 FROM categories WHERE slug=? AND id<>?', [$slug, $id])) {
        $errors['slug'] = 'Another category already uses that slug.';
    }

    if (!$errors) {
        if ($cat) {
            db_run("UPDATE categories SET slug=?, title_en=?, title_ru=? WHERE id=?", [$slug, $title_en, $title_ru, $id]);
            flash('success', '“' . $title_en . '” was updated.');
        } else {
            $next = (int) db_val("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories");
            db_run("INSERT INTO categories (slug, title_en, title_ru, sort_order) VALUES (?,?,?,?)", [$slug, $title_en, $title_ru, $next]);
            flash('success', '“' . $title_en . '” was created.');
        }
        redirect('categories');
    }

    // The old build flashed type 'danger', which the layout never rendered, and
    // re-rendered the form from an empty row — so a missing field silently wiped
    // everything the operator had typed. Errors are now shown per field and the
    // submitted values are kept.
    flash('error', 'Please correct the highlighted field' . (count($errors) > 1 ? 's' : '') . '.');
}

$reposted = $_SERVER['REQUEST_METHOD'] === 'POST';
$pick = static fn(string $k, $stored) => $reposted ? (string) ($_POST[$k] ?? '') : (string) ($stored ?? '');

$page = [
    'title'    => $cat ? 'Edit category' : 'New category',
    'subtitle' => $cat ? $cat['title_en'] : 'Create a tag that visitors can filter tours by.',
    'active'   => 'categories',
    'back'     => ['href' => url('categories'), 'label' => 'All categories'],
];
require __DIR__ . '/partials/head.php';

/** Inline error text for a field. */
$err = static function (string $key) use ($errors): string {
    return isset($errors[$key])
        ? '<p class="error-text"><i class="ri-error-warning-line" aria-hidden="true"></i>' . e($errors[$key]) . '</p>'
        : '';
};
?>

<form method="post" data-guard action="<?= url('category-edit' . ($id ? '?id=' . $id : '')) ?>">
    <?= csrf_field() ?>

    <div class="row">
        <div class="col-12 col-lg-8 col-xl-7">
            <section class="card">
                <div class="card__head"><h2 class="card__title">Category details</h2></div>
                <div class="card__body">
                    <div class="form-grid form-grid--2 mb-4">
                        <div>
                            <?php ui_field('title_en', 'Title (English)', [
                                'value'    => $pick('title_en', $cat['title_en'] ?? ''),
                                'required' => true,
                                'id'       => 'title_en',
                                'placeholder' => 'e.g. Mountains',
                                'attrs'    => isset($errors['title_en']) ? ['aria-invalid' => 'true'] : [],
                            ]); ?>
                            <?= $err('title_en') ?>
                        </div>
                        <div>
                            <?php ui_field('title_ru', 'Title (Russian)', [
                                'value'    => $pick('title_ru', $cat['title_ru'] ?? ''),
                                'required' => true,
                                'placeholder' => 'например, Горы',
                                'attrs'    => isset($errors['title_ru']) ? ['aria-invalid' => 'true'] : [],
                            ]); ?>
                            <?= $err('title_ru') ?>
                        </div>
                    </div>

                    <?php ui_field('slug', 'URL slug', [
                        'value'       => $pick('slug', $cat['slug'] ?? ''),
                        'placeholder' => 'created from the English title',
                        'optional'    => true,
                        'hint'        => 'Lower-case, no spaces. Leave blank to generate it automatically.',
                        'attrs'       => ['data-slug-from' => 'title_en'] + (isset($errors['slug']) ? ['aria-invalid' => 'true'] : []),
                    ]); ?>
                    <?= $err('slug') ?>

                    <p class="hint">
                        <i class="ri-information-line" aria-hidden="true"></i>
                        Position in the list is set with the arrows on the
                        <a href="<?= url('categories') ?>">Categories</a> page.
                    </p>
                </div>
            </section>
        </div>
    </div>

    <?php ui_sticky_actions($cat ? 'Save category' : 'Create category', ['cancel_href' => url('categories')]); ?>
</form>

<?php require __DIR__ . '/partials/foot.php'; ?>
