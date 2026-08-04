<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require_once __DIR__ . '/app/MailClient.php';

// Which mailbox this request is for. Every AJAX call and both compose forms carry
// an "account" field, so a single page can talk to either mailbox without reloading.
$mailAccounts = \App\MailClient::accounts();
$accountId    = \App\MailClient::resolveAccountId(input('account', 0));
$mailClient   = new \App\MailClient($accountId);

// Handle AJAX GET requests
if (isset($_GET['action'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if ($_GET['action'] === 'download_attachment') {
        $fileBasename = basename($_GET['file'] ?? '');
        $realName = $_GET['name'] ?? $fileBasename;
        $mime = $_GET['mime'] ?? 'application/octet-stream';
        
        $targetDir = realpath(__DIR__ . '/assets/mail_attachments');
        $fullPath = realpath($targetDir . '/' . $fileBasename);
        
        if (!$fullPath || !str_starts_with($fullPath, $targetDir) || !is_file($fullPath)) {
            http_response_code(404);
            die("Attachment file not found on disk.");
        }
        
        if ($mime === 'application/octet-stream' || empty($mime)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($fullPath);
            if ($detectedMime && $detectedMime !== 'application/octet-stream') {
                $mime = $detectedMime;
            }
        }
        
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addcslashes($realName, '"') . '"; filename*=UTF-8\'\'' . rawurlencode($realName));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullPath));
        
        readfile($fullPath);
        exit;
    }
    header('Content-Type: application/json');
    try {
        if ($_GET['action'] === 'inbox') {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            echo json_encode($mailClient->getInbox($page));
        } elseif ($_GET['action'] === 'sent') {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            echo json_encode($mailClient->getSentMessages($page));
        } elseif ($_GET['action'] === 'message' && isset($_GET['id'])) {
            $folder = $_GET['folder'] ?? 'INBOX';
            echo json_encode($mailClient->getMessage($_GET['id'], $folder));
        } elseif ($_GET['action'] === 'thread' && isset($_GET['id'])) {
            $folder = $_GET['folder'] ?? 'INBOX';
            echo json_encode($mailClient->getThread($_GET['id'], $folder));
        } elseif ($_GET['action'] === 'delete' && isset($_GET['id'])) {
            echo json_encode(['success' => $mailClient->deleteMessage($_GET['id'])]);
        } elseif ($_GET['action'] === 'mark_unread' && isset($_GET['id'])) {
            echo json_encode(['success' => $mailClient->markAsUnread($_GET['id'])]);
        }
    } catch (\Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle POST — send email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    csrf_verify();
    $to      = input('to', '');
    $subject = input('subject', '');
    $body    = input('body', '');
    $cc      = input('cc', '');
    $bcc     = input('bcc', '');
    $attachments = $_FILES['attachments'] ?? [];
    
    $isAjax = !empty($_POST['ajax'])
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    // Release the session lock before the SMTP/IMAP conversation. PHP's file session
    // handler holds an exclusive lock for the whole request, so without this every
    // other admin request — including ones in a brand new tab — blocks until the send
    // finishes. That is why the panel froze for as long as the spinner ran.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    @set_time_limit(90);

    try {
        if ($mailClient->sendMessage($to, $subject, $body, $cc, $bcc, $attachments)) {
            $transport = $mailClient->getLastTransport();
            // The mail server has *accepted* the message; it has not necessarily been
            // delivered yet. Say what actually happened rather than "sent successfully".
            $note = 'Message accepted by the mail server and queued for delivery.';
            if ($isAjax) {
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: application/json');
                echo json_encode([
                    'success'   => true,
                    'message'   => $note,
                    'transport' => $transport,
                    'from'      => $mailClient->getAddress(),
                ]);
                exit;
            }
            if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
            flash('success', $note);
        }
    } catch (\Throwable $e) {
        if ($isAjax) {
            while (ob_get_level()) { ob_end_clean(); }
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        session_start();
        flash('error', 'Failed to send email: ' . $e->getMessage());
    }
    redirect('email.php');
}

$page = [
    'title'      => 'Mail',
    'subtitle'   => 'Read and reply to mail sent to your agency addresses.',
    'active'     => 'mail',
    'vendor_js'  => ['libs/bootstrap/js/bootstrap.bundle.min.js'],
];
require __DIR__ . '/partials/head.php';
?>

<!-- Gmail Inspired Webmail CSS -->
<style>
    /* Webmail skin — mapped onto the admin design tokens so the Mail screen
       belongs to the same product as everything else, in both themes. */
    .gmail-app-wrapper {
        --gmail-bg: var(--canvas);
        --gmail-card-bg: var(--surface);
        --gmail-border: var(--border);
        --gmail-hover: var(--surface-hover);
        --gmail-unread-bg: var(--surface);
        --gmail-read-bg: var(--surface-sunk);
        --gmail-primary: var(--primary);
        --gmail-compose-bg: var(--primary);
        --gmail-compose-hover: var(--primary-hover);
        --gmail-compose-text: var(--primary-fg);
    }
    .gmail-app-wrapper {
        background: var(--gmail-bg);
        border: 1px solid var(--gmail-border);
        border-radius: 16px;
        overflow: hidden;
        min-height: calc(100vh - 180px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        display: flex;
        flex-direction: column;
    }

    /* Top Search & Action Header */
    .gmail-header-bar {
        background: var(--gmail-card-bg);
        padding: 12px 24px;
        border-bottom: 1px solid var(--gmail-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
    }
    .gmail-search-box {
        max-width: 680px;
        flex-grow: 1;
        background: var(--primary-soft);
        border-radius: 24px;
        padding: 8px 18px;
        display: flex;
        align-items: center;
        transition: background-color 0.2s, box-shadow 0.2s;
        border: 1px solid transparent;
    }
    .gmail-search-box:focus-within {
        background: var(--surface);
        box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15);
        border-color: var(--border);
    }
    .gmail-search-input {
        border: none;
        background: transparent;
        width: 100%;
        outline: none;
        font-size: 15px;
        color: var(--fg-strong);
        padding-left: 10px;
    }

    /* Mailbox switcher pill */
    .gmail-account-switch {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        max-width: 260px;
        border: 1px solid var(--gmail-border);
        background: var(--surface);
        border-radius: 20px;
        padding: 5px 12px 5px 6px;
        font-size: 13px;
        font-weight: 600;
        color: var(--fg-strong);
        transition: background-color 0.15s, box-shadow 0.15s;
    }
    .gmail-account-switch:hover {
        background: var(--gmail-hover);
        box-shadow: 0 1px 3px rgba(60,64,67,0.15);
    }
    .gmail-account-dot {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: var(--gmail-primary);
        color: var(--surface);
        font-size: 13px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .gmail-account-option.active {
        background: var(--primary-soft);
    }

    /* Threaded conversation */
    .thread-message {
        border: 1px solid var(--gmail-border);
        border-radius: 12px;
        background: var(--gmail-card-bg);
        padding: 14px 18px;
        margin-bottom: 12px;
    }
    .thread-message.is-sent {
        background: var(--surface-sunk);
        border-color: var(--primary-soft);
    }
    .thread-message-head {
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        user-select: none;
    }
    .thread-message-body { border-top: 1px solid var(--gmail-border); margin-top: 12px; }
    .thread-message-body .gmail-reading-body { padding: 0; overflow-wrap: anywhere; }
    .thread-message-body img { max-width: 100%; height: auto; }
    .thread-message-body table { max-width: 100%; }
    .thread-preview { min-height: 1em; }

    /* Collapsed quoted reply chain */
    .mail-quote-toggle {
        border: none;
        background: var(--border);
        color: var(--fg-muted);
        border-radius: 10px;
        padding: 1px 10px;
        line-height: 1;
        cursor: pointer;
        transition: background-color 0.15s;
    }
    .mail-quote-toggle:hover,
    .mail-quote-toggle.active { background: var(--border-strong); }
    .mail-quote {
        margin: 12px 0 0 0;
        padding: 4px 0 4px 14px;
        border-left: 2px solid var(--border);
        color: var(--fg-muted);
        font-size: 14px;
        overflow-x: auto;
    }
    .mail-quote img { max-width: 100%; height: auto; }

    /* Message bodies are attacker-controlled — never let one break the layout. */
    #detailBody { overflow-wrap: anywhere; }
    #detailBody img { max-width: 100%; height: auto; }
    #detailBody table { max-width: 100%; }

    /* Main Workspace Split Layout */
    .gmail-workspace {
        display: flex;
        flex-grow: 1;
        overflow: hidden;
        min-height: 580px;
    }
    .gmail-sidebar {
        width: 256px;
        flex-shrink: 0;
        padding: 16px 0;
        display: flex;
        flex-direction: column;
        background: var(--gmail-bg);
        border-right: 1px solid var(--gmail-border);
    }

    /* Iconic Compose Pill Button */
    .gmail-compose-btn {
        margin: 0 16px 20px 16px;
        background: var(--gmail-compose-bg);
        color: var(--gmail-compose-text);
        border-radius: 16px;
        padding: 14px 24px;
        font-weight: 600;
        font-size: 15px;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 14px;
        width: fit-content;
        transition: box-shadow 0.15s, background-color 0.15s;
        box-shadow: 0 1px 3px 0 rgba(60,64,67,0.2), 0 2px 6px 2px rgba(60,64,67,0.08);
    }
    .gmail-compose-btn:hover {
        background: var(--gmail-compose-hover);
        box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15);
        color: var(--gmail-compose-text);
    }

    /* Sidebar Nav Links */
    .gmail-nav-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 24px 10px 26px;
        color: var(--fg-muted);
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        border-radius: 0 20px 20px 0;
        margin-right: 16px;
        transition: background-color 0.15s, font-weight 0.15s;
        cursor: pointer;
    }
    .gmail-nav-item:hover {
        background: rgba(0, 0, 0, 0.05);
        color: var(--fg-strong);
    }
    .gmail-nav-item.active {
        background: var(--primary-soft);
        color: var(--fg-strong);
        font-weight: 700;
    }
    .gmail-nav-item.active i {
        color: var(--fg-strong) !important;
    }

    /* Message Box & Rows */
    .gmail-main-box {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        background: var(--surface);
        overflow: hidden;
        border-top-left-radius: 16px;
        border-left: 1px solid var(--gmail-border);
    }
    .gmail-list-scroll {
        flex-grow: 1;
        overflow-y: auto;
    }
    .gmail-message-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    /* Message Row with Hover Action Toolbar */
    .gmail-row {
        display: flex;
        align-items: center;
        padding: 10px 20px;
        border-bottom: 1px solid var(--surface-hover);
        cursor: pointer;
        transition: box-shadow 0.1s, background-color 0.15s, border-color 0.15s;
        position: relative;
        background: var(--gmail-read-bg);
        color: var(--fg-muted);
        font-size: 14px;
    }
    .gmail-row.unread {
        background: var(--gmail-unread-bg);
        font-weight: 700;
        color: var(--fg-strong);
    }
    .gmail-row:hover {
        background-color: var(--gmail-hover);
        box-shadow: inset 1px 0 0 var(--border), inset -1px 0 0 var(--border), 0 1px 2px 0 rgba(60,64,67,0.1);
        z-index: 2;
    }
    .gmail-sender {
        width: 230px;
        flex-shrink: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding-right: 16px;
        font-size: 14px;
    }
    .gmail-subject-snippet {
        flex-grow: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
        display: flex;
        align-items: center;
    }
    .gmail-subject {
        color: inherit;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .gmail-snippet {
        color: var(--fg-muted);
        font-weight: 400;
        margin-left: 6px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .gmail-meta-right {
        width: 130px;
        flex-shrink: 0;
        text-align: right;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
    }
    .gmail-date {
        display: block;
        transition: opacity 0.1s;
    }
    .gmail-hover-actions {
        display: none;
        align-items: center;
        gap: 6px;
        background: inherit;
    }
    .gmail-row:hover .gmail-date {
        display: none;
    }
    .gmail-row:hover .gmail-hover-actions {
        display: flex;
    }

    /* Attachment indicator */
    .gmail-attach-icon {
        color: var(--fg-muted);
        font-size: 14px;
        margin-right: 8px;
        flex-shrink: 0;
    }

    /* Icon Buttons (Circular Material styles) */
    .gmail-icon-btn {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: none;
        background: transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--fg-muted);
        transition: background-color 0.15s;
    }
    .gmail-icon-btn:hover {
        background-color: rgba(68, 71, 70, 0.1);
        color: var(--fg-strong);
    }
    .gmail-icon-btn.delete-btn:hover {
        background-color: rgba(220, 53, 69, 0.1);
        color: var(--danger);
    }

    /* Avatar Tag */
    .gmail-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        color: var(--surface);
        font-size: 15px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-right: 14px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }

    /* Reading View */
    .gmail-reading-pane {
        display: flex;
        flex-direction: column;
        height: 100%;
        background: var(--surface);
    }
    .gmail-reading-header {
        padding: 20px 28px 16px 28px;
        border-bottom: 1px solid var(--surface-hover);
    }
    .gmail-subject-title {
        font-size: 22px;
        font-weight: 400;
        color: var(--fg-strong);
        margin-bottom: 16px;
        word-break: break-word;
    }
    .gmail-reading-body {
        padding: 24px 28px;
        font-size: 15px;
        line-height: 1.65;
        color: var(--fg-strong);
        overflow-y: auto;
        flex-grow: 1;
    }
    .gmail-quick-reply-box {
        margin: 20px 28px 28px 28px;
        border: 1px solid var(--border);
        border-radius: 24px;
        padding: 16px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--surface-sunk);
        cursor: pointer;
        transition: box-shadow 0.15s, border-color 0.15s;
    }
    .gmail-quick-reply-box:hover {
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border-color: var(--border-strong);
        background: var(--surface);
    }

    /* CC/BCC toggle */
    .ccbcc-toggle {
        font-size: 13px;
        color: var(--fg-muted);
        cursor: pointer;
        text-decoration: none;
        font-weight: 500;
    }
    .ccbcc-toggle:hover {
        color: var(--gmail-primary);
    }

    /* Attachment dropzone */
    .gmail-attach-zone {
        border: 2px dashed var(--border);
        border-radius: 12px;
        padding: 12px 16px;
        text-align: center;
        color: var(--fg-muted);
        font-size: 13px;
        cursor: pointer;
        transition: border-color 0.2s, background-color 0.2s;
    }
    .gmail-attach-zone:hover, .gmail-attach-zone.dragover {
        border-color: var(--gmail-primary);
        background: var(--primary-soft);
    }
    .gmail-attach-zone input[type="file"] {
        display: none;
    }

    /* Two hues the palette doesn't carry, for image and media attachments. */
    :root { --att-violet: #6d4bd8; --att-teal: #0f8f9e; }
    :root[data-theme="dark"] { --att-violet: #a78bfa; --att-teal: #3fc0d0; }
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme]) { --att-violet: #a78bfa; --att-teal: #3fc0d0; }
    }

    /* Attachment cards — one look for the reader strip and for thread messages,
       tinted by file type so a spreadsheet doesn't read like a deck. */
    .mail-attach,
    .mail-attach:hover,
    .mail-attach:focus,
    .mail-attach:active {
        text-decoration: none !important;
        color: var(--fg-strong) !important;
    }
    .mail-attach {
        --att-tint: var(--fg-muted);
        box-sizing: border-box;
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        max-width: 280px;
        padding: 8px 12px 8px 8px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface);
        transition: border-color 0.15s, background-color 0.15s, box-shadow 0.15s;
    }
    .mail-attach:hover,
    .mail-attach:focus {
        background: var(--surface-hover);
        border-color: color-mix(in srgb, var(--att-tint) 45%, var(--border));
        box-shadow: var(--shadow-sm);
    }
    .mail-attach-icon {
        width: 36px;
        height: 36px;
        flex-shrink: 0;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        line-height: 1;
        color: var(--att-tint);
        background: color-mix(in srgb, var(--att-tint) 14%, transparent);
    }
    .mail-attach-text { min-width: 0; }
    .mail-attach-name {
        font-size: var(--fs-sm);
        font-weight: var(--fw-semibold);
        color: var(--fg-strong);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .mail-attach-meta {
        font-size: var(--fs-2xs);
        color: var(--fg-muted);
        letter-spacing: 0.02em;
    }
    .mail-attach-dl {
        margin-left: auto;
        flex-shrink: 0;
        font-size: 16px;
        color: var(--fg-subtle);
        transition: color 0.15s;
    }
    .mail-attach:hover .mail-attach-dl { color: var(--att-tint); }

    /* Compose picks the same tints up at badge scale. */
    .mail-attach-chip {
        --att-tint: var(--fg-muted);
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: 240px;
        margin: 0 6px 6px 0;
        padding: 4px 10px;
        border: 1px solid var(--border);
        border-radius: var(--r-full);
        background: var(--surface);
        font-size: var(--fs-xs);
        color: var(--fg);
    }
    .mail-attach-chip i { color: var(--att-tint); font-size: 14px; flex-shrink: 0; }
    .mail-attach-chip-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mail-attach-chip-size { color: var(--fg-muted); flex-shrink: 0; }

    /* File-type tints */
    .att-pdf     { --att-tint: var(--danger); }
    .att-sheet   { --att-tint: var(--success); }
    .att-doc     { --att-tint: var(--info); }
    .att-slides  { --att-tint: var(--warning); }
    .att-archive { --att-tint: var(--accent); }
    .att-image   { --att-tint: var(--att-violet); }
    .att-media   { --att-tint: var(--att-teal); }
    .att-code    { --att-tint: var(--primary); }

    /* Lightweight Rich Text Editor */
    .mail-editor-wrapper {
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface);
        overflow: hidden;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .mail-editor-wrapper:focus-within {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
    }
    .mail-editor-toolbar {
        background: var(--surface-sunk);
        border-bottom: 1px solid var(--border);
        padding: 6px 10px;
        display: flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
    }
    .mail-editor-toolbar .btn-tool {
        border: none;
        background: transparent;
        color: var(--fg-muted);
        width: 30px;
        height: 30px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        cursor: pointer;
        transition: background-color 0.15s, color 0.15s;
    }
    .mail-editor-toolbar .btn-tool:hover {
        background: rgba(0, 0, 0, 0.06);
        color: var(--fg-strong);
    }
    .mail-editor-content {
        min-height: 220px;
        max-height: 420px;
        overflow-y: auto;
        padding: 14px 16px;
        outline: none;
        line-height: 1.6;
        color: var(--fg-strong);
        word-break: break-word;
    }
    .mail-editor-content[contenteditable="true"]:empty:before {
        content: attr(data-placeholder);
        color: var(--fg-muted);
        pointer-events: none;
        display: block;
        font-style: italic;
    }
    .mail-editor-content p, .mail-editor-content ul, .mail-editor-content ol {
        margin-bottom: 0.75rem;
    }
    .mail-editor-content a {
        color: var(--primary);
        text-decoration: underline;
    }

    @media (max-width: 991.98px) {
        .gmail-workspace {
            flex-direction: column;
        }
        .gmail-sidebar {
            width: 100%;
            border-right: none;
            border-bottom: 1px solid var(--gmail-border);
            padding: 12px 0;
        }
        .gmail-main-box {
            border-top-left-radius: 0;
            border-left: none;
        }
        .gmail-sender {
            width: 130px;
        }
    }

    /* The mail folder list runs horizontally across the top of the workspace.
       As a vertical rail it sat directly beside the main sidebar and read as a
       second, competing navigation. */
    .gmail-workspace { flex-direction: column !important; }
    .gmail-sidebar {
        width: 100% !important;
        max-width: none !important;
        flex-direction: row !important;
        align-items: center;
        gap: var(--sp-2);
        padding: var(--sp-3) var(--sp-4) !important;
        border-right: 0 !important;
        border-bottom: 1px solid var(--gmail-border);
        overflow-x: auto;
    }
    .gmail-sidebar > .flex-grow-1 {
        display: flex;
        align-items: center;
        gap: var(--sp-1);
        flex-grow: 0 !important;
    }
    .gmail-nav-item {
        border-radius: var(--r-md) !important;
        margin: 0 !important;
        padding: var(--sp-2) var(--sp-3) !important;
        white-space: nowrap;
    }
    .gmail-compose-btn {
        margin: 0 !important;
        flex-shrink: 0;
        border-radius: var(--r-md) !important;
    }
    #inboxStatusText {
        margin: 0 0 0 auto !important;
        padding: 0 !important;
        border: 0 !important;
        white-space: nowrap;
        text-align: end !important;
    }
    @media (max-width: 767.98px) {
        #inboxStatusText { display: none !important; }
    }
