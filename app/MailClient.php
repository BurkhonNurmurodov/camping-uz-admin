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
        $fromEmail = filter_var($this->login, FILTER_VALIDATE_EMAIL) ? $this->login : 'info@silknaviora.com';
        
        // Strategy 1: Configured SMTP (the correct way)
        // Strategy 2: Sendmail binary fallback (bypasses network entirely)
        $strategies = [
            [
                'name' => "SMTP ({$this->smtpHost}:{$this->smtpPort})",
                'type' => 'smtp'
            ],
            [
                'name' => "Sendmail (/usr/sbin/sendmail)",
                'type' => 'sendmail'
            ]
        ];

        $errors = [];

        foreach ($strategies as $strat) {
            $mail = new PHPMailer(true);
            ob_start();
            try {
                if ($strat['type'] === 'smtp') {
                    $mail->isSMTP();
                    $mail->Host       = $this->smtpHost;
                    $mail->Port       = $this->smtpPort;
                    $mail->SMTPAuth   = !empty($this->login) && !empty($this->password);
                    if ($mail->SMTPAuth) {
                        $mail->Username = $this->login;
                        $mail->Password = $this->password;
                    }

                    // Determine encryption based on port
                    if ($this->smtpPort === 465) {
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    } elseif ($this->smtpPort === 587) {
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    } else {
                        $mail->SMTPSecure = '';
                        $mail->SMTPAutoTLS = true; // Let PHPMailer try STARTTLS if available
                    }

                    $mail->Timeout = 10;
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true,
                        ],
                    ];
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
                $mail->addAddress(trim($to));

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
                error_log("Email sent successfully via: " . $strat['name']);

                // Save a copy to the Sent folder
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

    // ─── Save Sent Copy ──────────────────────────────────────────────

    /**
     * Append the sent message to the IMAP Sent folder so it appears in "Sent".
     */
    private function saveSentCopy(PHPMailer $mail) {
        try {
            $stream = $this->getImapStream();
            $sentFolder = $this->detectSentFolder();
            $serverPath = '{' . $this->imapHost . ':' . $this->imapPort . '/imap' . $this->imapFlags . '}';
            
            // Get the full RFC822 message that was just sent
            $mail->preSend(); // Ensure headers are built
            $rawMessage = $mail->getSentMIMEMessage();
            
            @imap_append($stream, $serverPath . $sentFolder, $rawMessage, "\\Seen");
        } catch (\Throwable $e) {
            // Non-critical: log but don't fail the send
            error_log("Could not save to Sent folder: " . $e->getMessage());
        }
    }
}
