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

echo "\n--- Local SMTP transports (what the webmail actually sends through) ---\n";
// Mirrors MailClient::buildSendStrategies(). Each probe is timed: a transport that
// connects but never answers is what makes the compose spinner run for minutes.
function smtp_probe(string $host, int $port, int $timeout = 5): array {
    $scheme = ($port === 465 ? 'ssl://' : 'tcp://');
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
    ]]);
    $t0 = microtime(true);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    $connectMs = (int) round((microtime(true) - $t0) * 1000);
    if (!$fp) {
        return ['ok' => false, 'connect_ms' => $connectMs, 'error' => trim("$errstr (errno $errno)"), 'banner' => '', 'ehlo' => []];
    }
    stream_set_timeout($fp, $timeout);
    $banner = (string) @fgets($fp, 1024);
    $bannerMs = (int) round((microtime(true) - $t0) * 1000);
    $ehlo = [];
    if ($banner !== '') {
        @fwrite($fp, "EHLO diag.localhost\r\n");
        while (($line = @fgets($fp, 1024)) !== false) {
            $ehlo[] = rtrim($line, "\r\n");
            if (!isset($line[3]) || $line[3] === ' ') { break; }
            $meta = stream_get_meta_data($fp);
            if ($meta['timed_out']) { break; }
        }
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return [
        'ok' => true, 'connect_ms' => $connectMs, 'banner_ms' => $bannerMs,
        'error' => '', 'banner' => rtrim($banner, "\r\n"), 'ehlo' => $ehlo,
    ];
}

$localPorts = array_values(array_unique(array_filter([$smtpPort, 587, 25])));
foreach ($localPorts as $port) {
    $r = smtp_probe('127.0.0.1', (int) $port);
    if (!$r['ok']) {
        check("127.0.0.1:$port", false, $r['error'] . " after {$r['connect_ms']}ms");
        continue;
    }
    if ($r['banner'] === '') {
        check("127.0.0.1:$port", false, "connected in {$r['connect_ms']}ms but sent NO banner — this transport hangs the send");
        continue;
    }
    $auth = false;
    foreach ($r['ehlo'] as $l) { if (stripos($l, 'AUTH') !== false) { $auth = true; } }
    check("127.0.0.1:$port", true, "banner in {$r['banner_ms']}ms — " . $r['banner'] . ($auth ? ' [AUTH offered]' : ' [no AUTH before STARTTLS]'));
}
check('Sendmail binary present', file_exists('/usr/sbin/sendmail') || file_exists('/usr/lib/sendmail'));

echo "\n--- Outbound delivery (why a queued message may never arrive) ---\n";
// Everything above only proves the message reached the local queue. These checks are
// about whether the local MTA can then hand it to the recipient's server.
$publicIp = gethostbyname($smtpHost);
line('Public IP of ' . $smtpHost, $publicIp === $smtpHost ? '!! could not resolve' : $publicIp);

if (filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $ptr = @gethostbyaddr($publicIp);
    $hasPtr = ($ptr && $ptr !== $publicIp);
    check('Reverse DNS (PTR) for ' . $publicIp, $hasPtr, $hasPtr
        ? $ptr
        : 'NO PTR RECORD — Gmail/Outlook reject mail from IPs without one (550-5.7.25). Ask the hosting provider to set the PTR to ' . $smtpHost);
    if ($hasPtr) {
        $fwd = gethostbyname($ptr);
        check('PTR resolves back to the same IP', $fwd === $publicIp, "$ptr -> $fwd");
    }
}

foreach (['gmail-smtp-in.l.google.com', 'alt1.gmail-smtp-in.l.google.com'] as $mx) {
    $t0 = microtime(true);
    $errno = 0; $errstr = '';
    $fp = @fsockopen('tcp://' . $mx, 25, $errno, $errstr, 8);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    if ($fp) {
        stream_set_timeout($fp, 8);
        $banner = rtrim((string) @fgets($fp, 1024), "\r\n");
        @fclose($fp);
        check("Outbound port 25 -> $mx", $banner !== '', $banner !== ''
            ? "{$ms}ms — $banner"
            : "connected in {$ms}ms but no banner (traffic is being filtered)");
    } else {
        check("Outbound port 25 -> $mx", false, trim("$errstr (errno $errno)") . " after {$ms}ms — the host or provider is blocking outbound SMTP, so queued mail can never leave this server");
    }
}

echo "\n--- Local mail queue & log ---\n";
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$canShell = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
if (!$canShell) {
    echo "shell_exec() is disabled — run these on the server over SSH instead:\n";
    echo "  mailq | tail -40                       # messages stuck in the queue\n";
    echo "  postqueue -p | tail -40\n";
    echo "  tail -100 /var/log/mail.log            # or /var/log/maillog\n";
} else {
    $mailq = @shell_exec('mailq 2>&1 | tail -40');
    echo "\$ mailq | tail -40\n" . (trim((string) $mailq) === '' ? "(no output)\n" : $mailq . "\n");

    $logFile = null;
    foreach (['/var/log/mail.log', '/var/log/maillog', '/var/log/mail.err'] as $candidate) {
        if (is_readable($candidate)) { $logFile = $candidate; break; }
    }
    if ($logFile) {
        echo "\n\$ tail -60 $logFile\n" . (string) @shell_exec('tail -60 ' . escapeshellarg($logFile) . ' 2>&1') . "\n";
    } else {
        echo "\nNo readable mail log found (tried /var/log/mail.log, /var/log/maillog, /var/log/mail.err).\n";
        echo "Try: journalctl -u postfix -n 100 --no-pager\n";
    }
}

echo "\n--- Sender reputation records for " . ($domain = substr(strrchr($login, '@') ?: '@', 1)) . " ---\n";
$txt = @dns_get_record($domain, DNS_TXT) ?: [];
$spf = '';
foreach ($txt as $rec) {
    $val = $rec['txt'] ?? '';
    if (stripos($val, 'v=spf1') === 0) { $spf = $val; }
}
check('SPF record published', $spf !== '', $spf ?: 'missing — receiving servers cannot verify this server may send for ' . $domain);
$dmarc = @dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [];
check('DMARC record published', !empty($dmarc), !empty($dmarc) ? ($dmarc[0]['txt'] ?? '') : 'missing');
$dkim = @dns_get_record('default._domainkey.' . $domain, DNS_TXT) ?: [];
check('DKIM key at default._domainkey', !empty($dkim), !empty($dkim) ? 'published' : 'not at the "default" selector (may use another selector)');

echo "\n=== End of report — delete this file from the server when done ===\n";