</style>

<div class="gmail-app-wrapper mb-4">
    <!-- Top Search & Controls Header -->
    <div class="gmail-header-bar">
        <div class="d-flex align-items-center gap-2">
            <i class="ri-mail-star-fill fs-24 text-primary"></i>
            <span class="t-md t-semibold t-strong d-none d-xl-inline">Mailbox</span>

            <!-- Mailbox switcher: every request below carries the selected account id -->
            <div class="dropdown">
                <button class="gmail-account-switch" type="button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false" title="Switch mailbox">
                    <span class="gmail-account-dot" id="accountSwitchDot">?</span>
                    <span class="text-truncate" id="accountSwitchLabel">Mailbox</span>
                    <i class="ri-arrow-down-s-line fs-16 flex-shrink-0"></i>
                </button>
                <ul class="dropdown-menu shadow-sm border rounded-3 py-2" style="min-width: 260px;">
                    <li><h6 class="dropdown-header fs-11 text-uppercase fw-bold">Mail accounts</h6></li>
                    <?php foreach ($mailAccounts as $acc): ?>
                        <li>
                            <button type="button" class="dropdown-item d-flex align-items-center gap-2 py-2 gmail-account-option"
                                    data-account="<?= (int) $acc['id'] ?>"
                                    data-address="<?= e($acc['address']) ?>"
                                    data-label="<?= e($acc['label']) ?>"
                                    onclick="switchAccount(<?= (int) $acc['id'] ?>)">
                                <i class="ri-checkbox-circle-fill text-primary fs-16 gmail-account-check invisible"></i>
                                <span class="overflow-hidden">
                                    <span class="d-block fw-semibold fs-13 text-truncate"><?= e($acc['label']) ?></span>
                                    <?php if ($acc['label'] !== $acc['address']): ?>
                                        <span class="d-block text-body-secondary fs-12 text-truncate"><?= e($acc['address']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item fs-13 d-flex align-items-center gap-2 py-2" href="integrations">
                            <i class="ri-settings-3-line fs-16"></i>
                            <?= count($mailAccounts) > 1 ? 'Manage mail accounts' : 'Add a second mail account' ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="gmail-search-box">
            <i class="ri-search-line fs-18 text-muted"></i>
            <input type="text" id="gmailSearchInput" class="gmail-search-input" placeholder="Search mail, senders, or subjects..." oninput="filterEmails()">
            <button type="button" class="btn-close d-none fs-12 ms-2" id="clearSearchBtn" onclick="clearSearch()" title="Clear search"></button>
        </div>

        <div class="d-flex align-items-center gap-1">
            <button type="button" class="gmail-icon-btn" onclick="loadCurrentFolder()" title="Refresh">
                <i class="ri-refresh-line fs-18"></i>
            </button>
        </div>
    </div>

    <!-- Main Webmail Workspace -->
    <div class="gmail-workspace">
        <!-- Sidebar Navigation -->
        <div class="gmail-sidebar">
            <button class="gmail-compose-btn" data-bs-toggle="modal" data-bs-target="#composeModal">
                <i class="ri-pencil-fill fs-18 text-primary"></i>
                <span>Compose</span>
            </button>
            
            <div class="flex-grow-1">
                <a href="#" class="gmail-nav-item active" data-folder="inbox" onclick="switchFolder('inbox'); return false;">
                    <span class="d-flex align-items-center gap-3">
                        <i class="ri-inbox-archive-fill fs-18"></i>
                        <span>Inbox</span>
                    </span>
                    <span class="badge bg-primary rounded-pill fs-11 ms-2" id="inboxBadge">...</span>
                </a>
                <a href="#" class="gmail-nav-item" data-folder="unread" onclick="switchFolder('unread'); return false;">
                    <span class="d-flex align-items-center gap-3">
                        <i class="ri-mail-unread-line fs-18 text-muted"></i>
                        <span>Unread</span>
                    </span>
                    <span class="badge bg-secondary-subtle text-dark rounded-pill fs-11 ms-2" id="unreadBadge">0</span>
                </a>
                <a href="#" class="gmail-nav-item" data-folder="sent" onclick="switchFolder('sent'); return false;">
                    <span class="d-flex align-items-center gap-3">
                        <i class="ri-send-plane-fill fs-18 text-muted"></i>
                        <span>Sent</span>
                    </span>
                    <span class="badge bg-secondary-subtle text-dark rounded-pill fs-11 ms-2" id="sentBadge">—</span>
                </a>
            </div>
            
            <div class="px-3 text-muted fs-12 border-top pt-3 mt-auto text-center" id="inboxStatusText">
                Connecting to mail daemon...
            </div>
        </div>
        
        <!-- Main Panel: Mailbox & Reader -->
        <div class="gmail-main-box">
            <!-- VIEW 1: Inbox Message List -->
            <div id="mailListView" class="d-flex flex-column flex-grow-1 h-100">
                <div class="py-2 px-3 border-bottom d-flex align-items-center justify-content-between text-muted fs-13 bg-light-subtle">
                    <span id="listFilterLabel" class="fw-medium">Showing messages in Inbox</span>
                    <span id="inboxCount" class="fw-medium">Loading...</span>
                </div>
                
                <div class="gmail-list-scroll">
                    <div id="loadingIndicator" class="text-center py-5 my-5">
                        <div class="spinner-border text-primary my-2" role="status"></div>
                        <div class="text-muted fs-14 mt-2 fw-medium">Loading your conversations...</div>
                    </div>
                    
                    <div id="emptySearchState" class="text-center py-5 my-5 d-none">
                        <i class="ri-search-2-line fs-1 opacity-25 d-block mb-3"></i>
                        <h6 class="text-dark fw-semibold">No matching messages found</h6>
                        <p class="text-muted fs-13 mb-3">Try searching for a different keyword or check your spelling.</p>
                        <button class="btn btn-sm btn-outline-secondary px-4 rounded-pill" onclick="clearSearch()">Reset Search</button>
                    </div>

                    <ul class="gmail-message-list" id="mail-list">
                        <!-- Messages dynamically rendered via JavaScript -->
                    </ul>
                </div>
            </div>
            
            <!-- VIEW 2: Detailed Email Reader -->
            <div id="emailDetailsView" class="d-none flex-column flex-grow-1 h-100">
                <div class="gmail-reading-pane">
                    <!-- Reader Action Toolbar -->
                    <div class="py-2 px-3 border-bottom d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="gmail-icon-btn me-2" id="closeReadEmail" title="Back to list">
                                <i class="ri-arrow-left-line fs-20"></i>
                            </button>
                            <button type="button" class="gmail-icon-btn delete-btn" id="deleteEmailBtn" title="Delete conversation">
                                <i class="ri-delete-bin-line fs-18"></i>
                            </button>
                            <button type="button" class="gmail-icon-btn" id="markUnreadBtn" title="Mark as unread">
                                <i class="ri-mail-unread-line fs-18"></i>
                            </button>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3 d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#replyModal">
                                <i class="ri-reply-line fs-16"></i> <span>Reply</span>
                            </button>
                        </div>
                    </div>

                    <!-- Email Reading Header -->
                    <div class="gmail-reading-header">
                        <h3 class="gmail-subject-title" id="detailSubject">Loading subject...</h3>
                        <!-- Single-message sender strip. Hidden in threaded view, where each
                             message in the conversation carries its own header. -->
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2" id="detailSingleHeader">
                            <div class="d-flex align-items-center">
                                <div class="gmail-avatar fs-18 mb-0" id="detailAvatar" style="width:44px; height:44px;">?</div>
                                <div>
                                    <div class="d-flex align-items-center gap-2">
                                        <h6 class="mb-0 fw-bold text-dark fs-15" id="detailFromName">Sender Name</h6>
                                        <span class="text-muted fs-13" id="detailFromAddress">&lt;email@domain.com&gt;</span>
                                    </div>
                                    <div class="text-muted fs-12 mt-1">to me <i class="ri-arrow-down-s-line align-middle"></i></div>
                                </div>
                            </div>
                            <div class="text-muted fs-13 fw-medium" id="detailDate">Date</div>
                        </div>
                    </div>
                    
                    <!-- Email Reading Body Content -->
                    <div class="gmail-reading-body" id="detailBody">
                        <!-- Content inserted here -->
                    </div>
                    
                    <!-- Attachments Display Cards -->
                    <div id="detailAttachmentsContainer" class="d-none px-4 pb-4 border-top pt-3">
                        <h6 class="fs-13 fw-bold text-dark mb-3"><i class="ri-attachment-line me-1 text-primary"></i>Attachments (<span id="attachmentsCount">0</span>)</h6>
                        <div class="d-flex flex-wrap gap-3" id="detailAttachments">
                            <!-- Attachment cards rendered here -->
                        </div>
                    </div>
                    
                    <!-- Gmail Signature Quick-Reply Bottom Box -->
                    <div class="gmail-quick-reply-box shadow-sm" data-bs-toggle="modal" data-bs-target="#replyModal" title="Click to open reply composer">
                        <div class="d-flex align-items-center gap-3">
                            <div class="gmail-avatar bg-primary mb-0 me-0" style="width:32px; height:32px; font-size:14px;"><i class="ri-reply-line"></i></div>
                            <span class="text-muted fs-14 fw-medium">Click here to reply to <span id="quickReplySenderName" class="text-dark fw-semibold">this sender</span>...</span>
                        </div>
                        <span class="badge bg-light text-primary border rounded-pill px-3 py-1">Reply</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     COMPOSE MODAL — Full featured with CC/BCC/Attachments
     ═══════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="composeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" method="post" action="email.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="account" value="<?= (int) $accountId ?>" class="js-account-field">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <h5 class="modal-title fw-semibold fs-16 mb-0"><i class="ri-mail-send-line me-2 text-info"></i>New Message</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <!-- Sending mailbox — follows the account switcher in the header -->
                <div class="mb-2 border-bottom pb-2 d-flex align-items-center">
                    <span class="text-muted fs-13 me-2 flex-shrink-0">From</span>
                    <span class="fs-14 fw-medium text-dark text-truncate js-account-from"><?= e($mailAccounts[$accountId]['address'] ?? '') ?></span>
                    <?php if (count($mailAccounts) > 1): ?>
                        <span class="ms-auto fs-12 text-body-secondary flex-shrink-0">Switch mailbox in the header</span>
                    <?php endif; ?>
                </div>
                <!-- To field + CC/BCC toggle -->
                <div class="mb-2 border-bottom pb-2 d-flex align-items-center">
                    <span class="text-muted fs-13 me-2 flex-shrink-0">To</span>
                    <input type="email" name="to" class="form-control border-0 px-1 shadow-none fs-15 fw-medium" placeholder="recipient@example.com" required>
                    <span class="ccbcc-toggle ms-2 flex-shrink-0" onclick="toggleCcBcc('compose')">Cc/Bcc</span>
                </div>
                <!-- CC field (hidden by default) -->
                <div class="mb-2 border-bottom pb-2 d-none" id="composeCcRow">
                    <div class="d-flex align-items-center">
                        <span class="text-muted fs-13 me-2 flex-shrink-0">Cc</span>
                        <input type="text" name="cc" class="form-control border-0 px-1 shadow-none fs-14" placeholder="cc@example.com (comma-separated)">
                    </div>
                </div>
                <!-- BCC field (hidden by default) -->
                <div class="mb-2 border-bottom pb-2 d-none" id="composeBccRow">
                    <div class="d-flex align-items-center">
                        <span class="text-muted fs-13 me-2 flex-shrink-0">Bcc</span>
                        <input type="text" name="bcc" class="form-control border-0 px-1 shadow-none fs-14" placeholder="bcc@example.com (comma-separated)">
                    </div>
                </div>
                <!-- Subject -->
                <div class="mb-2 border-bottom pb-2">
                    <input type="text" name="subject" class="form-control border-0 px-1 shadow-none fs-15 fw-bold" placeholder="Subject" required>
                </div>
                <!-- Body -->
                <div>
                    <div class="mail-editor-wrapper mt-2">
                        <div class="mail-editor-toolbar">
                            <button type="button" class="btn-tool" title="Bold" onmousedown="event.preventDefault(); execMailCommand('bold');"><i class="ri-bold fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Italic" onmousedown="event.preventDefault(); execMailCommand('italic');"><i class="ri-italic fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Underline" onmousedown="event.preventDefault(); execMailCommand('underline');"><i class="ri-underline fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Strikethrough" onmousedown="event.preventDefault(); execMailCommand('strikethrough');"><i class="ri-strikethrough fs-16"></i></button>
                            <span class="border-end py-2 mx-1 opacity-25"></span>
                            <button type="button" class="btn-tool" title="Bullet List" onmousedown="event.preventDefault(); execMailCommand('insertUnorderedList');"><i class="ri-list-unordered fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Numbered List" onmousedown="event.preventDefault(); execMailCommand('insertOrderedList');"><i class="ri-list-ordered fs-16"></i></button>
                            <span class="border-end py-2 mx-1 opacity-25"></span>
                            <button type="button" class="btn-tool" title="Insert Link" onmousedown="event.preventDefault(); insertMailLink();"><i class="ri-links-line fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Remove Formatting" onmousedown="event.preventDefault(); execMailCommand('removeFormat');"><i class="ri-format-clear fs-16"></i></button>
                        </div>
                        <div id="composeEditor" class="mail-editor-content" contenteditable="true" data-placeholder="Write your email here..." style="min-height: 240px;"></div>
                        <textarea name="body" id="composeBody" class="d-none"></textarea>
                    </div>
                </div>
                <!-- Attachments -->
                <div class="mt-3">
                    <div class="gmail-attach-zone" onclick="this.querySelector('input').click()">
                        <i class="ri-attachment-2 me-1"></i> Attach files (click or drag & drop)
                        <input type="file" name="attachments[]" multiple onchange="showAttachNames(this, 'composeAttachList')">
                    </div>
                    <div id="composeAttachList" class="mt-2"></div>
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-3 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal" onclick="let ed = document.getElementById('composeEditor'); if(ed) ed.innerHTML=''; this.closest('form').reset();">Discard</button>
                <button type="submit" class="btn btn-primary px-5 rounded-pill fw-semibold shadow-sm"><i class="ri-send-plane-2-fill me-2"></i>Send</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     REPLY MODAL — Quotes original message, supports attachments
     ═══════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" method="post" action="email.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="to" id="replyTo">
            <input type="hidden" name="account" value="<?= (int) $accountId ?>" class="js-account-field">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <h5 class="modal-title fw-semibold fs-16 mb-0"><i class="ri-reply-fill me-2"></i>Reply to Conversation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <!-- Replying mailbox -->
                <div class="mb-2 border-bottom pb-2 d-flex align-items-center">
                    <span class="text-muted fs-13 me-2 flex-shrink-0">From</span>
                    <span class="fs-14 fw-medium text-dark text-truncate js-account-from"><?= e($mailAccounts[$accountId]['address'] ?? '') ?></span>
                </div>
                <!-- To (readonly) -->
                <div class="mb-2 border-bottom pb-2 d-flex align-items-center">
                    <span class="text-muted fs-13 me-2 flex-shrink-0">To</span>
                    <input type="text" id="replyToDisplay" class="form-control border-0 px-1 shadow-none fs-14 text-muted" readonly>
                    <span class="ccbcc-toggle ms-2 flex-shrink-0" onclick="toggleCcBcc('reply')">Cc/Bcc</span>
                </div>
                <!-- CC field (hidden by default) -->
                <div class="mb-2 border-bottom pb-2 d-none" id="replyCcRow">
                    <div class="d-flex align-items-center">
                        <span class="text-muted fs-13 me-2 flex-shrink-0">Cc</span>
                        <input type="text" name="cc" class="form-control border-0 px-1 shadow-none fs-14" placeholder="cc@example.com">
                    </div>
                </div>
                <!-- BCC field (hidden by default) -->
                <div class="mb-2 border-bottom pb-2 d-none" id="replyBccRow">
                    <div class="d-flex align-items-center">
                        <span class="text-muted fs-13 me-2 flex-shrink-0">Bcc</span>
                        <input type="text" name="bcc" class="form-control border-0 px-1 shadow-none fs-14" placeholder="bcc@example.com">
                    </div>
                </div>
                <!-- Subject -->
                <div class="mb-2 border-bottom pb-2">
                    <input type="text" name="subject" id="replySubject" class="form-control border-0 px-1 shadow-none fs-15 fw-bold text-muted" required readonly>
                </div>
                <!-- Reply body -->
                <div>
                    <div class="mail-editor-wrapper mt-2">
                        <div class="mail-editor-toolbar">
                            <button type="button" class="btn-tool" title="Bold" onmousedown="event.preventDefault(); execMailCommand('bold');"><i class="ri-bold fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Italic" onmousedown="event.preventDefault(); execMailCommand('italic');"><i class="ri-italic fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Underline" onmousedown="event.preventDefault(); execMailCommand('underline');"><i class="ri-underline fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Strikethrough" onmousedown="event.preventDefault(); execMailCommand('strikethrough');"><i class="ri-strikethrough fs-16"></i></button>
                            <span class="border-end py-2 mx-1 opacity-25"></span>
                            <button type="button" class="btn-tool" title="Bullet List" onmousedown="event.preventDefault(); execMailCommand('insertUnorderedList');"><i class="ri-list-unordered fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Numbered List" onmousedown="event.preventDefault(); execMailCommand('insertOrderedList');"><i class="ri-list-ordered fs-16"></i></button>
                            <span class="border-end py-2 mx-1 opacity-25"></span>
                            <button type="button" class="btn-tool" title="Insert Link" onmousedown="event.preventDefault(); insertMailLink();"><i class="ri-links-line fs-16"></i></button>
                            <button type="button" class="btn-tool" title="Remove Formatting" onmousedown="event.preventDefault(); execMailCommand('removeFormat');"><i class="ri-format-clear fs-16"></i></button>
                        </div>
                        <div id="replyEditor" class="mail-editor-content" contenteditable="true" data-placeholder="Type your response..." style="min-height: 180px;"></div>
                        <textarea name="body" id="replyBody" class="d-none"></textarea>
                    </div>
                </div>
                <!-- Quoted original (read-only visual context) -->
                <div class="mt-3 border-start border-3 border-primary-subtle ps-3" id="replyQuotedBlock" style="max-height: 200px; overflow-y: auto;">
                    <div class="fs-12 text-muted mb-1 fw-semibold" id="replyQuotedHeader">Original message</div>
                    <div class="fs-13 text-muted" id="replyQuotedContent"></div>
                </div>
                <!-- Attachments -->
                <div class="mt-3">
                    <div class="gmail-attach-zone" onclick="this.querySelector('input').click()">
                        <i class="ri-attachment-2 me-1"></i> Attach files
                        <input type="file" name="attachments[]" multiple onchange="showAttachNames(this, 'replyAttachList')">
                    </div>
                    <div id="replyAttachList" class="mt-2"></div>
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-3 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal" onclick="let ed = document.getElementById('replyEditor'); if(ed) ed.innerHTML=''; this.closest('form').reset();">Cancel</button>
                <button type="submit" class="btn btn-primary px-5 rounded-pill fw-semibold shadow-sm"><i class="ri-send-plane-2-fill me-2"></i>Send Reply</button>
            </div>
        </form>
    </div>
</div>

<!-- Gmail Inspired Floating Toast Banner -->
<div id="gmailToast" class="position-fixed bottom-0 start-0 m-4 shadow-lg rounded-3 py-3 px-4 align-items-center gap-3" style="display: none; background: #202124; color: #fff; min-width: 280px; max-width: 520px; border-radius: 8px; transition: all 0.2s ease; z-index: 1050;">
    <div id="gmailToastIcon"><span class="spinner-border spinner-border-sm text-white"></span></div>
    <span id="gmailToastMessage" class="fs-14 fw-medium flex-grow-1">Sending message...</span>
    <button type="button" class="btn-close btn-close-white ms-auto fs-12" style="display: none;" id="gmailToastClose" onclick="document.getElementById('gmailToast').style.display = 'none'"></button>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════
let allEmails = [];
let currentEmailId = null;
let currentFolder = 'inbox'; // 'inbox' | 'unread' | 'sent'
let currentMessageData = null; // Stored for quoting in reply

// Mailboxes configured on the Integrations page. Every request to the server
// carries the active one, so both mailboxes are usable without a page reload.
const MAIL_ACCOUNTS = <?= json_encode(array_values($mailAccounts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const ACCOUNT_STORAGE_KEY = 'webmail.account';
let currentAccount = <?= (int) $accountId ?>;

/** Build an email.php URL with the active mailbox attached. */
function mailUrl(params) {
    let qs = new URLSearchParams(params);
    qs.set('account', currentAccount);
    return 'email.php?' + qs.toString();
}

const avatarPalettes = [
    '#d93025', '#188038', '#1a73e8', '#e37400', '#8e24aa', 
    '#0097a7', '#3949ab', '#c2185b', '#00796b', '#5c6bc0'
];

// ═══════════════════════════════════════════════════════════════════
// GMAIL-STYLE DATE FORMATTING
// ═══════════════════════════════════════════════════════════════════
function formatGmailDate(dateStr) {
    try {
        let d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        let now = new Date();
        let diff = now - d;
        let dayMs = 86400000;
        
        // Today → show time only
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
        }
        // Yesterday
        let yesterday = new Date(now - dayMs);
        if (d.toDateString() === yesterday.toDateString()) {
            return 'Yesterday';
        }
        // This week (within 7 days)
        if (diff < 7 * dayMs) {
            return d.toLocaleDateString([], { weekday: 'short' });
        }
        // This year
        if (d.getFullYear() === now.getFullYear()) {
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
        }
        // Older
        return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (e) {
        return dateStr;
    }
}

// ═══════════════════════════════════════════════════════════════════
// TOAST NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════════
function showGmailToast(message, type = 'loading', duration = 0) {
    const toast = document.getElementById('gmailToast');
    const icon = document.getElementById('gmailToastIcon');
    const msgEl = document.getElementById('gmailToastMessage');
    const closeBtn = document.getElementById('gmailToastClose');
    
    toast.style.display = 'flex';
    msgEl.innerText = message;
    
    if (type === 'loading') {
        icon.innerHTML = '<span class="spinner-border spinner-border-sm text-white"></span>';
        closeBtn.style.display = 'none';
        toast.style.background = '#202124';
    } else if (type === 'success') {
        icon.innerHTML = '<i class="ri-checkbox-circle-fill text-success fs-20"></i>';
        closeBtn.style.display = 'block';
        toast.style.background = '#202124';
    } else if (type === 'error') {
        icon.innerHTML = '<i class="ri-error-warning-fill text-danger fs-20"></i>';
        closeBtn.style.display = 'block';
        toast.style.background = '#3a1e20';
    }
    
    if (duration > 0) {
        setTimeout(() => { toast.style.display = 'none'; }, duration);
    }
}

// ═══════════════════════════════════════════════════════════════════
// ASYNC FORM SUBMISSION (Gmail "Send and Forget" model)
// ═══════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#composeModal form, #replyModal form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Sync contenteditable editor to hidden textarea before submitting
            let editorEl = this.querySelector('.mail-editor-content');
            let hiddenTextarea = this.querySelector('textarea[name="body"]');
            if (editorEl && hiddenTextarea) {
                let html = editorEl.innerHTML.trim();
                if (html === '<br>' || html === '<div><br></div>' || html.replace(/<[^>]*>?/gm, '').trim() === '') {
                    showGmailToast('Please enter a message before sending.', 'error');
                    editorEl.focus();
                    return;
                }
                hiddenTextarea.value = html;
            }
            
            let formData = new FormData(this);
            formData.append('ajax', '1');
            // Send from whichever mailbox is selected right now, even if the modal
            // was opened before the switch.
            formData.set('account', currentAccount);

            // For reply: prepend quoted original into body
            if (this.closest('#replyModal') && currentMessageData) {
                let replyHtml = formData.get('body');
                let quotedSender = currentMessageData.fromName || currentMessageData.fromAddress;
                let quotedDate = currentMessageData.date || '';
                let quotedBody = currentMessageData.bodyPlain || (currentMessageData.body ? currentMessageData.body.replace(/<[^>]*>?/gm, '') : '') || '';
                let escapedBody = quotedBody.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                let separator = `<br><br><blockquote class="gmail_quote" style="margin: 12px 0 0 .8ex; border-left: 2px solid #ccc; padding-left: 1ex; color: #666;"><p>On ${quotedDate}, ${quotedSender} wrote:</p><div>${escapedBody}</div></blockquote>`;
                formData.set('body', replyHtml + separator);
            }
            
            let modalEl = this.closest('.modal');
            let modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modalInstance.hide();
            
            showGmailToast('Sending message...', 'loading');
            
            let submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            
            fetch('email.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(async res => {
                let text = await res.text();
                try {
                    return JSON.parse(text);
                } catch(err) {
                    throw new Error(text ? text.replace(/<[^>]*>?/gm, '').trim().substring(0, 200) : "Invalid response from server");
                }
            })
            .then(data => {
                if (submitBtn) submitBtn.disabled = false;
                if (data.success) {
                    form.reset();
                    let ed = form.querySelector('.mail-editor-content');
                    if (ed) ed.innerHTML = '';
                    let msg = data.message || 'Message queued for delivery.';
                    if (data.from && MAIL_ACCOUNTS.length > 1) msg = 'Sent from ' + data.from + '. ' + msg;
                    if (data.transport) msg += ' (via ' + data.transport + ')';
                    showGmailToast(msg, 'success', 8000);
                } else {
                    showGmailToast('Failed to send: ' + (data.error || 'Server error'), 'error', 0);
                    modalInstance.show();
                }
            })
            .catch(err => {
                if (submitBtn) submitBtn.disabled = false;
                showGmailToast('Error: ' + (err.message || 'Network error'), 'error', 0);
                modalInstance.show();
            });
        });
    });
});

