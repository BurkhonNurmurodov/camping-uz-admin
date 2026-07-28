<?php
// Temporary diagnostic page for the webmail feature.
// Open /mail-diag.php as admin, read the report. Delete it from the server when done.
//
// Every step is time-bounded and the report streams as it runs: the label is printed
// BEFORE the check executes, so if something still hangs you can see exactly which
// step did it instead of getting a blank page.
require __DIR__ . '/app/bootstrap.php';
require_admin();

// Release the session lock immediately — otherwise this page freezes every other
// admin request (same bug that made the compose spinner lock the panel).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ignore_user_abort(true);
@set_time_limit(180);

// MailClient.php normally loads this; load it here too so the class checks below reflect reality.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no'); // stop nginx/proxies from buffering the stream
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level()) { @ob_end_flush(); }
@ob_implicit_flush(true);

function out(string $s): void { echo $s; @flush(); }
function line($label, $value) { out(str_pad((string) $label, 46) . ': ' . $value . "\n"); }
function check($label, $ok, $extra = '') {
    line($label, ($ok ? 'OK' : '!! PROBLEM') . ($extra !== '' ? ' — ' . $extra : ''));
    return $ok;
}
/** Prints the label, flushes, THEN runs $fn — so a hang is attributable to a named step. */
function timed(string $label, callable $fn): void {
    out(str_pad($label, 46) . ': ');
    $t0 = microtime(true);
    $result = $fn();
    out($result . sprintf('  [%.1fs]', microtime(true) - $t0) . "\n");
}

$SHELL_OK = function_exists('shell_exec')
    && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
$HAS_TIMEOUT = false;
if ($SHELL_OK) {
    $HAS_TIMEOUT = trim((string) @shell_exec('command -v timeout 2>/dev/null')) !== '';
}
/**
 * Run a shell command under a hard wall-clock cap.
 * The command is wrapped in `sh -c` so 2>&1 covers the whole pipeline — appending it
 * to the outside would only capture stderr of the LAST stage and silently lose the
 * error from the command that actually matters (e.g. `mailq | tail`).
 */
function sh(string $cmd, int $seconds = 10): ?string {
    global $SHELL_OK, $HAS_TIMEOUT;
    if (!$SHELL_OK) { return null; }
    // The brace group matters: `cmd | tail 2>&1` binds the redirect to `tail`, so the
    // error from the command you actually care about is lost. `{ ...; } 2>&1` catches it.
    $full = ($HAS_TIMEOUT ? 'timeout ' . $seconds . ' ' : '') . 'sh -c ' . escapeshellarg('{ ' . $cmd . ' ; } 2>&1');
    return @shell_exec($full);
}

out("=== Webmail diagnostics (" . date('Y-m-d H:i:s') . ") ===\n");
out("shell_exec: " . ($SHELL_OK ? 'available' : 'DISABLED') . ($SHELL_OK && !$HAS_TIMEOUT ? " (no 'timeout' binary — shell steps are unbounded)" : '') . "\n\n");

// ───────────────────────────────────────────────────────────────────────────
out("--- PHP environment ---\n");
line('PHP version', PHP_VERSION);
line('SAPI', PHP_SAPI);
line('max_execution_time', ini_get('max_execution_time') . 's');
foreach (['imap', 'mbstring', 'iconv', 'fileinfo', 'json', 'openssl'] as $ext) {
    check("Extension: $ext", extension_loaded($ext), extension_loaded($ext) ? '' : "PHP extension \"$ext\" is not enabled");
}
$vendorDir = __DIR__ . '/vendor';
check('php-imap package present', is_file($vendorDir . '/php-imap/php-imap/src/PhpImap/Mailbox.php'));
check('class_exists(PhpImap\\Mailbox)', class_exists('PhpImap\\Mailbox'));
check('class_exists(PHPMailer)', class_exists('PHPMailer\\PHPMailer\\PHPMailer'));

// ───────────────────────────────────────────────────────────────────────────
out("\n--- Mail settings in use ---\n");
$imapHost = (string) setting('mail_imap_host', getenv('IMAP_HOST') ?: 'mail.silknaviora.com');
$imapPort = (int) setting('mail_imap_port', getenv('IMAP_PORT') ?: 993);
$login    = (string) setting('mail_username', getenv('MAIL_USER') ?: 'info@silknaviora.com');
$password = (string) setting('mail_password', getenv('MAIL_PASS') ?: '');
$smtpHost = (string) setting('mail_smtp_host', getenv('SMTP_HOST') ?: 'mail.silknaviora.com');
$smtpPort = (int) setting('mail_smtp_port', getenv('SMTP_PORT') ?: 587);
line('IMAP host', $imapHost . ':' . $imapPort);
line('SMTP host', $smtpHost . ':' . $smtpPort);
line('Username', $login);
line('Password', $password === '' ? '!! EMPTY' : 'set (' . strlen($password) . ' chars)');
$attachDir = __DIR__ . '/assets/mail_attachments';
check('Attachments dir writable', is_dir($attachDir) && is_writable($attachDir), is_dir($attachDir) ? '' : $attachDir . ' is missing');

