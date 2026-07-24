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

    public function __construct() {
        $imapHost = (string) setting('mail_imap_host', getenv('IMAP_HOST') ?: 'mail.silknaviora.uz');
        $this->imapPath = '{' . $imapHost . ':993/imap/ssl}INBOX';
        $this->login = (string) setting('mail_username', getenv('MAIL_USER') ?: 'info@silknaviora.uz');
        $this->password = (string) setting('mail_password', getenv('MAIL_PASS') ?: 'YOUR_PASSWORD_HERE');
        $this->smtpHost = (string) setting('mail_smtp_host', getenv('SMTP_HOST') ?: 'mail.silknaviora.uz');
        $this->smtpPort = (int) setting('mail_smtp_port', getenv('SMTP_PORT') ?: 465);

        // Try to connect to IMAP
        try {
            $this->mailbox = new Mailbox(
                $this->imapPath, 
                $this->login, 
                $this->password,
                __DIR__ . '/../assets/mail_attachments', // Directory for attachments
                'UTF-8',
                true, // Auto-trim
                false // Ignore self-signed certs (set to true if needed)
            );
            $this->mailbox->setConnectionArgs(CL_EXPUNGE);
        } catch (\Throwable $e) {
            // Ignore for now, handled on fetch
        }
    }

    private function requireMailbox(): Mailbox {
        if (!$this->mailbox instanceof Mailbox) {
            throw new \RuntimeException('IMAP mailbox is unavailable. Check the mail server settings and credentials.');
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
            return [
                'id' => $id,
                'subject' => $mail->subject,
                'fromName' => $mail->fromName,
                'fromAddress' => $mail->fromAddress,
                'date' => date('F j, Y | g:i A', strtotime($mail->date)),
                'body' => $mail->textHtml ?: nl2br($mail->textPlain),
                'attachments' => $mail->getAttachments()
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

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->smtpHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->login;
            $mail->Password   = $this->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // or ENCRYPTION_STARTTLS
            $mail->Port       = $this->smtpPort;

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
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}
