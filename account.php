<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

$me = admin_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $cur     = (string) input('current_password', '');
    $newUser = trim((string) input('new_username', ''));
    $newPass = (string) input('new_password', '');
    $confirm = (string) input('confirm_password', '');

    $row = db_one('SELECT password_hash FROM admins WHERE id = ?', [$me['id']]);

    if (!$row || !password_verify($cur, $row['password_hash'])) {
        $errors['current_password'] = 'That is not your current password.';
    }
    if ($newUser === '') {
        $errors['new_username'] = 'A username is required.';
    } elseif ($newUser !== $me['username'] && db_val('SELECT 1 FROM admins WHERE username = ? AND id <> ?', [$newUser, $me['id']])) {
        $errors['new_username'] = 'That username is already taken.';
    }
    if ($newPass !== '') {
        if (strlen($newPass) < 8) {
            $errors['new_password'] = 'Use at least 8 characters.';
        } elseif ($newPass !== $confirm) {
            $errors['confirm_password'] = 'The two passwords do not match.';
        }
    }

    if (!$errors) {
        admin_update_credentials((int) $me['id'], $newUser, $newPass !== '' ? $newPass : null);
        flash('success', $newPass !== '' ? 'Username and password updated.' : 'Username updated.');
        redirect('account');
    }

    flash('error', 'Nothing was changed — please check the highlighted fields.');
}

$page = [
    'title'    => 'Settings',
    'subtitle' => 'Your sign-in details.',
    'active'   => 'settings',
    'tabs'     => admin_settings_tabs('account'),
];
require __DIR__ . '/partials/head.php';

$err = static function (string $key) use ($errors): string {
    return isset($errors[$key])
        ? '<p class="error-text"><i class="ri-error-warning-line" aria-hidden="true"></i>' . e($errors[$key]) . '</p>'
        : '';
};
?>

<div class="row">
    <div class="col-12 col-xl-7">
        <div class="stack">

            <section class="card">
                <div class="card__body">
                    <div class="row-flex row-flex--wrap">
                        <?= ui_avatar($me['display_name'] ?? $me['username'], 'avatar--lg') ?>
                        <div>
                            <p class="t-lg t-semibold t-strong mb-0"><?= e($me['display_name'] ?? $me['username'] ?? 'Administrator') ?></p>
                            <p class="t-sm t-muted mb-0">
                                @<?= e($me['username'] ?? 'admin') ?> · Full access to every part of the panel
                            </p>
                        </div>
                        <span class="push-end"><?= ui_status('Signed in', 'success') ?></span>
                    </div>
                </div>
            </section>

            <form method="post" action="<?= url('account') ?>">
                <?= csrf_field() ?>
                <section class="card">
                    <div class="card__head">
                        <div>
                            <h2 class="card__title">Change sign-in details</h2>
                            <p class="card__sub">Confirm your current password to make any change.</p>
                        </div>
                    </div>
                    <div class="card__body">
                        <?php ui_field('current_password', 'Current password', [
                            'type'     => 'password',
                            'required' => true,
                            'placeholder' => 'Your password right now',
                            'attrs'    => ['autocomplete' => 'current-password']
                                          + (isset($errors['current_password']) ? ['aria-invalid' => 'true'] : []),
                        ]); ?>
                        <?= $err('current_password') ?>

                        <div class="form-section">
                            <p class="form-section__title">New details</p>
                            <p class="form-section__desc">Leave the password fields empty to keep your current password.</p>

                            <?php ui_field('new_username', 'Username', [
                                'value'    => $_SERVER['REQUEST_METHOD'] === 'POST'
                                                ? (string) input('new_username', '')
                                                : (string) $me['username'],
                                'required' => true,
                                'attrs'    => ['autocomplete' => 'username']
                                              + (isset($errors['new_username']) ? ['aria-invalid' => 'true'] : []),
                            ]); ?>
                            <?= $err('new_username') ?>

                            <div class="form-grid form-grid--2">
                                <div>
                                    <?php ui_field('new_password', 'New password', [
                                        'type'        => 'password',
                                        'placeholder' => 'At least 8 characters',
                                        'attrs'       => ['autocomplete' => 'new-password']
                                                         + (isset($errors['new_password']) ? ['aria-invalid' => 'true'] : []),
                                    ]); ?>
                                    <?= $err('new_password') ?>
                                </div>
                                <div>
                                    <?php ui_field('confirm_password', 'Confirm new password', [
                                        'type'        => 'password',
                                        'placeholder' => 'Type it again',
                                        'attrs'       => ['autocomplete' => 'new-password']
                                                         + (isset($errors['confirm_password']) ? ['aria-invalid' => 'true'] : []),
                                    ]); ?>
                                    <?= $err('confirm_password') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card__foot">
                        <span class="push-end">
                            <?= ui_btn('Update sign-in details', ['variant' => 'primary', 'icon' => 'ri-check-line']) ?>
                        </span>
                    </div>
                </section>
            </form>

            <section class="card">
                <div class="card__body row-flex row-flex--wrap">
                    <div>
                        <p class="t-medium t-strong mb-0">Sign out of the panel</p>
                        <p class="t-sm t-muted mb-0">You will need your username and password to get back in.</p>
                    </div>
                    <span class="push-end">
                        <?= ui_btn('Sign out', ['href' => url('logout'), 'variant' => 'danger-ghost', 'icon' => 'ri-logout-box-line']) ?>
                    </span>
                </div>
            </section>

        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
