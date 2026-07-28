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
            flash('success', 'Administrator account updated successfully.');
        }
        redirect('account');
    }
}

$page = ['title' => 'Admin Account', 'section' => 'System', 'active' => 'account'];
require __DIR__ . '/partials/head.php';
?>

<style>
    .account-card {
        border: 1px solid rgba(0,0,0,0.08);
        border-radius: 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        overflow: hidden;
    }
    .account-header {
        background: #f8fafc;
        border-bottom: 1px solid rgba(0,0,0,0.06);
        padding: 18px 24px;
    }
    .profile-avatar-large {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0f172a 0%, #334155 100%);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        font-weight: 700;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
    }
</style>

<div class="row g-4">
    <!-- Left Column: Profile Summary & Overview -->
    <div class="col-12 col-xl-4">
        <div class="card account-card h-100">
            <div class="account-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="ri-user-star-line text-primary me-2 fs-18 align-middle"></i> Active Admin Session</h6>
                <span class="badge bg-success-subtle text-success border rounded-pill px-2 py-1 fs-11">Online</span>
            </div>
            <div class="card-body p-4 text-center d-flex flex-column align-items-center justify-content-center">
                <div class="profile-avatar-large mb-3">
                    <?= strtoupper(substr(e($me['username'] ?? 'A'), 0, 1)) ?>
                </div>
                <h5 class="fw-bold text-dark mb-1"><?= e($me['display_name'] ?? $me['username'] ?? 'Administrator') ?></h5>
                <p class="text-muted fs-13 mb-3">@<?= e($me['username'] ?? 'admin') ?> &middot; ID #<?= (int) ($me['id'] ?? 1) ?></p>
                
                <div class="d-inline-flex align-items-center gap-2 px-3 py-2 bg-light-subtle border rounded-pill text-muted fs-12 mb-4">
                    <i class="ri-shield-keyhole-fill text-warning fs-14"></i> Full System Access Rights
                </div>

                <div class="w-100 border-top pt-3 d-flex justify-content-center gap-2">
                    <a href="<?= BASE_PATH ?>/dashboard" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                        <i class="ri-dashboard-line me-1"></i> Dashboard
                    </a>
                    <a href="<?= BASE_PATH ?>/logout" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                        <i class="ri-logout-box-r-line me-1"></i> Sign Out
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Login Update Form -->
    <div class="col-12 col-xl-8">
        <div class="card account-card">
            <div class="account-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                        <i class="ri-lock-password-line fs-22"></i>
                    </div>
                    <div>
                        <h5 class="card-title mb-0 fw-bold">Login Credentials &amp; Security</h5>
                        <span class="text-muted fs-13">Change your administrative login username and password</span>
                    </div>
                </div>
            </div>

            <div class="card-body p-4">
                <form method="post" action="account" class="row g-4" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="credentials">
                    
                    <!-- Current Password verification prompt -->
                    <div class="col-12">
                        <div class="p-3 bg-light-subtle rounded-3 border">
                            <label class="form-label fw-bold text-dark fs-13 d-flex align-items-center">
                                <i class="ri-shield-check-line text-primary fs-16 me-2"></i> Current Password <span class="text-danger ms-1">*</span>
                            </label>
                            <input type="password" name="current_password" class="form-control" placeholder="Enter your current password to authorize changes" required>
                            <div class="form-text fs-12 mb-0">Required for security verification before any credentials can be updated.</div>
                        </div>
                    </div>

                    <div class="col-12"><hr class="my-0 opacity-25"></div>

                    <!-- New Username -->
                    <div class="col-md-12">
                        <label class="form-label fw-medium fs-13">Admin Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="ri-user-3-line text-muted"></i></span>
                            <input type="text" name="new_username" class="form-control fw-semibold" value="<?= e($me['username']) ?>" required>
                        </div>
                        <div class="form-text fs-12">This username is required every time you sign in to the administration panel.</div>
                    </div>

                    <!-- New Password & Confirmation -->
                    <div class="col-md-6">
                        <label class="form-label fw-medium fs-13">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="ri-key-2-line text-muted"></i></span>
                            <input type="password" name="new_password" class="form-control" placeholder="Leave blank to retain current">
                        </div>
                        <div class="form-text fs-12">Must be at least 6 characters long if changing.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-medium fs-13">Confirm New Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="ri-key-2-line text-muted"></i></span>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Re-type new password">
                        </div>
                        <div class="form-text fs-12">Ensure both password inputs match exactly.</div>
                    </div>

                    <!-- Submit action -->
                    <div class="col-12 pt-3 border-top mt-4 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-5 rounded-pill fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="ri-check-line fs-18"></i> Update Security Credentials
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