// ═══════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════
function getAvatarColor(str) {
    let hash = 0;
    if (!str) return avatarPalettes[0];
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    return avatarPalettes[Math.abs(hash) % avatarPalettes.length];
}

function toggleCcBcc(prefix) {
    document.getElementById(prefix + 'CcRow').classList.toggle('d-none');
    document.getElementById(prefix + 'BccRow').classList.toggle('d-none');
}

function execMailCommand(cmd, arg = null) {
    document.execCommand(cmd, false, arg);
}
function insertMailLink() {
    let url = prompt('Enter link URL:', 'https://');
    if (url && url !== 'https://') {
        document.execCommand('createLink', false, url);
    }
}

// ═══════════════════════════════════════════════════════════════════
// ATTACHMENT PRESENTATION — shared by compose, the reader and threads
// ═══════════════════════════════════════════════════════════════════
const ATTACH_TYPES = [
    { ext: ['pdf'], icon: 'ri-file-pdf-2-fill', tone: 'att-pdf' },
    { ext: ['xls', 'xlsx', 'xlsm', 'csv', 'ods'], icon: 'ri-file-excel-2-fill', tone: 'att-sheet' },
    { ext: ['doc', 'docx', 'rtf', 'odt'], icon: 'ri-file-word-2-fill', tone: 'att-doc' },
    { ext: ['ppt', 'pptx', 'odp'], icon: 'ri-file-ppt-2-fill', tone: 'att-slides' },
    { ext: ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'], icon: 'ri-file-zip-fill', tone: 'att-archive' },
    { ext: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'heic', 'ico'], icon: 'ri-image-fill', tone: 'att-image' },
    { ext: ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv'], icon: 'ri-film-fill', tone: 'att-media' },
    { ext: ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'], icon: 'ri-music-2-fill', tone: 'att-media' },
    { ext: ['html', 'htm', 'xml', 'json', 'js', 'css', 'php', 'sql'], icon: 'ri-code-s-slash-fill', tone: 'att-code' },
    { ext: ['txt', 'log', 'md', 'eml'], icon: 'ri-file-text-fill', tone: 'att-text' },
];

/** Icon, tint class and short label for a filename — everything a card needs. */
function attachmentType(name) {
    let str = String(name || '');
    let dot = str.lastIndexOf('.');
    let ext = dot > 0 ? str.slice(dot + 1).toLowerCase().replace(/[^a-z0-9]/g, '') : '';
    let hit = ATTACH_TYPES.find(t => t.ext.includes(ext));
    return {
        icon: hit ? hit.icon : 'ri-file-3-fill',
        tone: hit ? hit.tone : 'att-file',
        label: ext && ext.length <= 4 ? ext.toUpperCase() : 'FILE',
    };
}

function formatFileSize(bytes) {
    if (!bytes || bytes < 0) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

/**
 * One download card. Built as DOM, never as an HTML string: attachment names
 * come from the message and must not be able to inject markup.
 */
function buildAttachmentCard(att) {
    let type = attachmentType(att.name);

    let a = document.createElement('a');
    a.className = 'mail-attach ' + type.tone;
    a.title = att.name || '';
    if (att.url) {
        a.href = att.url;
        a.target = '_blank';
        a.setAttribute('download', att.name || '');
        a.rel = 'noopener noreferrer';
    } else {
        a.href = '#';
        a.addEventListener('click', e => { e.preventDefault(); alert('Attachment file not available.'); });
    }

    let iconWrap = document.createElement('span');
    iconWrap.className = 'mail-attach-icon';
    let icon = document.createElement('i');
    icon.className = type.icon;
    iconWrap.appendChild(icon);

    let name = document.createElement('div');
    name.className = 'mail-attach-name';
    name.textContent = att.name || 'Attachment';
    let meta = document.createElement('div');
    meta.className = 'mail-attach-meta';
    let size = formatFileSize(att.size);
    meta.textContent = size ? type.label + ' · ' + size : type.label;

    let text = document.createElement('div');
    text.className = 'mail-attach-text';
    text.appendChild(name); text.appendChild(meta);

    let dl = document.createElement('i');
    dl.className = 'ri-download-2-line mail-attach-dl';

    a.appendChild(iconWrap); a.appendChild(text); a.appendChild(dl);
    return a;
}

function showAttachNames(input, listId) {
    let el = document.getElementById(listId);
    el.innerHTML = '';
    for (let f of input.files) {
        let type = attachmentType(f.name);
        let chip = document.createElement('span');
        chip.className = 'mail-attach-chip ' + type.tone;
        chip.title = f.name;

        let icon = document.createElement('i');
        icon.className = type.icon;
        let name = document.createElement('span');
        name.className = 'mail-attach-chip-name';
        name.textContent = f.name;
        let size = document.createElement('span');
        size.className = 'mail-attach-chip-size';
        size.textContent = formatFileSize(f.size);

        chip.appendChild(icon); chip.appendChild(name); chip.appendChild(size);
        el.appendChild(chip);
    }
}

// ═══════════════════════════════════════════════════════════════════
// MAILBOX SWITCHING
// ═══════════════════════════════════════════════════════════════════
function activeAccount() {
    return MAIL_ACCOUNTS.find(a => a.id === currentAccount) || MAIL_ACCOUNTS[0];
}

/** Paint the switcher, the compose "From" lines and the hidden form fields. */
function applyAccountToUi() {
    let acc = activeAccount();
    if (!acc) return;

    document.getElementById('accountSwitchLabel').innerText = acc.label;
    let dot = document.getElementById('accountSwitchDot');
    dot.innerText = (acc.label || acc.address).charAt(0).toUpperCase();
    dot.style.backgroundColor = getAvatarColor(acc.address);

    document.querySelectorAll('.gmail-account-option').forEach(el => {
        let isActive = Number(el.dataset.account) === currentAccount;
        el.classList.toggle('active', isActive);
        el.querySelector('.gmail-account-check').classList.toggle('invisible', !isActive);
    });

    document.querySelectorAll('.js-account-field').forEach(el => { el.value = currentAccount; });
    document.querySelectorAll('.js-account-from').forEach(el => { el.innerText = acc.address; });
}

function switchAccount(id) {
    id = Number(id);
    if (!MAIL_ACCOUNTS.some(a => a.id === id) || id === currentAccount) return;

    currentAccount = id;
    try { localStorage.setItem(ACCOUNT_STORAGE_KEY, String(id)); } catch (e) { /* private mode */ }

    // Nothing cached belongs to the new mailbox — drop it all and start at the Inbox.
    allEmails = [];
    currentEmailId = null;
    currentMessageData = null;
    document.getElementById('gmailSearchInput').value = '';
    document.getElementById('sentBadge').innerText = '—';

    applyAccountToUi();
    switchFolder('inbox');
}

// ═══════════════════════════════════════════════════════════════════
// FOLDER SWITCHING
// ═══════════════════════════════════════════════════════════════════
function switchFolder(folder) {
    currentFolder = folder;
    
    // Update sidebar active state
    document.querySelectorAll('.gmail-sidebar .gmail-nav-item').forEach(el => el.classList.remove('active'));
    let target = document.querySelector(`.gmail-nav-item[data-folder="${folder}"]`);
    if (target) target.classList.add('active');

    // Close email reader if open
    closeEmailReader();
    
    // Load appropriate data
    if (folder === 'sent') {
        loadSent();
    } else {
        loadInbox();
    }
}

function loadCurrentFolder() {
    if (currentFolder === 'sent') loadSent();
    else loadInbox();
}

// ═══════════════════════════════════════════════════════════════════
// INBOX LOADING
// ═══════════════════════════════════════════════════════════════════
function loadInbox() {
    document.getElementById('loadingIndicator').classList.remove('d-none');
    document.getElementById('emptySearchState').classList.add('d-none');
    document.getElementById('mail-list').innerHTML = '';
    document.getElementById('inboxBadge').innerText = '...';
    document.getElementById('unreadBadge').innerText = '...';
    document.getElementById('inboxStatusText').innerText = 'Syncing with mail server...';
    
    fetch(mailUrl({ action: 'inbox' }))
        .then(res => res.json())
        .then(data => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            
            if (data.error) {
                document.getElementById('mail-list').innerHTML = '<div class="p-5 text-center text-danger fw-medium"><i class="ri-error-warning-line fs-1 d-block mb-2"></i>' + data.error + '</div>';
                document.getElementById('inboxStatusText').innerText = 'Connection error';
                document.getElementById('inboxBadge').innerText = '0';
                document.getElementById('unreadBadge').innerText = '0';
                return;
            }
            
            allEmails = data;
            let unreadCount = data.filter(m => m.isUnread).length;
            
            document.getElementById('inboxBadge').innerText = data.length;
            document.getElementById('unreadBadge').innerText = unreadCount;
            document.getElementById('inboxStatusText').innerText = `${data.length} messages · ${unreadCount} unread`;
            
            renderEmails();
        })
        .catch(err => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            document.getElementById('mail-list').innerHTML = '<div class="p-5 text-center text-danger fw-medium"><i class="ri-wifi-off-line fs-1 d-block mb-2"></i>Failed to reach server.</div>';
            document.getElementById('inboxStatusText').innerText = 'Network error';
        });
}

// ═══════════════════════════════════════════════════════════════════
// SENT FOLDER LOADING
// ═══════════════════════════════════════════════════════════════════
function loadSent() {
    document.getElementById('loadingIndicator').classList.remove('d-none');
    document.getElementById('emptySearchState').classList.add('d-none');
    document.getElementById('mail-list').innerHTML = '';
    document.getElementById('inboxStatusText').innerText = 'Loading sent messages...';
    
    fetch(mailUrl({ action: 'sent' }))
        .then(res => res.json())
        .then(data => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            
            if (data.error) {
                document.getElementById('mail-list').innerHTML = '<div class="p-5 text-center text-danger fw-medium"><i class="ri-error-warning-line fs-1 d-block mb-2"></i>' + data.error + '</div>';
                document.getElementById('inboxStatusText').innerText = 'Could not load Sent folder';
                return;
            }
            
            allEmails = data;
            document.getElementById('sentBadge').innerText = data.length;
            document.getElementById('inboxStatusText').innerText = `${data.length} sent messages`;
            
            renderEmails();
        })
        .catch(err => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            document.getElementById('mail-list').innerHTML = '<div class="p-5 text-center text-danger fw-medium"><i class="ri-wifi-off-line fs-1 d-block mb-2"></i>Failed to reach server.</div>';
        });
}

