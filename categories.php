<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

if (input('action') === 'delete' && input('id')) {
    csrf_verify();
    db_run("DELETE FROM categories WHERE id=?", [input('id')]);
    flash('success', 'Category deleted.');
    redirect('categories');
}

$categories = db_all("SELECT * FROM categories ORDER BY sort_order, id");

$page = ['title' => 'Tour Categories', 'section' => 'Content', 'active' => 'categories'];
require __DIR__ . '/partials/head.php';
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Tour Categories</h5>
        <a href="<?= url('category-edit') ?>" class="btn btn-primary btn-sm"><i class="ri-add-line me-1"></i> Add Category</a>
    </div>
    <div class="card-body">
        <?php if (!$categories): ?>
            <p class="text-muted mb-0">No categories found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 50px">#</th>
                            <th>Title (EN)</th>
                            <th>Title (RU)</th>
                            <th>Slug</th>
                            <th>Sort Order</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $c): ?>
                            <tr>
                                <td><?= $c['id'] ?></td>
                                <td><strong><?= e($c['title_en']) ?></strong></td>
                                <td><?= e($c['title_ru']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= e($c['slug']) ?></span></td>
                                <td><?= $c['sort_order'] ?></td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="<?= url('category-edit?id=' . $c['id']) ?>" class="btn btn-sm btn-light"><i class="ri-pencil-line"></i></a>
                                        <form method="post" action="categories" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this category?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button class="btn btn-sm btn-light text-danger"><i class="ri-delete-bin-line"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
