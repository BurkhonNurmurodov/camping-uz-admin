<?php

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use PhpImap\Mailbox;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailClient {
    private $mailbox;
    private $imapPath;
    private $imapHost;
    private $imapPort;
    private $imapFlags;
    private $login;
    private $password;
    private $smtpHost;
    private $smtpPort;
    private int $accountId;
    private string $fromName;
    /** "host:port" as configured, kept after a fallback overwrites the live values. */
    private string $configuredEndpoint = '';
    private ?string $connectionError = null;
    private ?string $sentFolderCache = null;
    private ?string $lastTransport = null;
    private bool $allowSendmailFallback = false;
    /** @var array<string, Mailbox> folder name => open mailbox, for this request only */
    private array $folderMailboxes = [];

    /**
     * Hard caps for the SMTP conversation. PHPMailer's $Timeout only bounds the
     * *connect* call — every read is bounded by SMTP::$Timelimit, which defaults
     * to 300s (600s during DATA). A host that accepts the TCP connection but never
     * replies therefore stalls the request for minutes. See setSmtpTimeouts().
     */
    private const SMTP_CONNECT_TIMEOUT = 5;   // seconds, per connect attempt
    private const SMTP_READ_TIMELIMIT  = 10;  // seconds, per server reply (x2 during DATA)
    private const SEND_TIME_BUDGET     = 40;  // seconds, total across all strategies

    /**
     * Settings-key prefix per mailbox slot. Slot 1 keeps the original unprefixed
     * "mail_*" keys so an existing installation keeps working untouched; every
     * further slot is namespaced ("mail2_imap_host", "mail2_username", ...).
     *
     * To offer a third mailbox, add `3 => 'mail3_'` here and a matching card on
     * the Integrations page — nothing else in this class is slot-aware.
     */
    public const ACCOUNT_PREFIXES = [1 => 'mail_', 2 => 'mail2_'];

    /**
     * Mailbox slots that are actually usable, keyed by slot id.
     *
     * A slot counts as configured once it has an address; slot 1 always qualifies
     * because it falls back to the built-in default. This is what the account
     * switcher on the Email page lists — blank out the address of slot 2 in
     * Integrations and it disappears from the UI again.
     *
     * @return array<int, array{id:int, address:string, label:string, isPrimary:bool}>
     */
    public static function accounts(): array {
        $accounts = [];
        foreach (self::ACCOUNT_PREFIXES as $id => $prefix) {
            $address = trim((string) setting($prefix . 'username', ''));
            if ($address === '' && $id === 1) {
                $address = (string) (getenv('MAIL_USER') ?: 'info@silknaviora.com');
            }
            if ($address === '') {
                continue;
            }
            $label = trim((string) setting($prefix . 'label', ''));
            $accounts[$id] = [
                'id'        => $id,
                'address'   => $address,
                'label'     => $label !== '' ? $label : $address,
                'isPrimary' => $id === 1,
            ];
        }
        return $accounts;
    }

    /** Turn a user-supplied ?account= value into a slot that really exists. */
    public static function resolveAccountId($raw): int {
        $accounts = self::accounts();
        $id = (int) $raw;
        if (isset($accounts[$id])) {
            return $id;
        }
        return (int) (array_key_first($accounts) ?? 1);
    }

    public function __construct(int $accountId = 1) {
        $this->accountId = isset(self::ACCOUNT_PREFIXES[$accountId]) ? $accountId : 1;

        // Credentials are per-account and never inherited — a blank secondary
        // username must not silently open the primary mailbox. Read first: the
        // host fallbacks below are derived from the mailbox domain.
        $this->login    = (string) $this->accountSetting('username', $this->accountId === 1 ? (getenv('MAIL_USER') ?: 'info@silknaviora.com') : '');
        $this->password = (string) $this->accountSetting('password', $this->accountId === 1 ? (getenv('MAIL_PASS') ?: 'YOUR_PASSWORD_HERE') : '');

        $this->imapHost = (string) $this->serverSetting('imap_host', getenv('IMAP_HOST') ?: 'mail.silknaviora.com');
        $this->imapPort = (int) $this->serverSetting('imap_port', getenv('IMAP_PORT') ?: 993);

        $isLoopback = in_array(strtolower(trim($this->imapHost)), ['127.0.0.1', 'localhost', '::1'], true);

        // OpenSSL 3 crashes with "SSL negotiation failed" against localhost IPs on port 993.
        if ($this->imapPort === 993 && $isLoopback) {
            $this->imapHost = $this->publicMailHost();
            $isLoopback = false;
        }

        if ($isLoopback) {
            // Loopback never leaves this machine, so encryption buys nothing — and
            // forcing STARTTLS here fails outright when the local Dovecot doesn't
            // advertise it on 143, or when its certificate can't complete a handshake
            // under OpenSSL 3 ("TLS/SSL failure for 127.0.0.1: SSL negotiation
            // failed"). /notls is explicit so imap_open cannot try to upgrade.
            $this->imapFlags = '/notls';
        } elseif ($this->imapPort === 143) {
            $this->imapFlags = '/tls/novalidate-cert';   // STARTTLS
        } elseif ($this->imapPort === 993) {
            $this->imapFlags = '/ssl/novalidate-cert';   // implicit SSL
        } else {
            $this->imapFlags = '/novalidate-cert';
        }

        $this->imapPath = '{' . $this->imapHost . ':' . $this->imapPort . '/imap' . $this->imapFlags . '}INBOX';
        $this->configuredEndpoint = $this->imapHost . ':' . $this->imapPort;
        $this->smtpHost = (string) $this->serverSetting('smtp_host', getenv('SMTP_HOST') ?: 'mail.silknaviora.com');
        $this->smtpPort = (int) $this->serverSetting('smtp_port', getenv('SMTP_PORT') ?: 587);
        $this->fromName = (string) $this->accountSetting('from_name', setting('agency_name_en', 'Silk Naviora'));
        $this->allowSendmailFallback = filter_var(
            $this->serverSetting('allow_sendmail_fallback', getenv('MAIL_ALLOW_SENDMAIL') ?: '0'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    // ─── Per-account settings ────────────────────────────────────────

    /** A setting that belongs to this mailbox alone (address, password, sender name). */
    private function accountSetting(string $key, $default = null) {
        return setting(self::ACCOUNT_PREFIXES[$this->accountId] . $key, $default);
    }

    /**
     * A server-level setting (host, port, transport behaviour).
     *
     * Secondary mailboxes usually live on the same mail server as the primary one,
     * so anything left blank falls back to the primary account's value before the
     * built-in default. Only leave these filled in when a mailbox is hosted
     * elsewhere.
     */
    private function serverSetting(string $key, $default = null) {
        $value = $this->accountSetting($key, null);
        if ($value !== null && $value !== '') {
            return $value;
        }
        if ($this->accountId !== 1) {
            $primary = setting(self::ACCOUNT_PREFIXES[1] . $key, null);
            if ($primary !== null && $primary !== '') {
                return $primary;
            }
        }
        return $default;
    }

    /**
     * A routable hostname for this mailbox's mail server, used when a loopback
     * address cannot be dialled directly. Derived from the mailbox domain so a
     * secondary account on another domain is not sent to the primary's server.
     */
    private function publicMailHost(): string {
        $domain = substr(strrchr($this->login, '@') ?: '@', 1);
        if ($domain !== '' && strpos($domain, '.') !== false) {
            return 'mail.' . $domain;
        }
        return 'mail.silknaviora.com';
    }

    /** Slot id this client is bound to. */
    public function getAccountId(): int {
        return $this->accountId;
    }

    /** The mailbox address this client reads and sends as. */
    public function getAddress(): string {
        return $this->login;
    }

    // ─── IMAP Connection (Lazy-loaded) ───────────────────────────────

    private function requireMailbox(): Mailbox {
        if ($this->mailbox instanceof Mailbox) {
            return $this->mailbox;
        }

        if (function_exists('imap_timeout')) {
            imap_timeout(IMAP_OPENTIMEOUT, 5);
            imap_timeout(IMAP_READTIMEOUT, 10);
            imap_timeout(IMAP_WRITETIMEOUT, 10);
        }
        if (!class_exists(Mailbox::class)) {
            throw new \RuntimeException('The php-imap library is not installed on this server.');
        }
        if (!\extension_loaded('imap')) {
            throw new \RuntimeException('The PHP "imap" extension is not enabled. Install it with "sudo apt install php-imap".');
        }

        $errors = [];
        foreach ($this->imapCandidates() as $i => $endpoint) {
            $path = $this->imapPathFor($endpoint, 'INBOX');
            try {
                $mailbox = new Mailbox($path, $this->login, $this->password, $this->attachmentsDir());
                // retriesNum = 1: left at the default, c-client replays a rejected
                // LOGIN three times inside a single imap_open, so one page load with
                // a stale password counts as three failures against the fail2ban
                // dovecot jail — and that ban takes SMTP down with it.
                $mailbox->setConnectionArgs(CL_EXPUNGE, 1);
                $mailbox->getImapStream(); // Mailbox is lazy — this is what actually connects.

                // Everything else (folder paths, imap_append to Sent) is built from
                // these, so the endpoint that worked becomes the one we use.
                $this->imapHost  = $endpoint['host'];
                $this->imapPort  = $endpoint['port'];
                $this->imapFlags = $endpoint['flags'];
                $this->imapPath  = $path;
                if ($i > 0) {
                    error_log("IMAP: configured endpoint failed, connected via {$endpoint['host']}:{$endpoint['port']}{$endpoint['flags']}");
                    $this->rememberEndpoint($endpoint);
                }
                return $this->mailbox = $mailbox;
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $errors[] = "{$endpoint['host']}:{$endpoint['port']}{$endpoint['flags']} -> " . $this->firstLine($message);

                // Stop the moment the server rejects the credentials. Hammering it
                // with the same wrong password across every endpoint is what trips
                // the fail2ban dovecot jail, and a ban takes SMTP down with it.
                if (!$this->isRetryableImapError($message)) {
                    $this->connectionError = $message;
                    throw new \RuntimeException('IMAP mailbox is unavailable. Details: ' . $this->firstLine($message), 0, $e);
                }
            }
        }

        $this->connectionError = implode(' | ', $errors);
        throw new \RuntimeException("IMAP mailbox is unavailable. Tried:\n" . implode("\n", array_unique($errors)));
    }

    /** Shared attachment sink for every Mailbox this client opens. */
    private function attachmentsDir(): ?string {
        $dir = __DIR__ . '/../assets/mail_attachments';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);
        return (is_dir($dir) && is_writable($dir)) ? $dir : null;
    }

    private function imapPathFor(array $endpoint, string $folder): string {
        return '{' . $endpoint['host'] . ':' . $endpoint['port'] . '/imap' . $endpoint['flags'] . '}' . $folder;
    }

    /** Encryption flags that suit a host/port pair. */
    private function flagsFor(string $host, int $port): string {
        if (in_array(strtolower(trim($host)), ['127.0.0.1', 'localhost', '::1'], true)) {
            return '/notls';
        }
        if ($port === 143)  { return '/tls/novalidate-cert'; }
        if ($port === 993)  { return '/ssl/novalidate-cert'; }
        return '/novalidate-cert';
    }

    /**
     * Endpoints to try, best first.
     *
     * The configured one always leads. The rest exist because the two ways a mail
     * server commonly refuses a connection are opposites — plaintext is rejected
     * ("Server disables LOGIN"), or the TLS handshake never completes ("SSL
     * negotiation failed") — and no single fixed choice survives both. Only
     * transport-level failures advance the list; see requireMailbox().
     *
     * @return list<array{host:string, port:int, flags:string}>
     */
    private function imapCandidates(): array {
        $candidates = [];
        $add = function (string $host, int $port, ?string $flags = null) use (&$candidates): void {
            $host = trim($host);
            if ($host === '' || $port < 1) {
                return;
            }
            $entry = ['host' => $host, 'port' => $port, 'flags' => $flags ?? $this->flagsFor($host, $port)];
            foreach ($candidates as $existing) {
                if ($existing === $entry) {
                    return;
                }
            }
            $candidates[] = $entry;
        };

        // 1. Whatever worked last time for this exact configuration, so a working
        //    fallback does not re-pay for the failing attempts on every request.
        if ($cached = $this->cachedEndpoint()) {
            $add($cached['host'], $cached['port'], $cached['flags']);
        }

        // 2. The configured endpoint.
        $add($this->imapHost, $this->imapPort, $this->imapFlags);

        // 3. Same host, the other encryption. Covers a local Dovecot that refuses
        //    plaintext, and one whose STARTTLS certificate OpenSSL 3 won't accept.
        $add($this->imapHost, $this->imapPort, $this->imapPort === 993 ? '/ssl/novalidate-cert' : '/tls/novalidate-cert');
        // Plaintext is only worth trying off 993 — that port begins the TLS
        // handshake immediately, so an unencrypted client is never understood.
        if ($this->imapPort !== 993 && strcasecmp($this->imapFlags, '/notls') !== 0) {
            $add($this->imapHost, $this->imapPort, '/notls');
        }

        // 4. The mailbox's own mail server over the public interface. When the local
        //    daemon is unreachable or locked down, this is the same server reached
        //    the way any desktop mail client reaches it.
        $public = $this->publicMailHost();
        $add($public, 993);
        $add($public, 143);

        return $candidates;
    }

    /** Transport or policy problems worth retrying on another endpoint. */
    private function isRetryableImapError(string $message): bool {
        $needles = [
            'ssl negotiation failed',
            'server disables login',
            'no recognized sasl authenticator',
            'plaintext authentication',
            // c-client refuses to send credentials over a link it considers unsafe
            // ("SECURITY PROBLEM: insecure server advertised AUTH=PLAIN"). No password
            // was offered, so this is a transport verdict — retry with encryption.
            'security problem',
            'insecure server',
            'privacyrequired',
            'certificate',
            'connection refused',
            'can\'t connect',
            'cannot connect',
            'connection failed',
            'no such host',
            'unable to connect',
            'timed out',
            'timeout',
            'connection reset',
            'temporarily unavailable',
        ];
        $message = strtolower($message);
        foreach ($needles as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * php-imap wraps imap_errors() as a JSON array, so an unmodified message reaches
     * the operator as `["Server disables LOGIN, ..."]`. Unwrap it — these strings are
     * the entire diagnostic surface for a mail problem.
     */
    private function firstLine(string $message): string {
        $decoded = json_decode($message, true);
        if (is_array($decoded)) {
            $parts = array_filter(array_map(
                static fn($p): string => trim((string) $p),
                array_unique($decoded)
            ));
            if ($parts) {
                return implode('; ', $parts);
            }
        }
        $line = trim(strtok($message, "\n") ?: $message);
        return $line !== '' ? $line : trim($message);
    }

    /** Setting key holding the last endpoint that worked for this account. */
    private function endpointCacheKey(): string {
        return self::ACCOUNT_PREFIXES[$this->accountId] . 'imap_resolved';
    }

    /**
     * The remembered endpoint, but only while it still belongs to the currently
     * configured host/port — editing those on the Integrations page must take
     * effect immediately, not lose to a stale cache.
     */
    private function cachedEndpoint(): ?array {
        $raw = (string) setting($this->endpointCacheKey(), '');
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['for'] ?? null) !== $this->configuredEndpoint) {
            return null;
        }
        if (empty($data['host']) || empty($data['port'])) {
            return null;
        }
        return ['host' => (string) $data['host'], 'port' => (int) $data['port'], 'flags' => (string) ($data['flags'] ?? '')];
    }

    private function rememberEndpoint(array $endpoint): void {
        if (!function_exists('set_setting')) {
            return;
        }
        try {
            set_setting($this->endpointCacheKey(), json_encode([
                'for'   => $this->configuredEndpoint,
                'host'  => $endpoint['host'],
                'port'  => $endpoint['port'],
                'flags' => $endpoint['flags'],
            ]));
        } catch (\Throwable $e) {
            error_log('Could not cache IMAP endpoint: ' . $e->getMessage());
        }
    }

    /**
     * A Mailbox for an arbitrary folder, reused for the rest of the request.
     *
     * Each `new Mailbox` opens a fresh authenticated IMAP session. Rendering one page
     * used to open several, and that churn is what tripped the mail server's fail2ban
     * dovecot jail — which bans the whole source IP, taking SMTP down with it.
     */
    private function folderMailbox(string $folder): Mailbox {
        $folder = $folder === '' ? 'INBOX' : $folder;
        // Resolve the working endpoint first (cached after the first call): the folder
        // path below is built from it, so opening a folder before INBOX would
        // otherwise use the configured endpoint even when only a fallback connects.
        $inbox = $this->requireMailbox();
        if (strcasecmp($folder, 'INBOX') === 0) {
            return $inbox;
        }
        if (isset($this->folderMailboxes[$folder])) {
            return $this->folderMailboxes[$folder];
        }
        return $this->folderMailboxes[$folder] = new Mailbox(
            $this->imapPathFor(
                ['host' => $this->imapHost, 'port' => $this->imapPort, 'flags' => $this->imapFlags],
                $folder
            ),
            $this->login,
            $this->password,
            $this->attachmentsDir()
        );
    }

    /**
     * Get a raw IMAP stream for operations the php-imap library doesn't cover
     * (e.g. imap_append for saving to Sent folder).
     */
    private function getImapStream() {
        $mailbox = $this->requireMailbox();
        return $mailbox->getImapStream();
    }

    // ─── Folder Discovery ────────────────────────────────────────────

    /**
     * Auto-detect the Sent folder name on the IMAP server.
     * Different servers use: Sent, "Sent Messages", "Sent Items", "INBOX.Sent", etc.
     */
    public function detectSentFolder(): string {
        if ($this->sentFolderCache !== null) {
            return $this->sentFolderCache;
        }
        return $this->sentFolderCache = $this->discoverSentFolder();
    }

    private function discoverSentFolder(): string {
        try {
            $stream = $this->getImapStream();
            $serverPath = '{' . $this->imapHost . ':' . $this->imapPort . '/imap' . $this->imapFlags . '}';
            $folders = @imap_list($stream, $serverPath, '*');
            
            if (!is_array($folders)) {
                return 'Sent'; // Default fallback
            }

            $candidates = ['Sent', 'Sent Messages', 'Sent Items', 'INBOX.Sent', 'INBOX.Sent Messages'];
            foreach ($folders as $folder) {
                $folderName = str_replace($serverPath, '', $folder);
                $folderName = mb_convert_encoding($folderName, 'UTF-8', 'UTF7-IMAP');
                foreach ($candidates as $candidate) {
                    if (strcasecmp($folderName, $candidate) === 0) {
                        return $folderName;
                    }
                }
            }
            
            // If no standard name found, look for anything containing "sent"
            foreach ($folders as $folder) {
                $folderName = str_replace($serverPath, '', $folder);
                $folderName = mb_convert_encoding($folderName, 'UTF-8', 'UTF7-IMAP');
                if (stripos($folderName, 'sent') !== false) {
                    return $folderName;
                }
            }
            
            return 'Sent'; // Ultimate fallback
        } catch (\Throwable $e) {
            return 'Sent';
        }
    }

    // ─── Telegram Group Notifications for New Emails ─────────────────

    public function notifyNewEmails(): int {
        if (!function_exists('telegram_enabled') || !telegram_enabled()) {
            return 0;
        }
        try {
            $mailbox = $this->requireMailbox();
            // Check recent messages (both read and unread) so notifications still fire even if previewed on another device/app
            $allIds = $mailbox->searchMailbox('ALL');
            if (!$allIds) {
                return 0;
            }
            rsort($allIds);
            $mailsIds = array_slice($allIds, 0, 20);
            sort($mailsIds); // Send notifications in chronological order
            
            $prefix = self::ACCOUNT_PREFIXES[$this->accountId] ?? 'mail_';
            $notifiedKey = 'notified_uids_' . trim($prefix, '_');
            $notifiedJson = setting($notifiedKey, '[]');
            $notifiedIds = is_string($notifiedJson) ? (json_decode($notifiedJson, true) ?: []) : [];
            if (!is_array($notifiedIds)) { $notifiedIds = []; }
            
            $count = 0;
            $updated = false;
            $now = time();
            
            foreach ($mailsIds as $id) {
                if (in_array((int) $id, $notifiedIds, false) || in_array((string) $id, $notifiedIds, false)) {
                    continue;
                }
                
                try {
                    $mail = $mailbox->getMail($id, false);
                    $mailTime = strtotime($mail->date ?: 'now');
                    
                    // Do not send Telegram notifications for older existing messages (>48 hours old)
                    if ($now - $mailTime > 172800) {
                        $notifiedIds[] = (int) $id;
                        $updated = true;
                        continue;
                    }
                    
                    $fromStr = trim($mail->fromName ? ($mail->fromName . ' <' . $mail->fromAddress . '>') : $mail->fromAddress);
                    $toStr   = trim($mail->toString ?: ($this->login ?: 'Admin'));
                    $subjStr = trim((string) ($mail->subject ?: '(No Subject)'));
                    $dateStr = date('F j, Y | g:i A', $mailTime);
                    
                    $snippet = mb_substr(strip_tags((string) ($mail->textPlain ?: $mail->textHtml)), 0, 300);
                    $snippet = trim(preg_replace('/\s+/', ' ', $snippet));
                    
                    $text = implode("\n", [
                        '📧 <b>New Email Received</b>',
                        '<b>From:</b> ' . tg_escape($fromStr),
                        '<b>To:</b> ' . tg_escape($toStr),
                        '<b>Subject:</b> ' . tg_escape($subjStr),
                        '<b>DateTime:</b> ' . tg_escape($dateStr),
                    ]);
                    if ($snippet !== '') {
                        $text .= "\n\n" . tg_escape($snippet . (mb_strlen(strip_tags((string) ($mail->textPlain ?: $mail->textHtml))) > 300 ? '…' : ''));
                    }
                    
                    $res = function_exists('telegram_send') ? telegram_send($text) : ['ok' => false, 'error' => 'telegram_send not found'];
                    if (!empty($res['ok'])) {
                        $notifiedIds[] = (int) $id;
                        $updated = true;
                        $count++;
                    } else {
                        error_log("Telegram group email notification failed for mail ID {$id}: " . ($res['error'] ?? 'unknown'));
                        // Fallback attempt without HTML formatting if entities failed to parse
                        if (isset($res['error']) && stripos($res['error'], 'parse entities') !== false) {
                            $plainText = strip_tags($text);
                            if (telegram_notify($plainText)) {
                                $notifiedIds[] = (int) $id;
                                $updated = true;
                                $count++;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
            
            if ($updated && function_exists('set_setting')) {
                if (count($notifiedIds) > 300) {
                    $notifiedIds = array_slice($notifiedIds, -300);
                }
                set_setting($notifiedKey, json_encode(array_values(array_unique($notifiedIds))));
            }
            
            return $count;
        } catch (\Throwable $e) {
            error_log("notifyNewEmails Error: " . $e->getMessage());
            return 0;
        }
    }

    public static function notifyAllNewEmails(): int {
        if (!function_exists('telegram_enabled') || !telegram_enabled()) {
            return 0;
        }
        $total = 0;
        foreach (array_keys(self::accounts()) as $accountId) {
            try {
                $client = new self($accountId);
                $total += $client->notifyNewEmails();
            } catch (\Throwable $e) {
                error_log("notifyAllNewEmails Error for account {$accountId}: " . $e->getMessage());
            }
        }
        return $total;
    }

    // ─── Inbox ───────────────────────────────────────────────────────

    public function getInbox($page = 1, $perPage = 20) {
        try {
            $this->notifyNewEmails();
            $mailbox = $this->requireMailbox();
            $mailsIds = $mailbox->searchMailbox('ALL');
            if (!$mailsIds) {
                return [];
            }
            rsort($mailsIds);

            $pagedIds = array_slice($mailsIds, ($page - 1) * $perPage, $perPage);
            $emails = [];
            foreach ($pagedIds as $id) {
                try {
                    $mail = $mailbox->getMail($id, false);
                    $emails[] = [
                        'id'          => $id,
                        'subject'     => $mail->subject,
                        'fromName'    => $mail->fromName,
                        'fromAddress' => $mail->fromAddress,
                        'date'        => $mail->date, // Send raw date — JS will format it
                        'isUnread'    => !$mail->isSeen,
                        'snippet'     => mb_substr(strip_tags($mail->textPlain ?: $mail->textHtml), 0, 100) . '...',
                        'hasAttachments' => !empty($mail->getAttachments())
                    ];
                } catch (\Throwable $e) {
                    continue; // Skip corrupted messages
                }
            }
            return $emails;
        } catch (\Throwable $e) {
            error_log("IMAP Error: " . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    // ─── Sent Messages ──────────────────────────────────────────────

    public function getSentMessages($page = 1, $perPage = 20) {
        try {
            $sentMailbox = $this->folderMailbox($this->detectSentFolder());

            $mailsIds = $sentMailbox->searchMailbox('ALL');
            if (!$mailsIds) {
                return [];
            }
            rsort($mailsIds);

            $pagedIds = array_slice($mailsIds, ($page - 1) * $perPage, $perPage);
            $emails = [];
            foreach ($pagedIds as $id) {
                try {
                    $mail = $sentMailbox->getMail($id, false);
                    // For sent messages, "to" is more relevant than "from"
                    $toList = $mail->to ?? [];
                    $toDisplay = !empty($toList) ? implode(', ', array_keys($toList)) : 'Unknown';
                    
                    $emails[] = [
                        'id'          => $id,
                        'subject'     => $mail->subject,
                        'fromName'    => 'To: ' . $toDisplay,
                        'fromAddress' => $toDisplay,
                        'date'        => $mail->date,
                        'isUnread'    => false,
                        'snippet'     => mb_substr(strip_tags($mail->textPlain ?: $mail->textHtml), 0, 100) . '...',
                        'hasAttachments' => false,
                        'isSent'      => true
                    ];
                } catch (\Throwable $e) {
                    continue;
                }
            }
            return $emails;
        } catch (\Throwable $e) {
            error_log("Sent folder error: " . $e->getMessage());
            return ['error' => 'Could not load Sent folder: ' . $e->getMessage()];
        }
    }

    // ─── Read Single Message ─────────────────────────────────────────

    public function getMessage($id, $folder = 'INBOX') {
        try {
            $box = $this->folderMailbox($folder);
            $mail = $box->getMail($id, true);
            $box->markMailAsRead($id);

            $formattedAttachments = [];
            $rawAttachments = $mail->getAttachments();
            if (is_array($rawAttachments)) {
                foreach ($rawAttachments as $att) {
                    $filePath = $att->filePath ?: '';
                    $fileName = $att->name ?: ('attachment_' . ($att->id ?: uniqid()));
                    
                    $storedName = '';
                    if (!empty($filePath) && file_exists($filePath)) {
                        $storedName = basename($filePath);
                    } else {
                        $standardPath = __DIR__ . '/../assets/mail_attachments/' . $fileName;
                        if (file_exists($standardPath)) {
                            $storedName = basename($standardPath);
                        }
                    }
                    
                    $mime = $att->mimeType ?? ($att->mime ?? 'application/octet-stream');
                    $url = '';
                    if (!empty($storedName)) {
                        $url = 'email.php?action=download_attachment&file=' . urlencode($storedName) . '&name=' . urlencode($fileName) . '&mime=' . urlencode($mime);
                    }
                    
                    $formattedAttachments[] = [
                        'id'   => $att->id ?? '',
                        'name' => $fileName,
                        'size' => $att->sizeInBytes ?? ((!empty($filePath) && file_exists($filePath)) ? filesize($filePath) : 0),
                        'url'  => $url,
                        'mime' => $mime
                    ];
                }
            }

            $rendered = $this->renderBody($mail->textHtml, $mail->textPlain);

            return [
                'id'          => $id,
                'subject'     => $mail->subject,
                'fromName'    => $mail->fromName,
                'fromAddress' => $mail->fromAddress,
                'date'        => date('F j, Y | g:i A', strtotime($mail->date)),
                'body'        => $rendered['body'],
                'quoted'      => $rendered['quoted'],
                'bodyPlain'   => $mail->textPlain ?: strip_tags($mail->textHtml),
                'attachments' => $formattedAttachments
            ];
        } catch (\Throwable $e) {
            error_log("IMAP Error: " . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    // ─── Conversation Threading ──────────────────────────────────────

    /**
     * Every message belonging to the same conversation as $id, oldest first, with
     * received and sent messages interleaved.
     *
     * Messages are matched on the RFC 5322 reference chain (Message-ID / In-Reply-To /
     * References) and, as a fallback for clients that omit those, on a normalised
     * subject. Overviews are fetched in one call per folder rather than opening each
     * message, so building the chain costs two round trips regardless of mailbox size.
     */
    public function getThread($id, string $folder = 'INBOX', int $limit = 25): array {
        $sentFolder = $this->detectSentFolder();
        $folders = array_values(array_unique([$folder, 'INBOX', $sentFolder]));

        // 1. Collect lightweight overviews from every folder we care about.
        $overviews = [];
        foreach ($folders as $name) {
            try {
                $box = $this->folderMailbox($name);
                $ids = $box->searchMailbox('ALL');
                if (!$ids) { continue; }
                foreach ($box->getMailsInfo($ids) as $info) {
                    $overviews[] = [
                        'id'        => $info->uid ?? ($info->msgno ?? null),
                        'folder'    => $name,
                        'isSent'    => strcasecmp($name, $sentFolder) === 0,
                        'subject'   => (string) ($info->subject ?? ''),
                        'messageId' => $this->normaliseMessageId((string) ($info->message_id ?? '')),
                        'refs'      => $this->extractMessageIds(
                            ((string) ($info->references ?? '')) . ' ' . ((string) ($info->in_reply_to ?? ''))
                        ),
                        'udate'     => (int) ($info->udate ?? 0),
                    ];
                }
            } catch (\Throwable $e) {
                error_log("Thread: could not read folder {$name}: " . $e->getMessage());
            }
        }

        // 2. Locate the message that was clicked.
        $anchor = null;
        foreach ($overviews as $o) {
            if ((string) $o['id'] === (string) $id && $o['folder'] === $folder) { $anchor = $o; break; }
        }
        if ($anchor === null) {
            // Fall back to the single message rather than showing nothing.
            return [$this->getMessage($id, $folder) + ['isSent' => strcasecmp($folder, $sentFolder) === 0]];
        }

        // 3. Grow the id set until it stops changing — a reply may reference a message
        //    we only learn about on a later pass.
        $ids = array_filter(array_merge([$anchor['messageId']], $anchor['refs']));
        $ids = array_values(array_unique($ids));
        $subjectKey = $this->normaliseSubject($anchor['subject']);

        $members = [];
        do {
            $grew = false;
            foreach ($overviews as $k => $o) {
                if (isset($members[$k])) { continue; }
                $linked = ($o['messageId'] !== '' && in_array($o['messageId'], $ids, true))
                    || array_intersect($o['refs'], $ids)
                    || ($subjectKey !== '' && $this->normaliseSubject($o['subject']) === $subjectKey);
                if ($linked) {
                    $members[$k] = $o;
                    foreach (array_merge([$o['messageId']], $o['refs']) as $mid) {
                        if ($mid !== '' && !in_array($mid, $ids, true)) { $ids[] = $mid; }
                    }
                    $grew = true;
                }
            }
        } while ($grew);

        // 4. Oldest first, then load the bodies.
        $members = array_values($members);
        usort($members, static fn($a, $b) => $a['udate'] <=> $b['udate']);
        if (count($members) > $limit) {
            $members = array_slice($members, -$limit);
        }

        $thread = [];
        foreach ($members as $m) {
            try {
                $message = $this->getMessage($m['id'], $m['folder']);
                $message['isSent'] = $m['isSent'];
                $message['folder'] = $m['folder'];
                $message['isAnchor'] = ((string) $m['id'] === (string) $id && $m['folder'] === $folder);
                $thread[] = $message;
            } catch (\Throwable $e) {
                continue; // skip anything unreadable rather than failing the whole thread
            }
        }
        return $thread;
    }

    /** "<abc@host>" => "abc@host"; tolerates surrounding whitespace and missing brackets. */
    private function normaliseMessageId(string $raw): string {
        return strtolower(trim($raw, " \t\n\r<>"));
    }

    /** Pull every <...> message-id out of a References / In-Reply-To header. */
    private function extractMessageIds(string $header): array {
        if (!preg_match_all('/<([^<>]+)>/', $header, $m)) {
            return [];
        }
        return array_values(array_unique(array_map('strtolower', array_map('trim', $m[1]))));
    }

    /** Strip any run of Re:/Fwd:/Fw:/RE[2]: prefixes so replies group with the original. */
    private function normaliseSubject(string $subject): string {
        $s = trim($subject);
        do {
            $before = $s;
            $s = preg_replace('/^\s*(re|aw|fwd?|fw|sv|vs|antw|ответ|отв|пересылка|javob)\s*(\[\d+\])?\s*:\s*/iu', '', $s) ?? $s;
        } while ($s !== $before);
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    // ─── Body Rendering ──────────────────────────────────────────────

    /**
     * Turn a message into display HTML, split into the new content and the quoted
     * history beneath it.
     *
     * @return array{body: string, quoted: string}
     */
    public function renderBody(?string $html, ?string $plain): array {
        $html  = (string) $html;
        $plain = (string) $plain;

        if (trim($html) !== '') {
            return $this->splitQuotedHtml($this->sanitizeHtml($html));
        }
        return $this->splitQuotedPlain($plain);
    }

    /**
     * Strip anything executable out of message HTML.
     *
     * Mail bodies are attacker-controlled and the reader injects them with innerHTML,
     * so an unsanitised message could run script in the logged-in admin's session.
     */
    private function sanitizeHtml(string $html): string {
        if (!class_exists(\DOMDocument::class)) {
            // No DOM extension — fall back to showing it as text rather than as markup.
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The meta charset keeps DOMDocument from mangling UTF-8 (Cyrillic subjects/bodies).
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);

        // Drop entire elements that can execute or phone home.
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'form', 'input', 'button'] as $tag) {
            $nodes = iterator_to_array($doc->getElementsByTagName($tag));
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach ($xpath->query('//*[@*]') ?: [] as $el) {
            if (!$el instanceof \DOMElement) { continue; }
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name  = strtolower($attr->name);
                $value = trim($attr->value);
                // on* handlers, and any URL scheme other than http/https/mailto/cid/data:image
                if (str_starts_with($name, 'on')) {
                    $el->removeAttribute($attr->name);
                    continue;
                }
                if (in_array($name, ['href', 'src', 'action', 'background', 'formaction'], true)
                    && preg_match('#^\s*(javascript|vbscript|file)\s*:#i', $value)) {
                    $el->removeAttribute($attr->name);
                    continue;
                }
                if ($name === 'src' && preg_match('#^\s*data\s*:#i', $value) && !preg_match('#^\s*data\s*:\s*image/#i', $value)) {
                    $el->removeAttribute($attr->name);
                }
            }
            // External links should not carry the admin session's referrer.
            if (strtolower($el->tagName) === 'a' && $el->hasAttribute('href')) {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /**
     * Pull <blockquote> / Gmail's quote container off the end of an HTML body.
     *
     * @return array{body: string, quoted: string}
     */
    private function splitQuotedHtml(string $html): array {
        if (trim($html) === '' || !class_exists(\DOMDocument::class)) {
            return ['body' => $html, 'quoted' => ''];
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        $quotes = $xpath->query(
            '//blockquote | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote_container ")]'
            . ' | //div[@id="appendonsend"]'
        );
        if (!$quotes || $quotes->length === 0) {
            return ['body' => $html, 'quoted' => ''];
        }

        $quoted = '';
        foreach (iterator_to_array($quotes) as $node) {
            if (!$node->parentNode) { continue; } // already removed as part of an outer quote
            $quoted .= $doc->saveHTML($node);
            $node->parentNode->removeChild($node);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $rest = '';
        if ($body) {
            foreach ($body->childNodes as $child) {
                $rest .= $doc->saveHTML($child);
            }
        }
        return ['body' => trim($rest), 'quoted' => trim($quoted)];
    }

    /**
     * Split a plain-text body at the start of the quoted reply chain.
     *
     * The text is escaped before any markup is added — without that, content that
     * merely looks like a tag (`<Screenshot 2026-07-28 at 19.03.12.png>`, which is how
     * Apple Mail names inline attachments) is swallowed by the browser's parser.
     *
     * @return array{body: string, quoted: string}
     */
    private function splitQuotedPlain(string $plain): array {
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        $lines = explode("\n", $plain);

        // "On <date>, <someone> wrote:", Outlook's separator, or the first ">" line.
        $cut = null;
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '') { continue; }
            if (preg_match('/^On\b.{0,200}\bwrote:\s*$/isu', $t)
                || preg_match('/^-{2,}\s*(Original Message|Forwarded message)\s*-{2,}/iu', $t)
                || preg_match('/^_{5,}$/u', $t)
                || str_starts_with($t, '>')) {
                $cut = $i;
                break;
            }
        }

        $esc = static fn(string $s): string => nl2br(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        if ($cut === null) {
            return ['body' => $esc(rtrim($plain)), 'quoted' => ''];
        }
        $new    = rtrim(implode("\n", array_slice($lines, 0, $cut)));
        $quoted = trim(implode("\n", array_slice($lines, $cut)));

        return ['body' => $esc($new), 'quoted' => $esc($quoted)];
    }

    // ─── Delete Message ──────────────────────────────────────────────

    public function deleteMessage($id) {
        try {
            $mailbox = $this->requireMailbox();
            $mailbox->deleteMail($id);
            return true;
        } catch (\Throwable $e) {
            error_log("IMAP Error: " . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    // ─── Mark as Unread ──────────────────────────────────────────────

    public function markAsUnread($id) {
        try {
            $mailbox = $this->requireMailbox();
            $mailbox->markMailAsUnread($id);
            return true;
        } catch (\Throwable $e) {
            error_log("IMAP Error: " . $e->getMessage());
            return false;
        }
    }

    // ─── Send Message ────────────────────────────────────────────────

    /**
     * Send an email with optional CC, BCC, and file attachments.
     *
     * @param string   $to          Recipient email
     * @param string   $subject     Email subject
     * @param string   $body        Email body (HTML)
     * @param string   $cc          Comma-separated CC addresses
     * @param string   $bcc         Comma-separated BCC addresses
     * @param array    $attachments Array of uploaded file arrays ($_FILES format)
     * @return bool
     */
    public function sendMessage($to, $subject, $body, $cc = '', $bcc = '', $attachments = []) {
        $this->lastTransport = null;

        $to = trim((string) $to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            // Validate before the strategy loop — otherwise an unusable address is
            // retried against every transport and reported as a connection failure.
            throw new \InvalidArgumentException('"' . $to . '" is not a valid recipient address.');
        }

        if (!filter_var($this->login, FILTER_VALIDATE_EMAIL)) {
            // Only reachable for a half-configured secondary mailbox. Sending as the
            // primary address instead would put the wrong sender on the message.
            throw new \RuntimeException('Mail account #' . $this->accountId . ' has no valid address configured. Set it on the Integrations page.');
        }
        $fromEmail = $this->login;
        $hasAuth = !empty($this->login) && !empty($this->password);

        $strategies = $this->buildSendStrategies($hasAuth);
        $errors  = [];
        $started = microtime(true);

        foreach ($strategies as $strat) {
            $remaining = self::SEND_TIME_BUDGET - (microtime(true) - $started);
            if ($remaining < self::SMTP_CONNECT_TIMEOUT) {
                $errors[] = "{$strat['name']} -> skipped (send time budget of " . self::SEND_TIME_BUDGET . "s exhausted)";
                continue;
            }

            $mail = new PHPMailer(true);
            ob_start();
            try {
                if ($strat['type'] === 'smtp') {
                    $mail->isSMTP();
                    $mail->Host       = $strat['host'];
                    $mail->Port       = $strat['port'];
                    $mail->SMTPAuth   = $strat['auth'];
                    if ($strat['auth']) {
                        $mail->Username = $this->login;
                        $mail->Password = $this->password;
                    }
                    $mail->SMTPSecure  = $strat['secure'];
                    $mail->SMTPAutoTLS = $strat['autotls'];
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true,
                        ],
                    ];
                    $this->setSmtpTimeouts($mail, $remaining);
                } elseif ($strat['type'] === 'sendmail') {
                    if (!file_exists('/usr/sbin/sendmail') && !file_exists('/usr/lib/sendmail')) {
                        ob_end_clean();
                        $errors[] = "Sendmail -> binary not found on server";
                        continue;
                    }
                    $mail->isSendmail();
                }

                // From
                // EHLO name. Left unset, PHPMailer falls back to $_SERVER['SERVER_NAME']
                // or "localhost.localdomain" — and the mail server enforces
                // reject_invalid_helo_hostname / reject_non_fqdn_helo_hostname, so an
                // unqualified greeting gets the message refused.
                $mail->Hostname = $this->heloHostname();

                $mail->setFrom($fromEmail, $this->fromName !== '' ? $this->fromName : 'Silk Naviora');
                $mail->Sender = $fromEmail;

                // To
                $mail->addAddress($to);

                // CC
                if (!empty($cc)) {
                    foreach (array_filter(array_map('trim', explode(',', $cc))) as $ccAddr) {
                        if (filter_var($ccAddr, FILTER_VALIDATE_EMAIL)) {
                            $mail->addCC($ccAddr);
                        }
                    }
                }

                // BCC
                if (!empty($bcc)) {
                    foreach (array_filter(array_map('trim', explode(',', $bcc))) as $bccAddr) {
                        if (filter_var($bccAddr, FILTER_VALIDATE_EMAIL)) {
                            $mail->addBCC($bccAddr);
                        }
                    }
                }

                // Attachments
                if (!empty($attachments) && isset($attachments['name'])) {
                    $count = is_array($attachments['name']) ? count($attachments['name']) : 1;
                    for ($i = 0; $i < $count; $i++) {
                        $name = is_array($attachments['name']) ? $attachments['name'][$i] : $attachments['name'];
                        $tmpName = is_array($attachments['tmp_name']) ? $attachments['tmp_name'][$i] : $attachments['tmp_name'];
                        $error = is_array($attachments['error']) ? $attachments['error'][$i] : $attachments['error'];

                        if ($error === UPLOAD_ERR_OK && !empty($tmpName) && is_uploaded_file($tmpName)) {
                            $mail->addAttachment($tmpName, $name);
                        }
                    }
                }

                // Content
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->AltBody = strip_tags($body);

                $mail->send();
                while (ob_get_level()) { ob_end_clean(); }

                $this->lastTransport = $strat['name'];
                $elapsed = round(microtime(true) - $started, 2);
                error_log("Mail accepted for delivery via [{$strat['name']}] in {$elapsed}s (from: {$fromEmail}, to: {$to})");

                // Save a copy to the Sent folder (non-critical — never fail the send for this)
                $this->saveSentCopy($mail);

                return true;
            } catch (\Throwable $e) {
                while (ob_get_level()) { ob_end_clean(); }
                $err = $mail->ErrorInfo ?: $e->getMessage();
                error_log("Strategy [{$strat['name']}] failed: {$err}");
                $errors[] = "{$strat['name']} -> {$err}";
            }
        }

        throw new \RuntimeException("Failed to send email:\n" . implode("\n", array_unique($errors)));
    }

    /**
     * Name of the transport that accepted the last message ("Localhost submission
     * (localhost:587)", "Sendmail", ...). Null when nothing has been sent yet.
     */
    public function getLastTransport(): ?string {
        return $this->lastTransport;
    }

    /**
     * PHPMailer's $Timeout only bounds the connect() call. Every *reply* read is
     * bounded by SMTP::$Timelimit, which PHPMailer leaves at 300s (and doubles to
     * 600s while sending DATA). A host that completes the TCP handshake but never
     * sends a banner — the classic hairpin-NAT / dropped-packet case when the
     * server dials its own public IP — therefore blocks the whole HTTP request for
     * minutes. Pin both values so a dead transport fails in seconds.
     */
    private function setSmtpTimeouts(PHPMailer $mail, float $remainingBudget): void {
        $connect = (int) max(2, min(self::SMTP_CONNECT_TIMEOUT, floor($remainingBudget)));
        $mail->Timeout = $connect;

        $smtp = $mail->getSMTPInstance(); // smtpConnect() reuses this instance
        $smtp->Timeout   = $connect;
        $smtp->Timelimit = (int) max(4, min(self::SMTP_READ_TIMELIMIT, floor($remainingBudget)));
    }

    /**
     * A fully-qualified name to greet the mail server with. Prefers the domain of the
     * mailbox we authenticate as, since that is the name the server's certificate and
     * SPF record are built around.
     */
    private function heloHostname(): string {
        $domain = substr(strrchr($this->login, '@') ?: '@', 1);
        if ($domain !== '' && strpos($domain, '.') !== false) {
            return 'mail.' . $domain;
        }
        if (!in_array(strtolower(trim($this->smtpHost)), ['127.0.0.1', 'localhost', '::1', ''], true)
            && strpos($this->smtpHost, '.') !== false
            && !filter_var($this->smtpHost, FILTER_VALIDATE_IP)) {
            return $this->smtpHost;
        }
        return 'mail.silknaviora.com';
    }

    /**
     * True when the configured SMTP host is this same machine — which is the case for
     * mail.silknaviora.com, since it resolves back to the server's own public IP.
     * Unresolvable hosts count as external so a typo fails loudly instead of quietly
     * falling through to the local queue.
     */
    private function smtpHostIsThisServer(): bool {
        $host = strtolower(trim($this->smtpHost));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        $ip = @gethostbyname($this->smtpHost);
        if ($ip === $this->smtpHost) {
            return false; // did not resolve
        }
        $ownAddresses = array_filter([
            $_SERVER['SERVER_ADDR'] ?? null,
            @gethostbyname(@gethostname() ?: 'localhost'),
        ]);
        return in_array($ip, $ownAddresses, true);
    }

    /**
     * Ordered list of delivery transports.
     *
     * Local transports come first and the configured public hostname comes last:
     * from the server itself, mail.silknaviora.com resolves back to the server's own
     * public IP, and if the network does not hairpin, that connection hangs instead
     * of failing. It is the slowest possible path to the same Postfix instance, so
     * it is only worth trying once everything local has failed.
     */
    private function buildSendStrategies(bool $hasAuth): array {
        // If the operator configured a genuinely external relay, that is a deliberate
        // choice to stop delivering direct-to-MX from this box. Use it exclusively —
        // falling back to the local queue would silently undo it and reproduce the
        // "reported as sent, never arrives" behaviour the relay was meant to fix.
        if ($hasAuth && !$this->smtpHostIsThisServer()) {
            return [[
                'name'    => "Relay ({$this->smtpHost}:{$this->smtpPort})",
                'type'    => 'smtp',
                'host'    => $this->smtpHost,
                'port'    => $this->smtpPort,
                'auth'    => true,
                'secure'  => ($this->smtpPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : ($this->smtpPort === 587 ? PHPMailer::ENCRYPTION_STARTTLS : '')),
                'autotls' => true,
            ]];
        }

        $strategies = [];
        $seen = [];

        $addLocal = function (int $port, bool $auth) use (&$strategies, &$seen, $hasAuth) {
            if ($port < 1 || isset($seen[$port])) {
                return;
            }
            $seen[$port] = true;
            $auth = $auth && $hasAuth;
            $strategies[] = [
                'name'    => ($auth ? "Localhost submission (localhost:{$port})" : "Localhost relay (localhost:{$port})"),
                'type'    => 'smtp',
                'host'    => '127.0.0.1', // not "localhost": that may resolve to ::1 on an IPv4-only listener
                'port'    => $port,
                'auth'    => $auth,
                'secure'  => ($port === 465 ? PHPMailer::ENCRYPTION_SMTPS : ''),
                'autotls' => ($port !== 465),
            ];
        };

        // 1. Configured port on the local machine, then the standard submission port,
        //    then the unauthenticated local relay Postfix accepts from mynetworks.
        $addLocal($this->smtpPort, true);
        $addLocal(587, true);
        $addLocal(25, false);

        // 2. The configured public SMTP host. On this server that is the same Postfix
        //    reached over the public interface — which matters, because Postfix can be
        //    bound to the public address and not to loopback, making every strategy
        //    above fail while this one works.
        if (!in_array(strtolower(trim($this->smtpHost)), ['127.0.0.1', 'localhost', '::1', ''], true)) {
            $strategies[] = [
                'name'    => "SMTP ({$this->smtpHost}:{$this->smtpPort})",
                'type'    => 'smtp',
                'host'    => $this->smtpHost,
                'port'    => $this->smtpPort,
                'auth'    => $hasAuth,
                'secure'  => ($this->smtpPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : ($this->smtpPort === 587 ? PHPMailer::ENCRYPTION_STARTTLS : '')),
                'autotls' => true,
            ];
        }

        // 3. Sendmail, opt-in only, and always last.
        //    It hands the message to the LOCAL queue and reports success the moment the
        //    binary exits 0 — there is no acknowledgement to check. On a host where the
        //    local MTA is stopped (mail actually served from a container, say) that is a
        //    black hole: messages pile up unseen in /var/spool/postfix/maildrop while the
        //    UI reports "sent". A visible failure is worth more than a fake success, so
        //    this is only used when someone deliberately enables it.
        if ($this->allowSendmailFallback) {
            $strategies[] = [
                'name' => 'Sendmail (/usr/sbin/sendmail)',
                'type' => 'sendmail',
            ];
        }

        return $strategies;
    }

    // ─── Save Sent Copy ──────────────────────────────────────────────

    /**
     * Append the sent message to the IMAP Sent folder so it appears in "Sent".
     */
    private function saveSentCopy(PHPMailer $mail) {
        try {
            $stream = $this->getImapStream();
            $sentFolder = $this->detectSentFolder();
            $serverPath = '{' . $this->imapHost . ':' . $this->imapPort . '/imap' . $this->imapFlags . '}';
            
            // getSentMIMEMessage() returns the full RFC822 message from the last send() call
            $rawMessage = $mail->getSentMIMEMessage();
            
            if (!empty($rawMessage)) {
                @imap_append($stream, $serverPath . $sentFolder, $rawMessage, "\\Seen");
            }
        } catch (\Throwable $e) {
            // Non-critical: log but don't fail the send
            error_log("Could not save to Sent folder: " . $e->getMessage());
        }
    }
}

