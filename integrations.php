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

<form method="post" action="integrations" class="pb-5">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="integrations">

    <!-- Page Title & Top Actions -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4 pb-3 border-bottom">
        <div>
            <h4 class="mb-1 fw-bold">Integrations &amp; Mail Daemons</h4>
            <p class="text-body-secondary mb-0 fs-14">Connect third-party API services and configure system message delivery protocols.</p>
        </div>
        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
            <i class="ri-save-3-line fs-16"></i> Save Settings
        </button>
    </div>

    <!-- Section 1: Mail Routing & Daemon -->
    <div class="row g-4 mb-5">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-mail-settings-line text-primary fs-20"></i> Webmail &amp; SMTP Delivery
                </h6>
                <p class="text-body-secondary fs-13 mb-3">
                    Configure your incoming IMAP server to read mailbox correspondence directly in the Webmail tab, and your outgoing SMTP relay for automated notifications and replies.
                </p>
                <div class="alert bg-primary-subtle text-primary-emphasis border-0 rounded-3 fs-12 p-3 mb-0 d-flex gap-2">
                    <i class="ri-shield-flash-line fs-16 flex-shrink-0 mt-n1"></i>
                    <div>
                        <strong>Smart Routing Enabled:</strong> The mail engine automatically tests local relay sockets (<code>localhost:587/25</code>) first to bypass external firewall hairpin timeouts.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border rounded-3 h-100">
                <div class="card-body p-4">
                    <!-- Incoming Mail Server -->
                    <h6 class="fw-semibold fs-14 text-uppercase text-body-secondary mb-3">Incoming Server (IMAP)</h6>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-medium fs-13">IMAP Hostname</label>
                            <input type="text" name="mail_imap_host" class="form-control" value="<?= e(setting('mail_imap_host', 'mail.silknaviora.uz')) ?>" placeholder="e.g. mail.silknaviora.com">
                            <div class="form-text fs-12 text-body-secondary">Primary receiving hostname for mailbox synchronization.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium fs-13">Port</label>
                            <input type="number" name="mail_imap_port" class="form-control" value="<?= e(setting('mail_imap_port', '143')) ?>" min="1" max="65535" placeholder="143 or 993">
                            <div class="form-text fs-12 text-body-secondary">143 (TLS/Local) or 993 (SSL).</div>
                        </div>
                    </div>

                    <hr class="my-4 border-secondary-subtle opacity-50">

                    <!-- Outgoing Mail Server -->
                    <h6 class="fw-semibold fs-14 text-uppercase text-body-secondary mb-3">Outgoing Server (SMTP)</h6>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-medium fs-13">SMTP Hostname</label>
                            <input type="text" name="mail_smtp_host" class="form-control" value="<?= e(setting('mail_smtp_host', 'mail.silknaviora.uz')) ?>" placeholder="e.g. mail.silknaviora.com">
                            <div class="form-text fs-12 text-body-secondary">Used as external fallback when local routing is unavailable.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium fs-13">Port</label>
                            <input type="number" name="mail_smtp_port" class="form-control" value="<?= e(setting('mail_smtp_port', '587')) ?>" min="1" max="65535" placeholder="587 or 465">
                            <div class="form-text fs-12 text-body-secondary">587 (STARTTLS) or 465 (SSL).</div>
                        </div>
                    </div>

                    <hr class="my-4 border-secondary-subtle opacity-50">

                    <!-- Authentication Credentials -->
                    <h6 class="fw-semibold fs-14 text-uppercase text-body-secondary mb-3">Mailbox Credentials</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Email Address</label>
                            <input type="email" name="mail_username" class="form-control" value="<?= e(setting('mail_username', 'info@silknaviora.uz')) ?>" placeholder="info@example.com">
                            <div class="form-text fs-12 text-body-secondary">Full email address used for server authentication.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium fs-13">Mail Password</label>
                            <input type="password" name="mail_password" class="form-control" value="" placeholder="<?= setting('mail_password', '') !== '' ? '••••••••  (saved — leave blank to keep)' : 'Enter mailbox password' ?>" autocomplete="new-password">
                            <div class="form-text fs-12">
                                <?php if (setting('mail_password', '') !== ''): ?>
                                    <span class="text-success fw-medium"><i class="ri-checkbox-circle-fill me-1"></i>Secure password retained in database.</span>
                                <?php else: ?>
                                    <span class="text-body-secondary">Enter the password for this mailbox account.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Telegram Notifications -->
    <div class="row g-4 mb-5">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-telegram-fill text-info fs-20"></i> Telegram Bot &amp; Push Alerts
                </h6>
                <p class="text-body-secondary fs-13 mb-0">
                    Receive instant push notification messages directly to your Telegram device when clients book tour packages, send customized private inquiries, or fill out website contact forms.
                </p>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border rounded-3 h-100">
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-medium fs-13">Telegram Bot Token</label>
                        <input type="text" name="telegram_bot_token" class="form-control font-monospace fs-13" value="<?= e(setting('telegram_bot_token', '')) ?>" placeholder="123456789:ABCdefGHIjkLMN-opqRst_xyz...">
                        <div class="form-text fs-12 text-body-secondary">Obtain your token by registering a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener" class="text-decoration-none fw-semibold">@BotFather</a> on Telegram.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-medium fs-13">Target Chat / Group ID</label>
                        <input type="text" name="telegram_chat_id" class="form-control font-monospace fs-13" value="<?= e(setting('telegram_chat_id', '')) ?>" placeholder="e.g. 123456789 or -1001234567890">
                        <div class="form-text fs-12 text-body-secondary">Start a conversation with your bot first, or add it to your admin group. Locate your exact chat ID using <a href="https://t.me/userinfobot" target="_blank" rel="noopener" class="text-decoration-none fw-semibold">@userinfobot</a>.</div>
                    </div>
                </div>
                <div class="card-footer bg-body-tertiary border-top px-4 py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span class="fs-12 text-body-secondary">Test notification checks connection validity. Save settings before sending.</span>
                    <button type="submit" form="tgTestForm" class="btn btn-sm btn-outline-info fw-medium px-3 py-1 d-inline-flex align-items-center gap-1">
                        <i class="ri-send-plane-fill fs-14"></i> Send Test Message
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Google Maps Geolocation -->
    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="pe-xl-4">
                <h6 class="fw-bold fs-16 mb-2 d-flex align-items-center gap-2">
                    <i class="ri-map-pin-2-fill text-danger fs-20"></i> Google Maps Platform
                </h6>
                <p class="text-body-secondary fs-13 mb-0">
                    Connect your API Key to enable interactive location plotting, tour itinerary route maps, and geodetic destination rendering on the public travel portals.
                </p>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border rounded-3 h-100">
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-medium fs-13">Maps JavaScript &amp; Places API Key</label>
                        <input type="text" name="google_maps_api_key" class="form-control font-monospace fs-13" value="<?= e(setting('google_maps_api_key', '')) ?>" placeholder="AIzaSy...">
                        <div class="form-text fs-12 text-body-secondary">Requires Maps JavaScript API, Geocoding API, and Places API services activated within your Google Cloud Console.</div>
                    </div>
                    <div class="alert bg-warning-subtle text-warning-emphasis border-0 rounded-3 fs-12 p-3 mb-0 d-flex gap-2">
                        <i class="ri-error-warning-fill fs-16 flex-shrink-0 mt-n1"></i>
                        <div>
                            <strong>Security Protocol Reminder:</strong> Restrict your API key in Google Cloud Console to accept HTTP referrer traffic solely from your verified web domains (e.g. <code>*.silknaviora.com/*</code>).
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Save Confirmation -->
    <div class="d-flex justify-content-end align-items-center pt-4 mt-5 border-top">
        <button type="submit" class="btn btn-primary px-5 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
            <i class="ri-save-3-line fs-18"></i> Save Integration Settings
        </button>
    </div>
</form>

<!-- Standalone form for the Telegram test action -->
<form id="tgTestForm" method="post" action="integrations" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_telegram">
</form>

<?php require __DIR__ . '/partials/foot.php'; ?>
