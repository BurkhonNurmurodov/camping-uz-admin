<?php

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use PhpImap\Mailbox;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailClient {
    private $mailbox;
    private $imapPath;
    private $login;
    private $password;
    private $smtpHost;
    private $smtpPort;
    private ?string $connectionError = null;

    public function __construct() {
        // Fallback host per the domain's MX record — silknaviora.uz has no DNS records.
        $imapHost = (string) setting('mail_imap_host', getenv('IMAP_HOST') ?: 'mail.silknaviora.com');
        $imapPort = (int) setting('mail_imap_port', getenv('IMAP_PORT') ?: 993);
        
        // OpenSSL 3 on modern Ubuntu crashes with "SSL negotiation failed" when attempting SSL (port 993) directly against an IP address like 127.0.0.1.
        // Automatically translate localhost IPs to the valid certificate domain hostname when port 993 is used.
        if ($imapPort === 993 && in_array(strtolower(trim($imapHost)), ['127.0.0.1', 'localhost', '::1'], true)) {
            $imapHost = 'mail.silknaviora.com';
        }

        // Port 143 uses STARTTLS (/tls) because modern Dovecot disables plain /notls logins; port 993 uses direct SSL (/ssl)
        if ($imapPort === 143) {
            $flags = '/tls/novalidate-cert';
        } elseif ($imapPort === 993) {
            $flags = '/ssl/novalidate-cert';
        } else {
            $flags = '/novalidate-cert';
        }

        $this->imapPath = '{' . $imapHost . ':' . $imapPort . '/imap' . $flags . '}INBOX';
        $this->login = (string) setting('mail_username', getenv('MAIL_USER') ?: 'info@silknaviora.com');
        $this->password = (string) setting('mail_password', getenv('MAIL_PASS') ?: 'YOUR_PASSWORD_HERE');
        $this->smtpHost = (string) setting('mail_smtp_host', getenv('SMTP_HOST') ?: 'mail.silknaviora.com');
        // 587/STARTTLS by default: port 465 is closed on mail.silknaviora.com.
        $this->smtpPort = (int) setting('mail_smtp_port', getenv('SMTP_PORT') ?: 587);

        // Try to connect to IMAP
        try {
            if (function_exists('imap_timeout')) {
                imap_timeout(IMAP_OPENTIMEOUT, 5);
                imap_timeout(IMAP_READTIMEOUT, 10);
                imap_timeout(IMAP_WRITETIMEOUT, 10);
            }
            if (!class_exists(Mailbox::class)) {
                throw new \RuntimeException('The php-imap library is not installed on this server: vendor/php-imap is missing or the Composer autoloader is stale. Re-upload the whole vendor/ folder or run "composer install" on the server.');
            }
            if (!\extension_loaded('imap')) {
                throw new \RuntimeException('The PHP "imap" extension is not enabled on this server. Install it (e.g. "sudo apt install php-imap" on Ubuntu, then restart Apache/PHP-FPM).');
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
                (is_dir($attachmentsDir) && is_writable($attachmentsDir)) ? $attachmentsDir : null // check is_writable so read-only folders don't break IMAP parsing
            );
            $this->mailbox->setConnectionArgs(CL_EXPUNGE);
        } catch (\Throwable $e) {
            $this->connectionError = $e->getMessage();
        }
    }

    private function requireMailbox(): Mailbox {
        if (!$this->mailbox instanceof Mailbox) {
            $message = 'IMAP mailbox is unavailable. Check the mail server settings and credentials.';
            if ($this->connectionError) {
                $message .= ' Details: ' . $this->connectionError;
            }
            throw new \RuntimeException($message);
        }

        return $this->mailbox;
    }

    public function getInbox($page = 1, $perPage = 20) {
        try {
            $mailbox = $this->requireMailbox();
            $mailsIds = $mailbox->searchMailbox('ALL');
            if(!$mailsIds) {
                return [];
            }
            rsort($mailsIds); // Newest first

            $pagedIds = array_slice($mailsIds, ($page - 1) * $perPage, $perPage);
            $emails = [];
            foreach ($pagedIds as $id) {
                $mail = $mailbox->getMail($id, false);
                $emails[] = [
                    'id' => $id,
                    'subject' => $mail->subject,
                    'fromName' => $mail->fromName,
                    'fromAddress' => $mail->fromAddress,
                    'date' => date('Y-m-d H:i:s', strtotime($mail->date)),
                    'isUnread' => $mail->isUnseen,
                    'snippet' => mb_substr(strip_tags($mail->textPlain ?: $mail->textHtml), 0, 100) . '...'
                ];
            }
            return $emails;
        } catch (\Throwable $e) {
            error_log("IMAP Error: " . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function getMessage($id) {
        try {
            $mailbox = $this->requireMailbox();
            $mail = $mailbox->getMail($id);
            $mailbox->markMailAsRead($id);

            $formattedAttachments = [];
            $rawAttachments = $mail->getAttachments();
            if (is_array($rawAttachments)) {
                foreach ($rawAttachments as $att) {
                    $filePath = $att->filePath ?: '';
                    $fileName = $att->name ?: ('attachment_' . ($att->id ?: uniqid()));
                    
                    // Generate a publicly accessible web URL for the attachment
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
                        'id' => $att->id ?? '',
                        'name' => $fileName,
                        'size' => $att->sizeInBytes ?? ( (!empty($filePath) && file_exists($filePath)) ? filesize($filePath) : 0 ),
                        'url' => $url,
                        'mime' => $att->mimeType ?? ($att->mime ?? 'application/octet-stream')
                    ];
                }
            }

            return [
                'id' => $id,
                'subject' => $mail->subject,
                'fromName' => $mail->fromName,
                'fromAddress' => $mail->fromAddress,
                'date' => date('F j, Y | g:i A', strtotime($mail->date)),
                'body' => $mail->textHtml ?: nl2br($mail->textPlain),
                'attachments' => $formattedAttachments
            ];
        } catch (\Throwable $e) {
            error_log("IMAP Error: " . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

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

    public function sendMessage($to, $subject, $body) {
        $strategies = [
            // Strategy 1: User Configured SMTP Settings
            [
                'name' => "Configured SMTP ({$this->smtpHost}:{$this->smtpPort})",
                'type' => 'smtp',
                'host' => $this->smtpHost,
                'port' => $this->smtpPort,
                'auth' => !empty($this->login) && !empty($this->password),
                'user' => $this->login,
                'pass' => $this->password,
                'secure' => ($this->smtpPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : ($this->smtpPort === 587 ? PHPMailer::ENCRYPTION_STARTTLS : '')),
                'autotls' => ($this->smtpPort !== 25)
            ],
            // Strategy 2: Localhost Relaying on Configured Port (Bypasses firewall hairpin & DNS issues)
            [
                'name' => "Localhost Relaying (127.0.0.1:{$this->smtpPort})",
                'type' => 'smtp',
                'host' => '127.0.0.1',
                'port' => $this->smtpPort,
                'auth' => !empty($this->login) && !empty($this->password),
                'user' => $this->login,
                'pass' => $this->password,
                'secure' => ($this->smtpPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : ($this->smtpPort === 587 ? PHPMailer::ENCRYPTION_STARTTLS : '')),
                'autotls' => false
            ],
            // Strategy 3: Localhost Port 25 Direct Relay (No Auth, No TLS - standard Postfix local delivery)
            [
                'name' => "Localhost Direct Relay (127.0.0.1:25)",
                'type' => 'smtp',
                'host' => '127.0.0.1',
                'port' => 25,
                'auth' => false,
                'user' => '',
                'pass' => '',
                'secure' => '',
                'autotls' => false
            ],
            // Strategy 4: Localhost Port 587 Unauthenticated Direct Relay
            [
                'name' => "Localhost Direct Relay (127.0.0.1:587)",
                'type' => 'smtp',
                'host' => '127.0.0.1',
                'port' => 587,
                'auth' => false,
                'user' => '',
                'pass' => '',
                'secure' => '',
                'autotls' => false
            ],
            // Strategy 5: Local Server Sendmail Binary (Bypasses all network TCP sockets)
            [
                'name' => "Local Sendmail Service (/usr/sbin/sendmail)",
                'type' => 'sendmail'
            ],
            // Strategy 6: PHP Native mail() function fallback
            [
                'name' => "PHP Native Mail Daemon",
                'type' => 'mail'
            ]
        ];

        $errors = [];
        foreach ($strategies as $strat) {
            $mail = new PHPMailer(true);
            try {
                if ($strat['type'] === 'smtp') {
                    $mail->isSMTP();
                    $mail->Host       = $strat['host'];
                    $mail->Port       = $strat['port'];
                    $mail->SMTPAuth   = $strat['auth'];
                    if ($strat['auth']) {
                        $mail->Username = $strat['user'];
                        $mail->Password = $strat['pass'];
                    }
                    $mail->SMTPSecure = $strat['secure'];
                    $mail->SMTPAutoTLS = $strat['autotls'];
                    $mail->Timeout    = 5; // Rapid switch timeout so fallback executes without hanging web UI
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true,
                        ],
                    ];
                } elseif ($strat['type'] === 'sendmail') {
                    if (!file_exists('/usr/sbin/sendmail') && !file_exists('/usr/lib/sendmail')) {
                        continue;
                    }
                    $mail->isSendmail();
                } elseif ($strat['type'] === 'mail') {
                    $mail->isMail();
                }

                $mail->setFrom($this->login, 'Silk Naviora');
                $mail->addAddress($to);

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->AltBody = strip_tags($body);

                $mail->send();
                error_log("Successfully sent email using strategy: " . $strat['name']);
                return true;
            } catch (\Throwable $e) {
                $err = $mail->ErrorInfo ?: $e->getMessage();
                error_log("Strategy [{$strat['name']}] failed: {$err}");
                $errors[] = "{$strat['name']} -> {$err}";
            }
        }

        throw new \RuntimeException("All delivery attempts failed:\n" . implode("\n", array_unique($errors)));
    }
}