// ───────────────────────────────────────────────────────────────────────────
// THE ANSWER USUALLY LIVES HERE — printed early on purpose.
out("\n--- Mail queue & log (why a queued message never arrived) ---\n");
if (!$SHELL_OK) {
    out("shell_exec() is disabled. Run these over SSH instead — the log line for your\n");
    out("test message contains the literal reason delivery failed:\n");
    out("  mailq | tail -40\n");
    out("  grep -i 'gmail\\|status=' /var/log/mail.log | tail -40\n");
    out("  journalctl -u postfix -n 100 --no-pager\n");
} else {
    timed('postfix installed', function () {
        // NOTE: postconf -d prints *built-in defaults* — it succeeds even when Postfix
        // is stopped or misconfigured, so it only proves the package is present.
        $v = trim((string) sh('postconf -d mail_version', 8));
        return $v !== '' ? $v : 'not found in PATH';
    });
    timed('postfix service state', function () {
        $s = trim((string) sh('systemctl is-active postfix; systemctl is-enabled postfix', 8));
        return $s !== '' ? str_replace("\n", ' / ', $s) : '(systemctl unavailable)';
    });

    out("\n\$ ss -ltnp | grep -E ':(25|465|587|993|143)\\b'   <-- who is actually listening\n");
    $listen = sh("ss -ltnp | grep -E ':(25|465|587|993|143)\\b'", 8);
    if (trim((string) $listen) === '') {
        $listen = sh("netstat -ltnp | grep -E ':(25|465|587|993|143)\\b'", 8);
    }
    out((trim((string) $listen) === '' ? "(nothing returned — ss/netstat unavailable to this user)\n" : $listen) . "\n");

    out("\$ postconf -n | grep -E 'inet_interfaces|inet_protocols|myhostname|mydestination|relayhost|mynetworks'\n");
    out((string) sh("postconf -n | grep -E 'inet_interfaces|inet_protocols|myhostname|mydestination|relayhost|mynetworks'", 8) . "\n");

    out("\$ postqueue -p | tail -20\n");
    $q = sh('postqueue -p | tail -20', 10);
    out((trim((string) $q) === '' ? "(no output at all — postqueue could not run; see the error above if any)\n" : $q) . "\n");

    // `mailq`/`postqueue -p` do NOT show the maildrop queue. Mail submitted through
    // /usr/sbin/sendmail lands there first and stays invisible if the pickup daemon
    // is not running — exactly the "accepted, never delivered" case.
    out("Queue directory contents (maildrop = submitted via sendmail, not yet picked up):\n");
    foreach (['maildrop', 'incoming', 'active', 'deferred', 'corrupt'] as $qdir) {
        $path = '/var/spool/postfix/' . $qdir;
        timed('  ' . str_pad($qdir, 10), function () use ($path) {
            if (!is_dir($path)) { return 'directory not present'; }
            $n = trim((string) sh('find ' . escapeshellarg($path) . ' -type f | wc -l', 8));
            return ($n === '' ? '?' : $n) . ' file(s)' . (is_readable($path) ? '' : ' (not readable by this user)');
        });
    }

    out("\n");
    $logFile = null;
    foreach (['/var/log/mail.log', '/var/log/maillog', '/var/log/mail.err'] as $candidate) {
        if (is_readable($candidate)) { $logFile = $candidate; break; }
        if (file_exists($candidate)) {
            out("!! $candidate EXISTS but is not readable by " . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : 'the web user') . "\n");
            out("   Fix with:  sudo usermod -aG adm www-data && sudo systemctl reload apache2\n");
        }
    }
    if ($logFile) {
        out("\$ grep 'status=' $logFile | tail -25\n");
        out((string) sh('grep "status=" ' . escapeshellarg($logFile) . ' | tail -25', 10) . "\n");
    } else {
        out("No readable mail log. Run this over SSH — it names the exact failure reason:\n");
        out("  sudo grep 'status=' /var/log/mail.log | tail -30\n");
        out("  sudo journalctl -u postfix -n 100 --no-pager\n");
    }
}

