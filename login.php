<?php
require __DIR__ . '/app/bootstrap.php';

if (is_admin()) {
    redirect('index');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    [$ok, $error] = admin_login((string) input('username', ''), (string) input('password', ''));
    if ($ok) {
        $to = $_SESSION['_after_login'] ?? null;
        unset($_SESSION['_after_login']);
        redirect($to && str_contains((string) $to, '/admin/') ? $to : url('index'));
    }
}
$A = 'assets';
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <title>Sign in | Camping Uzbekistan Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <script type="module" src="<?= $A ?>/js/layout-setup.js"></script>
    <link rel="shortcut icon" href="<?= $A ?>/images/favicon.png">
    <link href="<?= $A ?>/css/bootstrap.min.css" id="bootstrap-style" rel="stylesheet">
    <link href="<?= $A ?>/css/icons.css" rel="stylesheet">
    <link href="<?= $A ?>/css/app.min.css" id="app-style" rel="stylesheet">
</head>
<body>
<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100 py-5">
        <div class="col-12 col-md-8 col-lg-6 col-xl-5">
            <div class="text-center mb-4">
                <?php
                $logoImage = setting('logo_image');
                $logoLight = setting('logo_image_light');
                if ($logoImage || $logoLight): ?>
                    <?php if ($logoImage): ?>
                        <img src="<?= e(upload_url($logoImage)) ?>" alt="Logo" class="logo-dark" style="max-height: 48px; max-width: 100%; object-fit: contain;">
                    <?php endif; ?>
                    <?php if ($logoLight): ?>
                        <img src="<?= e(upload_url($logoLight)) ?>" alt="Logo" class="logo-light" style="max-height: 48px; max-width: 100%; object-fit: contain;">
                    <?php endif; ?>
                    <?php if (!$logoLight && $logoImage): ?>
                        <img src="<?= e(upload_url($logoImage)) ?>" alt="Logo" class="logo-light" style="max-height: 48px; max-width: 100%; object-fit: contain;">
                    <?php endif; ?>
                    <?php if (!$logoImage && $logoLight): ?>
                        <img src="<?= e(upload_url($logoLight)) ?>" alt="Logo" class="logo-dark" style="max-height: 48px; max-width: 100%; object-fit: contain;">
                    <?php endif; ?>
                <?php else: ?>
                    <span class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded fw-bold fs-20"
                          style="width:48px;height:48px;">CU</span>
                <?php endif; ?>
                <h4 class="mt-3 mb-0">Camping Uzbekistan</h4>
                <p class="text-muted fs-13">Admin panel</p>
            </div>
            <div class="card shadow-sm">
                <div class="card-body p-4 p-sm-5">
                    <h3 class="fw-medium text-center mb-1">Welcome back</h3>
                    <p class="mb-4 text-muted text-center fs-13">Sign in to manage tours, guides and inbox</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="post" action="<?= url('login') ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="username" class="form-label"><?= e(t('admin_username')) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="username" class="form-control" placeholder="admin"
                                   autocomplete="username" autofocus required>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label"><?= e(t('admin_password')) ?> <span class="text-danger">*</span></label>
                            <div class="position-relative">
                                <input type="password" name="password" id="password" class="form-control"
                                       placeholder="••••••••" autocomplete="current-password" required>
                                <button type="button" class="btn btn-link position-absolute end-0 top-0 text-muted" id="togglePw" tabindex="-1">
                                    <i class="ri-eye-off-line"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><?= e(t('admin_login')) ?></button>
                    </form>
                </div>
            </div>
            <p class="text-center fs-13 text-muted mt-3 mb-0">&copy; <?= date('Y') ?> Camping Uzbekistan</p>
        </div>
    </div>
</div>

<script src="<?= $A ?>/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var btn = document.getElementById('togglePw');
    var pw = document.getElementById('password');
    btn && btn.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        btn.querySelector('i').className = show ? 'ri-eye-line' : 'ri-eye-off-line';
    });
})();
</script>
</body>
</html>
