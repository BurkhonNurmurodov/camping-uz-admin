<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';

$me = admin_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('action', 'integrations');

    if ($action === 'integrations') {
        foreach ([
            'telegram_bot_token', 'telegram_chat_id', 'google_maps_api_key',
            'mail_imap_host', 'mail_imap_port', 'mail_username', 'mail_smtp_host', 'mail_smtp_port',
        ] as $k) {
            set_setting($k, trim((string) input($k, '')));
        }
        
        $mailPassword = (string) input('mail_password', '');
        if ($mailPassword !== '') {
            set_setting('mail_password', $mailPassword);
        }

        flash('success', 'Integration settings saved successfully.');
        redirect('integrations');
    }

    if ($action === 'test_telegram') {
        $r = telegram_send('🔔 <b>Silk Naviora</b> — test notification. If you can read this, notifications are configured correctly.');
        if ($r['ok']) {
            flash('success', 'Test message sent — check your Telegram.');
        } else {
            flash('error', 'Telegram test failed: ' . $r['error']);
        }
        redirect('integrations');
    }
}

$page = ['title' => 'Integrations & Mail', 'section' => 'System', 'active' => 'integrations'];
require __DIR__ . '/partials/head.php';
?>

<!-- Custom UI styling for clean cards and structure -->
<style>
    .integration-card {
        border: 1px solid rgba(0,0,0,0.08);
        border-radius: 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        transition: transform 0.2s, box-shadow 0.2s;
        overflow: hidden;
    }
    .integration-header {
        background: #f8fafc;
        border-bottom: 1px solid rgba(0,0,0,0.06);
        padding: 18px 24px;
    }
    .section-divider {
        height: 1px;
        background-color: #e2e8f0;
        margin: 28px 0;
    }
    .help-box {
        background: #f1f5f9;
        border-radius: 10px;
        padding: 14px 18px;
        font-size: 13px;
        color: #475569;
        border-left: 4px solid #3b82f6;
    }
</style>

