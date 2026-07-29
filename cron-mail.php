<?php
/**
 * Cron script to check for new incoming emails and send notifications to the Telegram group.
 * 
 * Usage from Linux command line or cron schedule (run every 1-2 minutes):
 * * * * * php /var/www/camping-uz-admin/cron-mail.php
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/MailClient.php';

// Release session lock immediately if any was started during bootstrap
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $notified = \App\MailClient::notifyAllNewEmails();
    if (PHP_SAPI === 'cli') {
        echo "[{" . date('Y-m-d H:i:s') . "}] Checked incoming mail. Sent {$notified} new Telegram group notification(s).\n";
    }
} catch (\Throwable $e) {
    if (PHP_SAPI === 'cli') {
        echo "[{" . date('Y-m-d H:i:s') . "}] Error: " . $e->getMessage() . "\n";
    }
    error_log("cron-mail.php Error: " . $e->getMessage());
    exit(1);
}
