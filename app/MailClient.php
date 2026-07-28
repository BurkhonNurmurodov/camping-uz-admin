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
    private ?string $connectionError = null;
    private ?string $sentFolderCache = null;
    private ?string $lastTransport = null;

    /**
     * Hard caps for the SMTP conversation. PHPMailer's $Timeout only bounds the
     * *connect* call — every read is bounded by SMTP::$Timelimit, which defaults
     * to 300s (600s during DATA). A host that accepts the TCP connection but never
     * replies therefore stalls the request for minutes. See setSmtpTimeouts().
     */
    private const SMTP_CONNECT_TIMEOUT = 5;   // seconds, per connect attempt
    private const SMTP_READ_TIMELIMIT  = 10;  // seconds, per server reply (x2 during DATA)
    private const SEND_TIME_BUDGET     = 40;  // seconds, total across all strategies

    public function __construct() {
        $this->imapHost = (string) setting('mail_imap_host', getenv('IMAP_HOST') ?: 'mail.silknaviora.com');
        $this->imapPort = (int) setting('mail_imap_port', getenv('IMAP_PORT') ?: 993);
        
        // OpenSSL 3 crashes with "SSL negotiation failed" against localhost IPs on port 993.
        if ($this->imapPort === 993 && in_array(strtolower(trim($this->imapHost)), ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->imapHost = 'mail.silknaviora.com';
        }

        // Port 143 = STARTTLS, port 993 = implicit SSL
        if ($this->imapPort === 143) {
            $this->imapFlags = '/tls/novalidate-cert';
        } elseif ($this->imapPort === 993) {
            $this->imapFlags = '/ssl/novalidate-cert';
        } else {
            $this->imapFlags = '/novalidate-cert';
        }

        $this->imapPath = '{' . $this->imapHost . ':' . $this->imapPort . '/imap' . $this->imapFlags . '}INBOX';
        $this->login    = (string) setting('mail_username', getenv('MAIL_USER') ?: 'info@silknaviora.com');
        $this->password = (string) setting('mail_password', getenv('MAIL_PASS') ?: 'YOUR_PASSWORD_HERE');
        $this->smtpHost = (string) setting('mail_smtp_host', getenv('SMTP_HOST') ?: 'mail.silknaviora.com');
        $this->smtpPort = (int) setting('mail_smtp_port', getenv('SMTP_PORT') ?: 587);
    }

    // ─── IMAP Connection (Lazy-loaded) ───────────────────────────────

    private function requireMailbox(): Mailbox {
        if (!$this->mailbox instanceof Mailbox) {
            try {
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
                $attachmentsDir = __DIR__ . '/../assets/mail_attachments';
                if (!is_dir($attachmentsDir)) {
                    @mkdir($attachmentsDir, 0777, true);
                }
                @chmod($attachmentsDir, 0777);
                $this->mailbox = new Mailbox(
                    $this->imapPath,
                    $this->login,
                    $this->password,
                    (is_dir($attachmentsDir) && is_writable($attachmentsDir)) ? $attachmentsDir : null
                );
                $this->mailbox->setConnectionArgs(CL_EXPUNGE);
            } catch (\Throwable $e) {
                $this->connectionError = $e->getMessage();
                throw new \RuntimeException('IMAP mailbox is unavailable. Details: ' . $this->connectionError, 0, $e);
            }
        }
        return $this->mailbox;
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

    // ─── Inbox ───────────────────────────────────────────────────────

    public function getInbox($page = 1, $perPage = 20) {
        try {
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
                        'isUnread'    => $mail->isUnseen,
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
            $sentFolder = $this->detectSentFolder();
            $serverPath = '{' . $this->imapHost . ':' . $this->imapPort . '/imap' . $this->imapFlags . '}';
            $sentPath = $serverPath . $sentFolder;

            $attachmentsDir = __DIR__ . '/../assets/mail_attachments';
            $sentMailbox = new Mailbox(
                $sentPath,
                $this->login,
                $this->password,
                (is_dir($attachmentsDir) && is_writable($attachmentsDir)) ? $attachmentsDir : null
            );

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
            if ($folder !== 'INBOX') {
                // Open a different folder for reading
                $serverPath = '{' . $this->imapHost . ':' . $this->imapPort . '/imap' . $this->imapFlags . '}';
                $attachmentsDir = __DIR__ . '/../assets/mail_attachments';
                $folderMailbox = new Mailbox(
                    $serverPath . $folder,
                    $this->login,
                    $this->password,
                    (is_dir($attachmentsDir) && is_writable($attachmentsDir)) ? $attachmentsDir : null
                );
                $mail = $folderMailbox->getMail($id);
            } else {
                $mailbox = $this->requireMailbox();
                $mail = $mailbox->getMail($id);
                $mailbox->markMailAsRead($id);
            }

            $formattedAttachments = [];
            $rawAttachments = $mail->getAttachments();
            if (is_array($rawAttachments)) {
                foreach ($rawAttachments as $att) {
                    $filePath = $att->filePath ?: '';
                    $fileName = $att->name ?: ('attachment_' . ($att->id ?: uniqid()));
                    
                    $url = '';
                    if (!empty($filePath) && file_exists($filePath)) {
                        if (($pos = strpos($filePath, 'assets/mail_attachments/')) !== false) {
                            $url = substr($filePath, $pos);
                        } else {
                            $url = 'assets/mail_attachments/' . basename($filePath);
                        }
                    } else {
                        $standardPath = __DIR__ . '/../assets/mail_attachments/' . $fileName;
                        if (file_exists($standardPath)) {
                            $url = 'assets/mail_attachments/' . $fileName;
                        }
                    }
                    
                    $formattedAttachments[] = [
                        'id'   => $att->id ?? '',
                        'name' => $fileName,
                        'size' => $att->sizeInBytes ?? ((!empty($filePath) && file_exists($filePath)) ? filesize($filePath) : 0),
                        'url'  => $url,
                        'mime' => $att->mimeType ?? ($att->mime ?? 'application/octet-stream')
                    ];
                }
            }

            return [
                'id'          => $id,
                'subject'     => $mail->subject,
                'fromName'    => $mail->fromName,
                'fromAddress' => $mail->fromAddress,
                'date'        => date('F j, Y | g:i A', strtotime($mail->date)),
                'body'        => $mail->textHtml ?: nl2br($mail->textPlain),
                'bodyPlain'   => $mail->textPlain ?: strip_tags($mail->textHtml),
                'attachments' => $formattedAttachments
            ];
        } catch (\Throwable $e) {
            error_log("IMAP Error: " . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
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

        $fromEmail = filter_var($this->login, FILTER_VALIDATE_EMAIL) ? $this->login : 'info@silknaviora.com';
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
                $mail->setFrom($fromEmail, 'Silk Naviora');
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
                error_log("Mail accepted for delivery via [{$strat['name']}] in {$elapsed}s (to: {$to})");

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

        // 3. Sendmail LAST. It hands the message to the local queue and reports success
        //    as soon as the binary exits 0 — it cannot fail in a way we can detect, so
        //    placing it earlier would shadow every real SMTP transport behind it and
        //    turn an undeliverable message into a "sent successfully".
        $strategies[] = [
            'name' => 'Sendmail (/usr/sbin/sendmail)',
            'type' => 'sendmail',
        ];

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