<form method="post" action="integrations">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="integrations">

    <div class="row g-4">
        <!-- Webmail & SMTP Daemon Configuration -->
        <div class="col-12">
            <div class="card integration-card">
                <div class="integration-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="ri-mail-settings-line fs-22"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-0 fw-bold">Webmail &amp; Mail Daemon Configuration</h5>
                            <span class="text-muted fs-13">Manage IMAP inbox reading and SMTP outgoing mail delivery settings</span>
                        </div>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border rounded-pill px-3 py-2 fw-semibold fs-12">
                        <i class="ri-server-line me-1"></i> Mail Protocols
                    </span>
                </div>
                
                <div class="card-body p-4">
                    <div class="help-box mb-4">
                        <div class="d-flex gap-2">
                            <i class="ri-information-line fs-18 text-primary flex-shrink-0 mt-n1"></i>
                            <div>
                                <strong>System Routing Note:</strong> The MailClient engine automatically prioritizes native <strong>Localhost Relaying</strong> to bypass network hairpin NAT loops and firewall restrictions. Ensure the login credentials match your primary mailbox.
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Left Sub-column: Incoming Mail (IMAP) -->
                        <div class="col-12 col-xl-6">
                            <div class="p-3 bg-light-subtle rounded-3 border h-100">
                                <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                                    <i class="ri-inbox-archive-line text-info fs-18"></i> Incoming Mail Server (IMAP)
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label fw-medium fs-13">IMAP Host</label>
                                        <input type="text" name="mail_imap_host" class="form-control" value="<?= e(setting('mail_imap_host', 'mail.silknaviora.uz')) ?>" placeholder="e.g. mail.silknaviora.com">
                                        <div class="form-text fs-12">Hostname of your mail server for reading the inbox.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium fs-13">IMAP Port</label>
                                        <input type="number" name="mail_imap_port" class="form-control" value="<?= e(setting('mail_imap_port', '143')) ?>" min="1" max="65535" placeholder="143 or 993">
                                        <div class="form-text fs-12">143 (STARTTLS) or 993 (SSL).</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Sub-column: Outgoing Mail (SMTP) -->
                        <div class="col-12 col-xl-6">
                            <div class="p-3 bg-light-subtle rounded-3 border h-100">
                                <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                                    <i class="ri-send-plane-line text-success fs-18"></i> Outgoing Mail Server (SMTP)
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label fw-medium fs-13">SMTP Host</label>
                                        <input type="text" name="mail_smtp_host" class="form-control" value="<?= e(setting('mail_smtp_host', 'mail.silknaviora.uz')) ?>" placeholder="e.g. mail.silknaviora.com">
                                        <div class="form-text fs-12">External backup delivery server hostname.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium fs-13">SMTP Port</label>
                                        <input type="number" name="mail_smtp_port" class="form-control" value="<?= e(setting('mail_smtp_port', '587')) ?>" min="1" max="65535">
                                        <div class="form-text fs-12">587 (STARTTLS) or 465 (SSL).</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Full Width: Authentication Credentials -->
                        <div class="col-12">
                            <h6 class="fw-bold text-dark mb-3 pt-2">Mailbox Authentication</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-medium fs-13">Mail Username (Email Address)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="ri-user-line text-muted"></i></span>
                                        <input type="email" name="mail_username" class="form-control" value="<?= e(setting('mail_username', 'info@silknaviora.uz')) ?>" placeholder="info@example.com">
                                    </div>
                                    <div class="form-text fs-12">Full email address used for IMAP and SMTP login.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-medium fs-13">Mail Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="ri-lock-password-line text-muted"></i></span>
                                        <input type="password" name="mail_password" class="form-control" value="" placeholder="<?= setting('mail_password', '') !== '' ? '••••••••  (saved — leave blank to keep)' : 'Enter mailbox password' ?>" autocomplete="new-password">
                                    </div>
                                    <div class="form-text fs-12 text-success">
                                        <?php if (setting('mail_password', '') !== ''): ?>
                                            <i class="ri-shield-check-fill me-1"></i> A secure password is currently saved. Leave blank to retain it.
                                        <?php else: ?>
                                            Enter the mailbox account password.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Telegram Notifications -->
        <div class="col-12 col-xl-6">
            <div class="card integration-card h-100">
                <div class="integration-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="ri-telegram-fill fs-24"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-0 fw-bold">Telegram Notifications</h5>
                            <span class="text-muted fs-13">Instant alerts for registrations and inquiries</span>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="mb-3">
                            <label class="form-label fw-medium fs-13">Telegram Bot Token</label>
                            <input type="text" name="telegram_bot_token" class="form-control font-monospace text-dark fs-14" value="<?= e(setting('telegram_bot_token', '')) ?>" placeholder="123456789:ABCdefGHIjkLMN-opqRst...">
                            <div class="form-text fs-12">Obtain this token by creating a bot via <a href="https://t.me/BotFather" target="_blank" rel="noopener" class="text-decoration-none fw-semibold">@BotFather</a> on Telegram.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-medium fs-13">Telegram Chat ID</label>
                            <input type="text" name="telegram_chat_id" class="form-control font-monospace text-dark fs-14" value="<?= e(setting('telegram_chat_id', '')) ?>" placeholder="e.g. 123456789 or -10012345678">
                            <div class="form-text fs-12">
                                DM your bot first or add it to your group. Find your ID instantly with <a href="https://t.me/userinfobot" target="_blank" rel="noopener" class="text-decoration-none fw-semibold">@userinfobot</a>.
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border-top mt-2 d-flex align-items-center justify-content-between">
                        <span class="fs-12 text-muted">Test connection after saving changes</span>
                        <button type="submit" form="tgTestForm" class="btn btn-sm btn-outline-info rounded-pill px-3 fw-medium d-flex align-items-center gap-1">
                            <i class="ri-send-plane-fill"></i> Send Test Message
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Google Maps API -->
        <div class="col-12 col-xl-6">
            <div class="card integration-card h-100">
                <div class="integration-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="ri-map-pin-user-fill fs-22"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-0 fw-bold">Google Maps Geolocation</h5>
                            <span class="text-muted fs-13">Interactive tour routes and location pickers</span>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="mb-4">
                            <label class="form-label fw-medium fs-13">Google Maps API Key</label>
                            <input type="text" name="google_maps_api_key" class="form-control font-monospace text-dark fs-14" value="<?= e(setting('google_maps_api_key', '')) ?>" placeholder="AIzaSy...">
                            <div class="form-text fs-12">Requires Maps JavaScript API, Places API, and Geocoding API enabled in Google Cloud Console.</div>
                        </div>

                        <div class="help-box mb-0" style="border-left-color: #ef4444;">
                            <div class="d-flex gap-2">
                                <i class="ri-shield-keyhole-line fs-18 text-danger flex-shrink-0 mt-n1"></i>
                                <div>
                                    <strong>Security Recommendation:</strong> Ensure your API Key is restricted in the Google Cloud Dashboard to accept requests only from your authorized website domain (<code>*.silknaviora.com</code>).
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sticky or bottom action bar -->
    <div class="card mt-4 border-0 bg-dark text-white shadow-sm rounded-4">
        <div class="card-body py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-2">
                <i class="ri-checkbox-circle-fill text-success fs-20"></i>
                <span class="fs-14 fw-medium">All changes take effect immediately across all background tasks and mail daemons.</span>
            </div>
            <button type="submit" class="btn btn-primary px-4 rounded-pill fw-semibold shadow-sm">
                <i class="ri-save-3-line me-2"></i> Save Integration Settings
            </button>
        </div>
    </div>
</form>

<!-- Standalone form for the Telegram test button -->
<form id="tgTestForm" method="post" action="integrations" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_telegram">
</form>

<?php require __DIR__ . '/partials/foot.php'; ?>