// ═══════════════════════════════════════════════════════════════════
// RENDER EMAIL LIST
// ═══════════════════════════════════════════════════════════════════
function renderEmails() {
    let query = (document.getElementById('gmailSearchInput').value || '').trim().toLowerCase();
    let clearBtn = document.getElementById('clearSearchBtn');
    clearBtn.classList.toggle('d-none', query.length === 0);
    
    let filtered = allEmails.filter(mail => {
        if (currentFolder === 'unread' && !mail.isUnread) return false;
        if (query) {
            let s = (mail.subject || '').toLowerCase();
            let fn = (mail.fromName || '').toLowerCase();
            let fa = (mail.fromAddress || '').toLowerCase();
            let sn = (mail.snippet || '').toLowerCase();
            return s.includes(query) || fn.includes(query) || fa.includes(query) || sn.includes(query);
        }
        return true;
    });

    let listEl = document.getElementById('mail-list');
    let emptyState = document.getElementById('emptySearchState');
    let label = document.getElementById('listFilterLabel');
    let countEl = document.getElementById('inboxCount');

    let folderLabel = currentFolder === 'sent' ? 'Sent' : (currentFolder === 'unread' ? 'Unread' : 'Inbox');
    label.innerText = query ? `Searching ${folderLabel} for "${query}"` : `Showing ${folderLabel}`;
    countEl.innerText = `${filtered.length} of ${allEmails.length}`;

    if (filtered.length === 0) {
        listEl.innerHTML = '';
        emptyState.classList.remove('d-none');
        return;
    }
    emptyState.classList.add('d-none');

    let html = '';
    filtered.forEach(mail => {
        let unreadClass = mail.isUnread ? 'unread' : '';
        let cleanName = (mail.fromName && mail.fromName !== 'null' && mail.fromName.trim() !== '') ? mail.fromName : mail.fromAddress;
        let initial = cleanName.charAt(0).toUpperCase();
        let bg = getAvatarColor(cleanName);
        let attachIcon = mail.hasAttachments ? '<i class="ri-attachment-2 gmail-attach-icon"></i>' : '';
        let isSent = mail.isSent || currentFolder === 'sent';
        let readFn = isSent ? `readSentEmail(${mail.id})` : `readEmail(${mail.id})`;
        let dateFormatted = formatGmailDate(mail.date);
        
        html += `
        <li class="gmail-row ${unreadClass}" onclick="${readFn}">
            <div class="gmail-avatar" style="background-color: ${bg};">${initial}</div>
            <div class="gmail-sender">
                <span class="text-truncate d-block">${cleanName}</span>
            </div>
            <div class="gmail-subject-snippet">
                ${attachIcon}
                <span class="gmail-subject fw-medium">${mail.subject || 'No Subject'}</span>
                <span class="gmail-snippet">— ${mail.snippet || ''}</span>
            </div>
            <div class="gmail-meta-right">
                <span class="gmail-date">${dateFormatted}</span>
                <div class="gmail-hover-actions">
                    <button type="button" class="gmail-icon-btn" title="Open" onclick="event.stopPropagation(); ${readFn}">
                        <i class="ri-mail-open-line fs-16"></i>
                    </button>
                    ${!isSent ? `<button type="button" class="gmail-icon-btn delete-btn" title="Delete" onclick="event.stopPropagation(); deleteFromList(${mail.id})">
                        <i class="ri-delete-bin-line fs-16"></i>
                    </button>` : ''}
                </div>
            </div>
        </li>
        `;
    });
    listEl.innerHTML = html;
}