// ───────────────────────────────────────────────────────────────────────────
out("\n--- Local SMTP transports (what the webmail sends through) ---\n");
// Mirrors MailClient::buildSendStrategies(). A transport that connects but returns
// no banner is exactly what used to stall the compose spinner for minutes.
// 8s, not 4: Postfix's postscreen deliberately withholds the banner for
// postscreen_greet_wait (6s by default) from clients it has not yet vouched for, so a
// shorter cap would report a healthy server as broken.
function smtp_probe(string $host, int $port, int $timeout = 8): array {
    $scheme = ($port === 465 ? 'ssl://' : 'tcp://');
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
    ]]);
    $t0 = microtime(true);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    $connectMs = (int) round((microtime(true) - $t0) * 1000);
    if (!$fp) {
        return ['ok' => false, 'connect_ms' => $connectMs, 'error' => trim("$errstr (errno $errno)"), 'banner' => '', 'auth' => false];
    }
    stream_set_timeout($fp, $timeout);
    $banner = rtrim((string) @fgets($fp, 1024), "\r\n");
    $bannerMs = (int) round((microtime(true) - $t0) * 1000);
    $auth = false;
    if ($banner !== '') {
        @fwrite($fp, "EHLO diag.localhost\r\n");
        while (($l = @fgets($fp, 1024)) !== false) {
            if (stripos($l, 'AUTH') !== false) { $auth = true; }
            if (!isset($l[3]) || $l[3] === ' ') { break; }
            $m = stream_get_meta_data($fp);
            if ($m['timed_out']) { break; }
        }
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return ['ok' => true, 'connect_ms' => $connectMs, 'banner_ms' => $bannerMs, 'error' => '', 'banner' => $banner, 'auth' => $auth];
}

foreach (array_values(array_unique(array_filter([$smtpPort, 587, 25]))) as $port) {
    timed("127.0.0.1:$port", function () use ($port) {
        $r = smtp_probe('127.0.0.1', (int) $port);
        if (!$r['ok'])            { return '!! PROBLEM — ' . $r['error']; }
        if ($r['banner'] === '')  { return '!! PROBLEM — connected in ' . $r['connect_ms'] . 'ms but NO banner (this transport hangs sends)'; }
        return 'OK — ' . $r['banner'] . ($r['auth'] ? ' [AUTH offered]' : ' [no AUTH before STARTTLS]');
    });
}
check('Sendmail binary present', file_exists('/usr/sbin/sendmail') || file_exists('/usr/lib/sendmail'));

// ───────────────────────────────────────────────────────────────────────────
out("\n--- Outbound delivery capability ---\n");
// Everything above only proves the message reached the local queue. These checks ask
// whether the local MTA can then hand it to the recipient's server.
foreach (['gmail-smtp-in.l.google.com', 'alt1.gmail-smtp-in.l.google.com'] as $mx) {
    timed("Outbound 25 -> $mx", function () use ($mx) {
        $errno = 0; $errstr = '';
        $fp = @fsockopen('tcp://' . $mx, 25, $errno, $errstr, 6);
        if (!$fp) {
            return '!! PROBLEM — ' . trim("$errstr (errno $errno)") . ' — outbound SMTP is blocked, queued mail can never leave this server';
        }
        stream_set_timeout($fp, 6);
        $banner = rtrim((string) @fgets($fp, 1024), "\r\n");
        @fclose($fp);
        return $banner !== '' ? 'OK — ' . $banner : '!! PROBLEM — connected but no banner (traffic is filtered)';
    });
}

