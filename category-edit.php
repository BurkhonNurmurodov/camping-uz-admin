<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

$id = (int) input('id', 0);
$cat = $id ? db_one("SELECT * FROM categories WHERE id=?", [$id]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title_en = trim((string) input('title_en'));
    $title_ru = trim((string) input('title_ru'));
    $slug = trim((string) input('slug'));
    $sort_order = (int) input('sort_order', 0);

    if (!$title_en || !$title_ru || !$slug) {
        flash('danger', 'Please fill in all required fields.');
    } else {
        if ($cat) {
            db_run("UPDATE categories SET slug=?, title_en=?, title_ru=?, sort_order=? WHERE id=?", 
                [$slug, $title_en, $title_ru, $sort_order, $id]);
            flash('success', 'Category updated.');
        } else {
            db_run("INSERT INTO categories (slug, title_en, title_ru, sort_order) VALUES (?, ?, ?, ?)", 
                [$slug, $title_en, $title_ru, $sort_order]);
            flash('success', 'Category created.');
        }
        redirect('categories');
    }
}

$page = ['title' => $cat ? 'Edit Category' : 'New Category', 'section' => 'Content', 'active' => 'categories'];
require __DIR__ . '/partials/head.php';
?>

<form method="post" action="<?= url('category-edit' . ($id ? '?id=' . $id : '')) ?>">
    <?= csrf_field() ?>
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><?= $cat ? 'Edit Category' : 'New Category' ?></h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Title (EN) *</label>
                            <input type="text" name="title_en" class="form-control" value="<?= e($cat['title_en'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mt-3 mt-md-0">
                            <label class="form-label">Title (RU) *</label>
                            <input type="text" name="title_ru" class="form-control" value="<?= e($cat['title_ru'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug (URL string) *</label>
                        <input type="text" name="slug" class="form-control" value="<?= e($cat['slug'] ?? '') ?>" required>
                        <div class="form-text">e.g., "hiking", "historical". Must be unique.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= $cat['sort_order'] ?? 0 ?>">
                    </div>
                </div>
                <div class="card-footer text-end">
                    <a href="<?= url('categories') ?>" class="btn btn-light me-2">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i> Save Category</button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/partials/foot.php'; ?>