function filterEmails() { renderEmails(); }

function clearSearch() {
    document.getElementById('gmailSearchInput').value = '';
    if (currentFolder === 'unread') switchFolder('inbox');
    else renderEmails();
}

// ═══════════════════════════════════════════════════════════════════
// EMAIL READING
// ═══════════════════════════════════════════════════════════════════
function readEmail(id) {
    currentEmailId = id;
    showEmailReader();
    
    document.getElementById('detailBody').innerHTML = '<div class="text-center my-5 py-5"><div class="spinner-border text-primary my-2" role="status"></div><div class="text-muted fs-14 mt-2">Opening conversation...</div></div>';
    if (document.getElementById('detailAttachmentsContainer')) {
        document.getElementById('detailAttachmentsContainer').classList.add('d-none');
    }
    
    fetch(mailUrl({ action: 'thread', id: id, folder: 'INBOX' }))
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger my-4">' + data.error + '</div>';
                return;
            }

            populateThreadReader(data, id);

            // Mark as read in local cache and immediately update UI
            if (Array.isArray(data)) {
                data.forEach(msg => {
                    let cached = allEmails.find(e => e.id == msg.id);
                    if (cached && cached.isUnread) {
                        cached.isUnread = 0;
                    }
                });
            }
            let cachedAnchor = allEmails.find(e => e.id == id);
            if (cachedAnchor && cachedAnchor.isUnread) {
                cachedAnchor.isUnread = 0;
            }
            let unreadCount = allEmails.filter(m => m.isUnread).length;
            if (document.getElementById('unreadBadge')) {
                document.getElementById('unreadBadge').innerText = unreadCount;
            }
            if (document.getElementById('inboxStatusText') && currentFolder === 'inbox') {
                document.getElementById('inboxStatusText').innerText = `${allEmails.length} messages · ${unreadCount} unread`;
            }
            renderEmails();
        })
        .catch(err => {
            document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger my-4">Network error communicating with mail server.</div>';
        });
}

