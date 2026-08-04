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

$A = BASE_PATH . '/assets';
$logo = setting('logo_image') ?: setting('logo_image_light');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · Silk Naviora Admin</title>

<?php if ($favicon = setting('favicon')): ?>
    <link rel="icon" href="<?= e(upload_url($favicon)) ?>">
<?php else: ?>
    <link rel="icon" href="<?= $A ?>/images/favicon.png">
<?php endif; ?>

<script>
(function(){try{var m=localStorage.getItem('sn.theme');if(m&&m!=='system')document.documentElement.setAttribute('data-theme',m);}catch(e){}})();
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">

<link rel="stylesheet" href="<?= $A ?>/libs/bootstrap/css/bootstrap-reboot.min.css">
<link rel="stylesheet" href="<?= $A ?>/libs/bootstrap/css/bootstrap-grid.min.css">
<link rel="stylesheet" href="<?= $A ?>/libs/bootstrap/css/bootstrap-utilities.min.css">
<link rel="stylesheet" href="<?= $A ?>/libs/remixicon/fonts/remixicon.css">
<link rel="stylesheet" href="<?= $A ?>/css/tokens.css">
<link rel="stylesheet" href="<?= $A ?>/css/admin.css">
</head>
<body>
<main class="login">
    <div class="login__inner">
        <div class="login__brand">
            <?php if ($logo): ?>
                <img src="<?= e(upload_url($logo)) ?>" alt="Silk Naviora" style="max-height:52px;max-width:220px;object-fit:contain">
            <?php else: ?>
                <span class="login__mark" aria-hidden="true">SN</span>
            <?php endif; ?>
            <div class="text-center">
                <h1 class="login__title">Silk Naviora</h1>
                <p class="login__sub mb-0">Admin panel</p>
            </div>
        </div>

        <div class="card">
            <div class="card__body">
                <?php if ($error): ?>
                    <div class="alert alert--danger" role="alert">
                        <i class="alert__icon ri-error-warning-line" aria-hidden="true"></i>
                        <div class="alert__body"><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= url('login') ?>">
                    <?= csrf_field() ?>

                    <div class="field">
                        <label class="label" for="username">Username</label>
                        <input class="input" type="text" id="username" name="username"
                               value="<?= e((string) input('username', '')) ?>"
                               autocomplete="username" autofocus required
                               <?= $error ? 'aria-invalid="true"' : '' ?>>
                    </div>

                    <div class="field">
                        <label class="label" for="password">Password</label>
                        <div class="pw-field">
                            <input class="input" type="password" id="password" name="password"
                                   autocomplete="current-password" required
                                   <?= $error ? 'aria-invalid="true"' : '' ?>>
                            <button type="button" class="pw-toggle" id="pwToggle"
                                    aria-label="Show password" title="Show password">
                                <i class="ri-eye-line" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn--primary btn--lg btn--block">
                        <i class="ri-login-circle-line" aria-hidden="true"></i>
                        <span>Sign in</span>
                    </button>
                </form>
            </div>
        </div>

        <p class="login__foot">&copy; <?= date('Y') ?> Silk Naviora</p>
    </div>
</main>

<script>
(function () {
    var btn = document.getElementById('pwToggle');
    var input = document.getElementById('password');
    btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.setAttribute('title', show ? 'Hide password' : 'Show password');
        btn.querySelector('i').className = show ? 'ri-eye-off-line' : 'ri-eye-line';
        input.focus();
    });
})();
</script>
</body>
</html>
