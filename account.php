<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';

$me = admin_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('action', 'credentials');

    if ($action === 'credentials') {
        $cur = (string) input('current_password', '');
        $newUser = trim((string) input('new_username', ''));
        $newPass = (string) input('new_password', '');
        $confirm = (string) input('confirm_password', '');

        $row = db_one('SELECT password_hash FROM admins WHERE id = ?', [$me['id']]);
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif ($newUser === '') {
            flash('error', 'Username cannot be empty.');
        } elseif ($newPass !== '' && $newPass !== $confirm) {
            flash('error', 'New passwords do not match.');
        } elseif ($newPass !== '' && strlen($newPass) < 6) {
            flash('error', 'New password must be at least 6 characters.');
        } else {
            admin_update_credentials((int) $me['id'], $newUser, $newPass !== '' ? $newPass : null);
            flash('success', 'Administrator login credentials updated successfully.');
        }
        redirect('account');
    }
}

$page = ['title' => 'Admin Account', 'section' => 'System', 'active' => 'account'];
require __DIR__ . '/partials/head.php';
?>

<div class="pb-5">
    <!-- Page Title & Header -->
    <div class="mb-4 pb-3 border-bottom">
        <h4 class="mb-1 fw-bold">Administrator Account &amp; Security</h4>
        <p class="text-body-secondary mb-0 fs-14">Manage session privileges, review authorization status, and update login authentication credentials.</p>
    </div>

    <!-- Section 1: Session Overview -->
    <div class="row g-4 mb-5">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-user-star-line text-primary fs-20"></i> Active Profile &amp; Permissions
                </h6>
                <p class="text-body-secondary fs-13 mb-0">
                    Your administrative account maintains complete supervisory access over tour itineraries, bookings, traveler correspondence, and core configuration tokens.
                </p>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border rounded-3">
                <div class="card-body p-4 d-flex align-items-center justify-content-between flex-wrap gap-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-primary-subtle text-primary fw-bold rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 64px; height: 64px; font-size: 24px;">
                            <?= strtoupper(substr(e($me['username'] ?? 'A'), 0, 1)) ?>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h5 class="fw-bold mb-0"><?= e($me['display_name'] ?? $me['username'] ?? 'Administrator') ?></h5>
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0 fs-11">Online</span>
                            </div>
                            <span class="text-body-secondary fs-13">@<?= e($me['username'] ?? 'admin') ?> &middot; System Administrator #<?= (int) ($me['id'] ?? 1) ?></span>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <a href="<?= BASE_PATH ?>/dashboard" class="btn btn-sm btn-outline-secondary px-3 py-2 fw-medium d-inline-flex align-items-center gap-1">
                            <i class="ri-dashboard-line fs-16"></i> Dashboard
                        </a>
                        <a href="<?= BASE_PATH ?>/logout" class="btn btn-sm btn-outline-danger px-3 py-2 fw-medium d-inline-flex align-items-center gap-1">
                            <i class="ri-logout-box-r-line fs-16"></i> Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Security & Credentials -->
    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-shield-keyhole-line text-warning fs-20"></i> Authentication Update
                </h6>
                <p class="text-body-secondary fs-13 mb-3">
                    Change your username or set a new password. To protect against unauthorized alterations from an unlocked terminal, entering your current valid password is required to execute changes.
                </p>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <form method="post" action="account">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="credentials">

                <div class="card shadow-sm border rounded-3 overflow-hidden">
                    <div class="card-body p-4">
                        <!-- Verification Step -->
                        <h6 class="fw-semibold fs-14 text-uppercase text-body-secondary mb-3">Security Verification</h6>
                        <div class="mb-4">
                            <label class="form-label fw-medium fs-13">Current Password <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" class="form-control" placeholder="Enter existing password to confirm identity" required>
                            <div class="form-text fs-12 text-body-secondary">Required before saving any authentication updates below.</div>
                        </div>

                        <hr class="my-4 border-secondary-subtle opacity-50">

                        <!-- New Credentials Step -->
                        <h6 class="fw-semibold fs-14 text-uppercase text-body-secondary mb-3">New Login Profile</h6>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-medium fs-13">Admin Username</label>
                                <input type="text" name="new_username" class="form-control fw-semibold" value="<?= e($me['username']) ?>" required>
                                <div class="form-text fs-12 text-body-secondary">Unique profile identifier required at sign-in.</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label fw-medium fs-13">New Password</label>
                                <input type="password" name="new_password" class="form-control" placeholder="Leave blank to retain current">
                                <div class="form-text fs-12 text-body-secondary">Minimum 6 characters required if updating.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-medium fs-13">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password">
                                <div class="form-text fs-12 text-body-secondary">Must match the new password exactly.</div>
                            </div>
                        </div>
                    </div>

                    <div class="card-footer bg-body-tertiary border-top px-4 py-3 d-flex justify-content-end align-items-center">
                        <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="ri-check-line fs-18"></i> Update Security Credentials
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