function readSentEmail(id) {
    currentEmailId = id;
    showEmailReader();
    
    document.getElementById('detailBody').innerHTML = '<div class="text-center my-5 py-5"><div class="spinner-border text-primary my-2" role="status"></div><div class="text-muted fs-14 mt-2">Opening sent message...</div></div>';
    
    let sentFolder = 'Sent'; // Default; the server auto-detects
    fetch(mailUrl({ action: 'thread', id: id, folder: sentFolder }))
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger my-4">' + data.error + '</div>';
                return;
            }
            populateThreadReader(data, id);
        })
        .catch(err => {
            document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger my-4">Network error.</div>';
        });
}

// ═══════════════════════════════════════════════════════════════════
// THREADED CONVERSATION READER
// ═══════════════════════════════════════════════════════════════════
function buildQuoteToggle(quotedHtml) {
    let toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'mail-quote-toggle';
    toggle.setAttribute('aria-label', 'Show trimmed content');
    toggle.innerHTML = '<i class="ri-more-fill"></i>';

    let quote = document.createElement('blockquote');
    quote.className = 'mail-quote d-none';
    quote.innerHTML = quotedHtml;

    toggle.addEventListener('click', function () {
        let hidden = quote.classList.toggle('d-none');
        toggle.classList.toggle('active', !hidden);
    });

    let wrap = document.createElement('div');
    wrap.className = 'mt-3';
    wrap.appendChild(toggle);
    wrap.appendChild(quote);
    return wrap;
}

