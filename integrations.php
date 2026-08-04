<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require_once __DIR__ . '/app/MailClient.php';

/** Fields stored once per mailbox, prefixed with the account's key prefix. */
const MAIL_ACCOUNT_FIELDS = [
    'label', 'from_name', 'username', 'imap_host', 'imap_port', 'smtp_host', 'smtp_port',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input('action', 'save');

    if ($action === 'test_telegram') {
        $r = telegram_send('🔔 <b>Silk Naviora</b> — test notification. If you can read this, notifications are configured correctly.');
        if ($r['ok']) { flash('success', 'Test message sent — check Telegram.'); }
        else { flash('error', 'Telegram test failed: ' . $r['error']); }
        redirect('integrations');
    }

    foreach (['telegram_bot_token', 'telegram_chat_id', 'google_maps_api_key'] as $k) {
        set_setting($k, trim((string) input($k, '')));
    }

    foreach (\App\MailClient::ACCOUNT_PREFIXES as $slot => $prefix) {
        foreach (MAIL_ACCOUNT_FIELDS as $field) {
            set_setting($prefix . $field, trim((string) input($prefix . $field, '')));
        }
        // A blank password means "keep the stored one" — the form never renders
        // the saved value back to the browser.
        $password = (string) input($prefix . 'password', '');
        if ($password !== '') {
            set_setting($prefix . 'password', $password);
        }
        // Clearing the address retires the mailbox; drop its password too rather
        // than leaving a live credential behind for an account nobody can see.
        if (trim((string) input($prefix . 'username', '')) === '' && $slot !== 1) {
            set_setting($prefix . 'password', '');
        }
    }

    flash('success', 'Integrations saved.');
    redirect('integrations');
}

// One editable panel per mailbox slot. Slot 1 keeps the historical "mail_*"
// keys and its own defaults; every other slot starts empty and inherits the
// primary server.
$mailSlots = [];
foreach (\App\MailClient::ACCOUNT_PREFIXES as $slot => $prefix) {
    $isPrimary = ($slot === 1);
    $address   = (string) setting($prefix . 'username', $isPrimary ? 'info@silknaviora.uz' : '');
    $label     = (string) setting($prefix . 'label', '');
    $mailSlots[$slot] = [
        'id'         => $slot,
        'prefix'     => $prefix,
        'isPrimary'  => $isPrimary,
        'address'    => $address,
        'label'      => $label,
        'tabTitle'   => $label !== '' ? $label : ($address !== '' ? $address : 'Second mailbox'),
        'configured' => $address !== '',
    ];
}

$page = [
    'title'    => 'Settings',
    'subtitle' => 'Mail delivery, notifications and map keys.',
    'active'   => 'settings',
    'tabs'     => admin_settings_tabs('integrations'),
];
require __DIR__ . '/partials/head.php';
?>

