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
        $mail = new PHPMailer(true);
        $isLocal = in_array(strtolower(trim($this->smtpHost)), ['127.0.0.1', 'localhost', '::1'], true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->smtpHost;
            $mail->SMTPAuth   = !empty($this->login) && !empty($this->password);
            $mail->Username   = $this->login;
            $mail->Password   = $this->password;
            
            if ($this->smtpPort === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->smtpPort === 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
            $mail->Port       = $this->smtpPort;

            // Prevent SSL hostname mismatch errors when connecting to 127.0.0.1 or local mail servers
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            // Recipients
            $mail->setFrom($this->login, 'Silk Naviora');
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            $firstError = $mail->ErrorInfo ?: $e->getMessage();
            error_log("First SMTP send attempt failed: {$firstError}");

            // Automatic fallback for local Postfix servers:
            // Many Ubuntu VPS Postfix implementations disallow SASL auth or forced STARTTLS over loopback connections (127.0.0.1).
            if ($isLocal) {
                try {
                    $mail->SMTPAuth = false;
                    $mail->SMTPSecure = '';
                    $mail->SMTPAutoTLS = false;
                    $mail->send();
                    return true;
                } catch (\Throwable $fallbackError) {
                    $secondError = $mail->ErrorInfo ?: $fallbackError->getMessage();
                    error_log("Fallback SMTP send failed: {$secondError}");
                }
            }

            throw new \RuntimeException($firstError);
        }
    }
}
