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
        // NOTE: These should be moved to settings.php or .env ideally
        $this->imapPath = '{' . (getenv('IMAP_HOST') ?: 'mail.silknaviora.uz') . ':993/imap/ssl}INBOX';
        $this->login = getenv('MAIL_USER') ?: 'info@silknaviora.uz';
        $this->password = getenv('MAIL_PASS') ?: 'YOUR_PASSWORD_HERE';
        $this->smtpHost = getenv('SMTP_HOST') ?: 'mail.silknaviora.uz';
        $this->smtpPort = getenv('SMTP_PORT') ?: 465;

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
        } catch (\Exception $e) {
            // Ignore for now, handled on fetch
        }
    }

    public function getInbox($page = 1, $perPage = 20) {
        try {
            $mailsIds = $this->mailbox->searchMailbox('ALL');
            if(!$mailsIds) {
                return [];
            }
            rsort($mailsIds); // Newest first

            $pagedIds = array_slice($mailsIds, ($page - 1) * $perPage, $perPage);
            $emails = [];

            foreach ($pagedIds as $id) {
                $mail = $this->mailbox->getMail($id, false);
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
        } catch (\Exception $e) {
            error_log("IMAP Error: " . $e->getMessage());
            return [];
        }
    }

    public function getMessage($id) {
        try {
            $mail = $this->mailbox->getMail($id);
            $this->mailbox->markMailAsRead($id);
            return [
                'id' => $id,
                'subject' => $mail->subject,
                'fromName' => $mail->fromName,
                'fromAddress' => $mail->fromAddress,
                'date' => date('F j, Y | g:i A', strtotime($mail->date)),
                'body' => $mail->textHtml ?: nl2br($mail->textPlain),
                'attachments' => $mail->getAttachments()
            ];
        } catch (\Exception $e) {
            error_log("IMAP Error: " . $e->getMessage());
            return null;
        }
    }

    public function deleteMessage($id) {
        try {
            $this->mailbox->deleteMail($id);
            return true;
        } catch (\Exception $e) {
            return false;
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