function buildAttachmentRow(attachments) {
    let row = document.createElement('div');
    row.className = 'd-flex flex-wrap gap-2 mt-3 pt-3 border-top';
    attachments.forEach(att => row.appendChild(buildAttachmentCard(att)));
    return row;
}

function buildThreadMessage(msg, expanded) {
    let hasName = (msg.fromName && msg.fromName !== 'null' && msg.fromName.trim() !== '' && msg.fromName !== msg.fromAddress);
    let sender = hasName ? msg.fromName : (msg.fromAddress || 'Unknown');

    let card = document.createElement('div');
    card.className = 'thread-message' + (msg.isSent ? ' is-sent' : '');

    // ── header (click to collapse/expand)
    let head = document.createElement('div');
    head.className = 'thread-message-head';

    let avatar = document.createElement('div');
    avatar.className = 'gmail-avatar fs-14';
    avatar.style.cssText = 'width:36px;height:36px;flex-shrink:0;';
    avatar.style.backgroundColor = getAvatarColor(sender);
    avatar.textContent = sender.charAt(0).toUpperCase();

    let who = document.createElement('div');
    who.className = 'flex-grow-1 overflow-hidden';
    let nameEl = document.createElement('div');
    nameEl.className = 'fw-bold fs-14 text-dark text-truncate';
    nameEl.textContent = sender + (msg.isSent ? ' (you)' : '');
    let addrEl = document.createElement('div');
    addrEl.className = 'text-muted fs-12 text-truncate thread-preview';
    who.appendChild(nameEl); who.appendChild(addrEl);

    let dateEl = document.createElement('div');
    dateEl.className = 'text-muted fs-12 flex-shrink-0 ms-2';
    dateEl.textContent = msg.date || '';

    head.appendChild(avatar); head.appendChild(who); head.appendChild(dateEl);

    // ── body
    let bodyWrap = document.createElement('div');
    bodyWrap.className = 'thread-message-body';
    let body = document.createElement('div');
    body.className = 'gmail-reading-body pt-3';
    body.innerHTML = msg.body || '';
    bodyWrap.appendChild(body);
    if (msg.quoted) { bodyWrap.appendChild(buildQuoteToggle(msg.quoted)); }
    if (msg.attachments && msg.attachments.length) { bodyWrap.appendChild(buildAttachmentRow(msg.attachments)); }

    card.appendChild(head); card.appendChild(bodyWrap);

    // Collapsed messages show a one-line preview instead of the full body.
    function apply(open) {
        bodyWrap.classList.toggle('d-none', !open);
        addrEl.textContent = open
            ? (hasName ? msg.fromAddress : '')
            : (body.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 120);
    }
    apply(expanded);
    head.addEventListener('click', () => apply(bodyWrap.classList.contains('d-none')));

    return card;
}