<form method="post" action="<?= url('integrations') ?>" data-guard>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <div class="row">
        <div class="col-12 col-xl-9">
            <div class="stack">

                <section class="card">
                    <div class="card__head">
                        <div>
                            <h2 class="card__title">Mailboxes</h2>
                            <p class="card__sub">Read and send mail from inside the panel. Fill in the second tab to add another address.</p>
                        </div>
                    </div>
                    <div class="card__body">
                        <div data-tabs>
                            <div class="tabs tabs--inline" role="tablist" aria-label="Mailboxes">
                                <?php $first = true; foreach ($mailSlots as $slot): ?>
                                    <button type="button" class="tab<?= $first ? ' is-active' : '' ?>"
                                            role="tab" aria-selected="<?= $first ? 'true' : 'false' ?>"
                                            data-tab-target="mailAccount<?= (int) $slot['id'] ?>">
                                        <?= e($slot['tabTitle']) ?>
                                        <?php if (!$slot['configured']): ?>
                                            <span class="badge badge--outline">not set up</span>
                                        <?php endif; ?>
                                    </button>
                                    <?php $first = false; endforeach; ?>
                            </div>

                            <div>
                                <?php $first = true; foreach ($mailSlots as $slot):
                                    $p         = $slot['prefix'];
                                    $isPrimary = $slot['isPrimary'];
                                    $hasPass   = setting($p . 'password', '') !== '';
                                    $hostPh    = $isPrimary ? 'mail.example.com' : (setting('mail_imap_host', 'mail.silknaviora.uz') . ' (same as primary)');
                                    $smtpPh    = $isPrimary ? 'mail.example.com' : (setting('mail_smtp_host', 'mail.silknaviora.uz') . ' (same as primary)');
                                ?>
                                <div class="tab-panel<?= $first ? ' is-active' : '' ?>" id="mailAccount<?= (int) $slot['id'] ?>" role="tabpanel">

                                    <?php if (!$isPrimary): ?>
                                        <div class="alert alert--info">
                                            <i class="alert__icon ri-information-line" aria-hidden="true"></i>
                                            <div class="alert__body">
                                                Leave the server fields blank to reuse the primary mailbox's servers.
                                                Clear the email address to remove this mailbox from the Mail page.
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="form-grid form-grid--2">
                                        <?php ui_field($p . 'label', 'Display name in the panel', [
                                            'value'       => setting($p . 'label', ''),
                                            'placeholder' => $isPrimary ? 'e.g. Info desk' : 'e.g. Bookings',
                                            'hint'        => 'Shown in the mailbox switcher.',
                                        ]); ?>
                                        <?php ui_field($p . 'from_name', 'Sender name', [
                                            'value'       => setting($p . 'from_name', ''),
                                            'placeholder' => setting('agency_name_en', 'Silk Naviora'),
                                            'hint'        => 'What recipients see on mail you send.',
                                        ]); ?>
                                    </div>

                                    <div class="form-section">
                                        <p class="form-section__title">Sign in</p>
                                        <div class="form-grid form-grid--2">
                                            <?php ui_field($p . 'username', 'Email address', [
                                                'type'        => 'email',
                                                'value'       => setting($p . 'username', $isPrimary ? 'info@silknaviora.uz' : ''),
                                                'placeholder' => $isPrimary ? 'info@example.com' : 'bookings@example.com',
                                            ]); ?>
                                            <?php ui_field($p . 'password', 'Mailbox password', [
                                                'type'        => 'password',
                                                'placeholder' => $hasPass ? '•••••••• saved' : 'Enter the password',
                                                'hint'        => $hasPass ? 'A password is saved. Leave blank to keep it.' : 'Stored so the panel can sign in for you.',
                                                'attrs'       => ['autocomplete' => 'new-password'],
                                            ]); ?>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <p class="form-section__title">Incoming mail (IMAP)</p>
                                        <div class="form-grid form-grid--2">
                                            <?php ui_field($p . 'imap_host', 'Server', [
                                                'value' => setting($p . 'imap_host', $isPrimary ? 'mail.silknaviora.uz' : ''),
                                                'placeholder' => $hostPh,
                                            ]); ?>
                                            <?php ui_field($p . 'imap_port', 'Port', [
                                                'type'  => 'number',
                                                'value' => setting($p . 'imap_port', $isPrimary ? '143' : ''),
                                                'placeholder' => '143 or 993',
                                                'hint'  => '143 for STARTTLS, 993 for SSL.',
                                                'attrs' => ['min' => 1, 'max' => 65535],
                                            ]); ?>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <p class="form-section__title">Outgoing mail (SMTP)</p>
                                        <div class="form-grid form-grid--2">
                                            <?php ui_field($p . 'smtp_host', 'Server', [
                                                'value' => setting($p . 'smtp_host', $isPrimary ? 'mail.silknaviora.uz' : ''),
                                                'placeholder' => $smtpPh,
                                            ]); ?>
                                            <?php ui_field($p . 'smtp_port', 'Port', [
                                                'type'  => 'number',
                                                'value' => setting($p . 'smtp_port', $isPrimary ? '587' : ''),
                                                'placeholder' => '587 or 465',
                                                'hint'  => '587 for STARTTLS, 465 for SSL.',
                                                'attrs' => ['min' => 1, 'max' => 65535],
                                            ]); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php $first = false; endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card__foot">
                        <i class="ri-shield-check-line t-muted" aria-hidden="true"></i>
                        <span class="t-xs t-muted">
                            The panel tries a local mail relay first, then falls back to the server above.
                        </span>
                        <span class="push-end">
                            <?= ui_btn('Run mail diagnostics', ['href' => url('mail-diag'), 'size' => 'sm', 'icon' => 'ri-stethoscope-line']) ?>
                        </span>
                    </div>
                </section>

                <section class="card">
                    <div class="card__head">
                        <div>
                            <h2 class="card__title">Telegram alerts</h2>
                            <p class="card__sub">Get a message the moment someone registers or writes in.</p>
                        </div>
                    </div>
                    <div class="card__body">
                        <?php ui_field('telegram_bot_token', 'Bot token', [
                            'value'       => setting('telegram_bot_token', ''),
                            'placeholder' => '123456789:ABCdef…',
                            'hint'        => 'Create a bot with @BotFather on Telegram to get this.',
                            'attrs'       => ['class' => 'input t-mono'],
                        ]); ?>
                        <?php ui_field('telegram_chat_id', 'Chat or group ID', [
                            'value'       => setting('telegram_chat_id', ''),
                            'placeholder' => '123456789 or -1001234567890',
                            'hint'        => 'Message your bot first, or add it to a group. @userinfobot tells you the ID.',
                            'attrs'       => ['class' => 'input t-mono'],
                        ]); ?>
                    </div>
                    <div class="card__foot">
                        <span class="t-xs t-muted">Save first, then send a test.</span>
                        <span class="push-end">
                            <?= ui_btn('Send test message', [
                                'size' => 'sm', 'icon' => 'ri-send-plane-line',
                                'attrs' => ['form' => 'tgTestForm'],
                            ]) ?>
                        </span>
                    </div>
                </section>

                <section class="card">
                    <div class="card__head">
                        <div>
                            <h2 class="card__title">Google Maps</h2>
                            <p class="card__sub">Needed for the route picker in the tour editor and the map on the public site.</p>
                        </div>
                    </div>
                    <div class="card__body">
                        <?php ui_field('google_maps_api_key', 'Maps JavaScript API key', [
                            'value'       => setting('google_maps_api_key', ''),
                            'placeholder' => 'AIzaSy…',
                            'hint'        => 'Enable Maps JavaScript, Geocoding and Places in Google Cloud Console.',
                            'attrs'       => ['class' => 'input t-mono'],
                        ]); ?>
                        <div class="alert alert--warning mb-0">
                            <i class="alert__icon ri-shield-keyhole-line" aria-hidden="true"></i>
                            <div class="alert__body">
                                Restrict the key to your own domains in Google Cloud Console, or anyone can use it at your expense.
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </div>

    <?php ui_sticky_actions('Save integrations'); ?>
</form>

<form id="tgTestForm" method="post" action="<?= url('integrations') ?>" class="hide">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_telegram">
</form>

<?php require __DIR__ . '/partials/foot.php'; ?>