// ───────────────────────────────────────────────────────────────────────────
out("\n--- Sender reputation records ---\n");
// gethostbyaddr()/dns_get_record() have no timeout knob and can block indefinitely
// on a slow resolver, so prefer `dig` under a hard cap and only fall back if asked.
// The SMTP host may be set to 127.0.0.1, which says nothing about the address the
// world sees us on — so fall back to the interface Apache is bound to, then to the
// public MX hostname. gethostbyname() returns its argument unchanged for IP literals.
$publicIp = '';
timed('Public IP of this server', function () use ($smtpHost, &$publicIp) {
    $candidates = [];
    if (filter_var($smtpHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $candidates[] = $smtpHost;
    }
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
    if (filter_var($serverAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $candidates[] = $serverAddr;
    }
    foreach (['mail.silknaviora.com', 'silknaviora.com'] as $name) {
        $ip = @gethostbyname($name);
        if ($ip !== $name && filter_var($ip, FILTER_VALIDATE_IP)) { $candidates[] = $ip; }
    }
    $publicIp = $candidates[0] ?? '';
    return $publicIp !== '' ? $publicIp . ' (candidates: ' . implode(', ', array_unique($candidates)) . ')' : '!! could not determine';
});

if ($publicIp !== '' && $SHELL_OK) {
    timed('Reverse DNS (PTR) for ' . $publicIp, function () use ($publicIp, $smtpHost) {
        $ptr = trim((string) sh('dig +short +time=3 +tries=1 -x ' . escapeshellarg($publicIp), 8));
        if ($ptr === '') {
            $ptr = trim((string) sh('host -W 3 ' . escapeshellarg($publicIp), 8));
            if (stripos($ptr, 'not found') !== false || stripos($ptr, 'NXDOMAIN') !== false) { $ptr = ''; }
        }
        return $ptr !== ''
            ? 'OK — ' . $ptr
            : '!! PROBLEM — NO PTR RECORD. Gmail/Outlook reject mail from IPs without one (550-5.7.25). '
              . 'Ask the hosting provider to set the PTR for ' . $publicIp . ' to ' . $smtpHost;
    });

    $domain = substr(strrchr($login, '@') ?: '@', 1) ?: 'silknaviora.com';
    foreach ([
        'SPF'   => $domain,
        'DMARC' => '_dmarc.' . $domain,
        'DKIM (default selector)' => 'default._domainkey.' . $domain,
    ] as $label => $name) {
        timed($label, function () use ($name) {
            $r = trim((string) sh('dig +short +time=3 +tries=1 TXT ' . escapeshellarg($name), 8));
            return $r !== '' ? 'OK — ' . substr(str_replace("\n", ' ', $r), 0, 120) : '!! not published';
        });
    }
} elseif ($publicIp !== '') {
    out("shell_exec disabled — check these from any machine:\n");
    out("  dig -x $publicIp        (must return a PTR; missing PTR = Gmail rejects)\n");
    out("  dig TXT " . substr(strrchr($login, '@') ?: '@', 1) . "\n");
}

// ───────────────────────────────────────────────────────────────────────────
// Slowest checks last: from the server, the public mail hostname resolves back to
// the server's own IP, and if the network does not hairpin these will crawl.
out("\n--- IMAP reachability (slow — runs last on purpose) ---\n");
out("(If the report stops inside this section, everything above it is complete.\n");
out(" ext-imap's c-client can ignore imap_timeout against a loopback 993 listener.)\n");
timed("TCP $imapHost:$imapPort", function () use ($imapHost, $imapPort) {
    $errno = 0; $errstr = '';
    $t = @fsockopen('tcp://' . $imapHost, $imapPort, $errno, $errstr, 6);
    if ($t) { fclose($t); return 'OK'; }
    return '!! PROBLEM — ' . trim("$errstr (errno $errno)");
});

// Deliberately NOT imap_open(): ext-imap's c-client ignores imap_timeout() on this
// path and blocks forever, which truncated every report. A raw IMAP conversation over
// a stream with stream_set_timeout() is bounded and proves the same thing.
timed('IMAP greeting + LOGIN', function () use ($imapHost, $imapPort, $login, $password) {
    $quote = static fn(string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    $scheme = ($imapPort === 993) ? 'ssl://' : 'tcp://';
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
    ]]);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($scheme . $imapHost . ':' . $imapPort, $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return '!! PROBLEM — ' . trim("$errstr (errno $errno)");
    }
    stream_set_timeout($fp, 6);
    $greeting = rtrim((string) @fgets($fp, 2048), "\r\n");
    if ($greeting === '') {
        @fclose($fp);
        return '!! PROBLEM — connected but no IMAP greeting within 6s';
    }
    @fwrite($fp, 'a1 LOGIN ' . $quote($login) . ' ' . $quote($password) . "\r\n");
    $tagged = '';
    while (($l = @fgets($fp, 2048)) !== false) {
        if (strncmp($l, 'a1 ', 3) === 0) { $tagged = rtrim($l, "\r\n"); break; }
        $meta = stream_get_meta_data($fp);
        if ($meta['timed_out']) { break; }
    }
    @fwrite($fp, "a2 LOGOUT\r\n");
    @fclose($fp);

    if ($tagged === '')                        { return '!! PROBLEM — greeted (' . $greeting . ') but LOGIN never answered'; }
    if (strncmp($tagged, 'a1 OK', 5) === 0)    { return 'OK — ' . $greeting; }
    return '!! PROBLEM — LOGIN rejected: ' . $tagged; // never contains the password
});

out("\n=== End of report — delete this file from the server when done ===\n");