function populateThreadReader(thread, anchorId) {
    if (!Array.isArray(thread) || thread.length === 0) {
        document.getElementById('detailBody').innerHTML = '<div class="alert alert-warning my-4">Conversation could not be loaded.</div>';
        return;
    }

    // Reply defaults come from the newest message that is not one of ours; failing
    // that, the newest message overall.
    let newest = thread[thread.length - 1];
    let replyTarget = [...thread].reverse().find(m => !m.isSent) || newest;
    currentMessageData = replyTarget;
    currentEmailId = anchorId;

    document.getElementById('detailSubject').innerText = newest.subject || 'No Subject';

    // The single-message header and attachment strip are replaced by per-message ones.
    let singleHeader = document.getElementById('detailSingleHeader');
    if (singleHeader) { singleHeader.classList.add('d-none'); }
    let attachmentsContainer = document.getElementById('detailAttachmentsContainer');
    if (attachmentsContainer) { attachmentsContainer.classList.add('d-none'); }

    let host = document.getElementById('detailBody');
    host.innerHTML = '';

    if (thread.length > 1) {
        let count = document.createElement('div');
        count.className = 'text-muted fs-13 fw-medium pb-2';
        count.textContent = thread.length + ' messages in this conversation';
        host.appendChild(count);
    }

    thread.forEach((msg, i) => {
        // Newest expanded, plus whichever message was clicked. Older ones collapse.
        let expanded = (i === thread.length - 1) || msg.isAnchor;
        host.appendChild(buildThreadMessage(msg, expanded));
    });

    // Quick-reply footer targets the person we would actually be replying to.
    let hasName = (replyTarget.fromName && replyTarget.fromName !== 'null' && replyTarget.fromName.trim() !== '' && replyTarget.fromName !== replyTarget.fromAddress);
    let replyName = hasName ? replyTarget.fromName : replyTarget.fromAddress;
    document.getElementById('quickReplySenderName').innerText = replyName;
    document.getElementById('replyTo').value = replyTarget.fromAddress || '';
    document.getElementById('replyToDisplay').value = replyTarget.fromAddress || '';
    document.getElementById('replySubject').value = (newest.subject || '').startsWith('Re:')
        ? newest.subject
        : 'Re: ' + (newest.subject || '');

    let quotedContent = document.getElementById('replyQuotedContent');
    let quotedHeader = document.getElementById('replyQuotedHeader');
    if (quotedContent && quotedHeader) {
        quotedHeader.innerText = `On ${replyTarget.date}, ${replyName} wrote:`;
        quotedContent.innerText = replyTarget.bodyPlain || '';
    }
    let rEd = document.getElementById('replyEditor');
    if (rEd) rEd.innerHTML = '';
}

function populateEmailReader(data) {
    document.getElementById('detailSubject').innerText = data.subject || 'No Subject';
    
    let hasValidName = (data.fromName && data.fromName !== 'null' && data.fromName.trim() !== '' && data.fromName !== data.fromAddress);
    let displaySender = hasValidName ? data.fromName : data.fromAddress;
    let bg = getAvatarColor(displaySender);
    
    document.getElementById('detailFromName').innerText = displaySender;
    document.getElementById('detailFromAddress').innerText = hasValidName ? `<${data.fromAddress}>` : '';
    let avatarEl = document.getElementById('detailAvatar');
    avatarEl.innerText = displaySender.charAt(0).toUpperCase();
    avatarEl.style.backgroundColor = bg;
    
    document.getElementById('detailDate').innerText = data.date;

    // Body, with the quoted reply chain hidden behind a "•••" toggle the way a mail
    // client does it — the server has already split and sanitised the two halves.
    let bodyEl = document.getElementById('detailBody');
    bodyEl.innerHTML = data.body || '';
    if (data.quoted) {
        let toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'mail-quote-toggle';
        toggle.setAttribute('aria-label', 'Show trimmed content');
        toggle.innerHTML = '<i class="ri-more-fill"></i>';

        let quote = document.createElement('blockquote');
        quote.className = 'mail-quote d-none';
        quote.innerHTML = data.quoted;

        toggle.addEventListener('click', function () {
            let hidden = quote.classList.toggle('d-none');
            toggle.classList.toggle('active', !hidden);
        });

        let wrap = document.createElement('div');
        wrap.className = 'mt-3';
        wrap.appendChild(toggle);
        wrap.appendChild(quote);
        bodyEl.appendChild(wrap);
    }

    document.getElementById('quickReplySenderName').innerText = displaySender;
    
    // Attachments
    let attachmentsContainer = document.getElementById('detailAttachmentsContainer');
    let attachmentsList = document.getElementById('detailAttachments');
    let attachmentsCount = document.getElementById('attachmentsCount');
    if (attachmentsContainer && attachmentsList && attachmentsCount) {
        attachmentsList.innerHTML = '';
        if (data.attachments && data.attachments.length > 0) {
            attachmentsCount.innerText = data.attachments.length;
            data.attachments.forEach(att => attachmentsList.appendChild(buildAttachmentCard(att)));
            attachmentsContainer.classList.remove('d-none');
        } else {
            attachmentsContainer.classList.add('d-none');
        }
    }
    
    // Set up reply defaults
    document.getElementById('replyTo').value = data.fromAddress;
    document.getElementById('replyToDisplay').value = data.fromAddress;
    document.getElementById('replySubject').value = (data.subject || '').startsWith('Re:') ? data.subject : 'Re: ' + (data.subject || '');
    
    // Populate quoted content in reply modal
    let quotedContent = document.getElementById('replyQuotedContent');
    let quotedHeader = document.getElementById('replyQuotedHeader');
    if (quotedContent && quotedHeader) {
        quotedHeader.innerText = `On ${data.date}, ${displaySender} wrote:`;
        quotedContent.innerText = data.bodyPlain || (data.body ? data.body.replace(/<[^>]*>?/gm, '') : '');
    }
    let rEd = document.getElementById('replyEditor');
    if (rEd) rEd.innerHTML = '';
}

function showEmailReader() {
    document.getElementById('mailListView').classList.add('d-none');
    document.getElementById('mailListView').classList.remove('d-flex');
    document.getElementById('emailDetailsView').classList.remove('d-none');
    document.getElementById('emailDetailsView').classList.add('d-flex');
}

function closeEmailReader() {
    document.getElementById('emailDetailsView').classList.add('d-none');
    document.getElementById('emailDetailsView').classList.remove('d-flex');
    document.getElementById('mailListView').classList.remove('d-none');
    document.getElementById('mailListView').classList.add('d-flex');
    currentEmailId = null;
    currentMessageData = null;
    renderEmails();
}

// ═══════════════════════════════════════════════════════════════════
// DELETE & MARK UNREAD
// ═══════════════════════════════════════════════════════════════════
function deleteFromList(id) {
    if (!confirm('Delete this message from the server?')) return;
    
    allEmails = allEmails.filter(m => m.id !== id);
    renderEmails();
    document.getElementById('inboxBadge').innerText = allEmails.length;
    document.getElementById('unreadBadge').innerText = allEmails.filter(m => m.isUnread).length;
    
    fetch(mailUrl({ action: 'delete', id: id }))
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showGmailToast('Failed to delete message on server.', 'error', 5000);
                loadCurrentFolder();
            }
        })
        .catch(() => { loadCurrentFolder(); });
}

// Close reader
document.getElementById('closeReadEmail').addEventListener('click', closeEmailReader);

// Delete from reader
document.getElementById('deleteEmailBtn').addEventListener('click', function() {
    if (!currentEmailId || !confirm('Permanently delete this email?')) return;
    
    let btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
    
    fetch(mailUrl({ action: 'delete', id: currentEmailId }))
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ri-delete-bin-line fs-18"></i>';
            if (data.success) {
                allEmails = allEmails.filter(m => m.id !== currentEmailId);
                document.getElementById('inboxBadge').innerText = allEmails.length;
                document.getElementById('unreadBadge').innerText = allEmails.filter(m => m.isUnread).length;
                closeEmailReader();
                showGmailToast('Message deleted.', 'success', 4000);
            } else {
                showGmailToast('Failed to delete: ' + (data.error || 'Unknown error'), 'error', 0);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ri-delete-bin-line fs-18"></i>';
            showGmailToast('Network error while deleting.', 'error', 5000);
        });
});

// Mark as unread from reader
document.getElementById('markUnreadBtn').addEventListener('click', function() {
    if (!currentEmailId) return;
    
    fetch(mailUrl({ action: 'mark_unread', id: currentEmailId }))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                let cached = allEmails.find(e => e.id == currentEmailId);
                if (cached) cached.isUnread = 1;
                document.getElementById('unreadBadge').innerText = allEmails.filter(m => m.isUnread).length;
                closeEmailReader();
                showGmailToast('Marked as unread.', 'success', 3000);
            }
        });
});

// ═══════════════════════════════════════════════════════════════════
// BOOT
// ═══════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {
    // ?account=N wins (shareable link), otherwise the last mailbox this browser used.
    let requested = new URLSearchParams(window.location.search).get('account');
    if (requested === null) {
        try { requested = localStorage.getItem(ACCOUNT_STORAGE_KEY); } catch (e) { requested = null; }
    }
    if (requested !== null && MAIL_ACCOUNTS.some(a => a.id === Number(requested))) {
        currentAccount = Number(requested);
    }

    applyAccountToUi();
    loadInbox();
});
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
