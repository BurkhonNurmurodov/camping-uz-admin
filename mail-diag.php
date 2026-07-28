<?php
// Temporary diagnostic page for the webmail feature.
// Upload to the server next to email.php, open /mail-diag.php as admin, read the report.
// Delete it from the server once the mail issue is fixed.
require __DIR__ . '/app/bootstrap.php';
require_admin();

// MailClient.php normally loads this; load it here too so the class checks below reflect reality.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

header('Content-Type: text/plain; charset=utf-8');

function line($label, $value) {
    echo str_pad($label, 46) . ': ' . $value . "\n";
}
function check($label, $ok, $extra = '') {
    line($label, ($ok ? 'OK' : '!! PROBLEM') . ($extra !== '' ? ' — ' . $extra : ''));
    return $ok;
}

echo "=== Webmail diagnostics (" . date('Y-m-d H:i:s') . ") ===\n\n";

echo "--- PHP environment ---\n";
line('PHP version', PHP_VERSION);
line('SAPI', PHP_SAPI);
line('Loaded php.ini', php_ini_loaded_file() ?: 'none');
foreach (['imap', 'mbstring', 'iconv', 'fileinfo', 'json', 'openssl'] as $ext) {
    check("Extension: $ext", extension_loaded($ext), extension_loaded($ext) ? '' : "PHP extension \"$ext\" is not enabled");
}

echo "\n--- Composer / php-imap library ---\n";
$vendorDir = __DIR__ . '/vendor';
check('vendor/autoload.php exists', is_file($vendorDir . '/autoload.php'));
$mailboxFile = $vendorDir . '/php-imap/php-imap/src/PhpImap/Mailbox.php';
check('php-imap package files present', is_file($mailboxFile), is_file($mailboxFile) ? '' : 'vendor/php-imap is missing — re-upload vendor/ or run composer install');

$psr4File = $vendorDir . '/composer/autoload_psr4.php';
$psr4HasPhpImap = false;
if (is_file($psr4File)) {
    $psr4 = require $psr4File;
    $psr4HasPhpImap = isset($psr4['PhpImap\\']);
}
check('Autoloader maps PhpImap\\ namespace', $psr4HasPhpImap, $psr4HasPhpImap ? '' : 'vendor/composer/autoload_* files are stale — re-upload the whole vendor/ folder');

$installedVersion = 'unknown';
$installedJson = $vendorDir . '/composer/installed.json';
if (is_file($installedJson)) {
    $data = json_decode((string) file_get_contents($installedJson), true);
    $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
    foreach (is_array($packages) ? $packages : [] as $pkg) {
        if (is_array($pkg) && ($pkg['name'] ?? '') === 'php-imap/php-imap') {
            $installedVersion = $pkg['version'] ?? 'unknown';
        }
    }
}
line('php-imap version per installed.json', $installedVersion);
check('class_exists(PhpImap\\Mailbox)', class_exists('PhpImap\\Mailbox'), class_exists('PhpImap\\Mailbox') ? '' : 'THIS is the error the mail page shows');
check('class_exists(PHPMailer)', class_exists('PHPMailer\\PHPMailer\\PHPMailer'));

echo "\n--- Mail settings in use ---\n";
$imapHost = (string) setting('mail_imap_host', getenv('IMAP_HOST') ?: 'mail.silknaviora.com');
$login    = (string) setting('mail_username', getenv('MAIL_USER') ?: 'info@silknaviora.com');
$password = (string) setting('mail_password', getenv('MAIL_PASS') ?: '');
$smtpHost = (string) setting('mail_smtp_host', getenv('SMTP_HOST') ?: 'mail.silknaviora.com');
$smtpPort = (int) setting('mail_smtp_port', getenv('SMTP_PORT') ?: 587);
line('IMAP host', $imapHost . ':993 (ssl)');
line('SMTP host', $smtpHost . ':' . $smtpPort);
line('Username', $login);
line('Password', $password === '' ? '!! EMPTY' : 'set (' . strlen($password) . ' chars)');

$attachDir = __DIR__ . '/assets/mail_attachments';
check('Attachments dir exists', is_dir($attachDir), is_dir($attachDir) ? '' : $attachDir . ' is missing — Mailbox constructor fails without it');
if (is_dir($attachDir)) {
    check('Attachments dir writable', is_writable($attachDir));
}

echo "\n--- Network reachability (no ext-imap needed) ---\n";
foreach ([[$imapHost, 993, 'IMAP'], [$smtpHost, $smtpPort, 'SMTP']] as [$host, $port, $what]) {
    $errno = 0; $errstr = '';
    $tcp = @fsockopen('tcp://' . $host, $port, $errno, $errstr, 10);
    check("$what tcp connect $host:$port", (bool) $tcp, $tcp ? '' : "$errstr (errno $errno)");
    if ($tcp) {
        fclose($tcp);
        if (!in_array($port, [465, 993, 995], true)) {
            // 25/143/587 use STARTTLS — an immediate TLS handshake would always fail there.
            line("$what ssl handshake $host:$port", "skipped (port $port uses STARTTLS, not implicit TLS)");
            continue;
        }
        $errno = 0; $errstr = '';
        $ssl = @fsockopen('ssl://' . $host, $port, $errno, $errstr, 10);
        $detail = trim("$errstr (errno $errno)");
        if (!$ssl) {
            $last = error_get_last();
            if ($last && stripos($last['message'], 'ssl') !== false) {
                $detail = $last['message'];
            }
        }
        check("$what ssl handshake $host:$port", (bool) $ssl, $ssl ? '' : $detail . " — TLS/certificate validation failed; the certificate may not cover \"$host\"");
        if ($ssl) {
            fclose($ssl);
        }
    }
}

echo "\n--- Live IMAP login test ---\n";
// Uses only raw imap_* functions, so it works even while the php-imap library is missing.
if (!extension_loaded('imap')) {
    echo "Skipped: PHP imap extension is not loaded.\n";
} else {
    imap_timeout(IMAP_OPENTIMEOUT, 10);
    $stream = @imap_open('{' . $imapHost . ':993/imap/ssl}INBOX', $login, $password, OP_READONLY, 1);
    if ($stream) {
        $info = imap_check($stream);
        check('IMAP login + INBOX open', true);
        line('Messages in INBOX', $info ? $info->Nmsgs : '?');
        imap_close($stream);
    } else {
        check('IMAP login + INBOX open', false, (string) imap_last_error());
        imap_errors();
        imap_alerts();
        // Retry without certificate validation to tell cert problems apart from credential problems.
        $relaxed = @imap_open('{' . $imapHost . ':993/imap/ssl/novalidate-cert}INBOX', $login, $password, OP_READONLY, 1);
        if ($relaxed) {
            line('Retry with novalidate-cert', "WORKS — credentials are fine, but the TLS certificate does not cover \"$imapHost\". Extend the certificate or use a hostname it covers.");
            imap_close($relaxed);
        } else {
            line('Retry with novalidate-cert', 'also fails — ' . (string) imap_last_error() . ' (likely wrong username/password or IMAP disabled for this account)');
        }
    }
    imap_errors();
    imap_alerts();
}

echo "\n=== End of report — delete this file from the server when done ===\n";
