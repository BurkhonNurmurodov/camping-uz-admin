<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';
require_once __DIR__ . '/app/MailClient.php';

$mailClient = new \App\MailClient();

// Handle AJAX requests
if (isset($_GET['action'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // Release session lock instantly so IMAP retries never freeze other admin tabs
    }
    header('Content-Type: application/json');
    try {
        if ($_GET['action'] === 'inbox') {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            echo json_encode($mailClient->getInbox($page));
        } elseif ($_GET['action'] === 'message' && isset($_GET['id'])) {
            echo json_encode($mailClient->getMessage($_GET['id']));
        } elseif ($_GET['action'] === 'delete' && isset($_GET['id'])) {
            echo json_encode(['success' => $mailClient->deleteMessage($_GET['id'])]);
        }
    } catch (\Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    csrf_verify();
    $to = input('to', '');
    $subject = input('subject', '');
    $body = input('body', '');
    
    $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    try {
        if ($mailClient->sendMessage($to, $subject, $body)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Message sent successfully!']);
                exit;
            }
            flash('success', 'Email sent successfully.');
        }
    } catch (\Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        flash('error', 'Failed to send email: ' . $e->getMessage());
    }
    redirect('email.php');
}

$page = ['title' => 'Email', 'section' => 'Applications', 'active' => 'email'];
require __DIR__ . '/partials/head.php';
?>

<!-- Gmail Inspired Webmail CSS -->
<style>
    :root {
        --gmail-bg: #f6f8fc;
        --gmail-card-bg: #ffffff;
        --gmail-border: #e1e5ea;
        --gmail-hover: #f2f6fc;
        --gmail-unread-bg: #ffffff;
        --gmail-read-bg: #f8fafe;
        --gmail-primary: #0b57d0;
        --gmail-compose-bg: #c2e7ff;
        --gmail-compose-hover: #b1dcfb;
        --gmail-compose-text: #001d35;
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
        background: #eaf1fb;
        border-radius: 24px;
        padding: 8px 18px;
        display: flex;
        align-items: center;
        transition: background-color 0.2s, box-shadow 0.2s;
        border: 1px solid transparent;
    }
    .gmail-search-box:focus-within {
        background: #ffffff;
        box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15);
        border-color: #e0e0e0;
    }
    .gmail-search-input {
        border: none;
        background: transparent;
        width: 100%;
        outline: none;
        font-size: 15px;
        color: #1f1f1f;
        padding-left: 10px;
    }

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
        color: #444746;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        border-radius: 0 20px 20px 0;
        margin-right: 16px;
        transition: background-color 0.15s, font-weight 0.15s;
    }
    .gmail-nav-item:hover {
        background: rgba(0, 0, 0, 0.05);
        color: #1f1f1f;
    }
    .gmail-nav-item.active {
        background: #d3e3fd;
        color: #041e49;
        font-weight: 700;
    }
    .gmail-nav-item.active i {
        color: #041e49 !important;
    }

    /* Message Box & Rows */
    .gmail-main-box {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        background: #ffffff;
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
        border-bottom: 1px solid #f1f3f4;
        cursor: pointer;
        transition: box-shadow 0.1s, background-color 0.15s, border-color 0.15s;
        position: relative;
        background: var(--gmail-read-bg);
        color: #5f6368;
        font-size: 14px;
    }
    .gmail-row.unread {
        background: var(--gmail-unread-bg);
        font-weight: 700;
        color: #202124;
    }
    .gmail-row:hover {
        background-color: var(--gmail-hover);
        box-shadow: inset 1px 0 0 #dadce0, inset -1px 0 0 #dadce0, 0 1px 2px 0 rgba(60,64,67,0.1);
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
        color: #5f6368;
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
        color: #444746;
        transition: background-color 0.15s;
    }
    .gmail-icon-btn:hover {
        background-color: rgba(68, 71, 70, 0.1);
        color: #1f1f1f;
    }
    .gmail-icon-btn.delete-btn:hover {
        background-color: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }

    /* Avatar Tag */
    .gmail-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        color: #fff;
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
        background: #ffffff;
    }
    .gmail-reading-header {
        padding: 20px 28px 16px 28px;
        border-bottom: 1px solid #f1f3f4;
    }
    .gmail-subject-title {
        font-size: 22px;
        font-weight: 400;
        color: #1f1f1f;
        margin-bottom: 16px;
        word-break: break-word;
    }
    .gmail-reading-body {
        padding: 24px 28px;
        font-size: 15px;
        line-height: 1.65;
        color: #202124;
        overflow-y: auto;
        flex-grow: 1;
    }
    .gmail-quick-reply-box {
        margin: 20px 28px 28px 28px;
        border: 1px solid #dadce0;
        border-radius: 24px;
        padding: 16px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fafafc;
        cursor: pointer;
        transition: box-shadow 0.15s, border-color 0.15s;
    }
    .gmail-quick-reply-box:hover {
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border-color: #c0c4c9;
        background: #ffffff;
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
</style>

<div class="gmail-app-wrapper mb-4">
    <!-- Top Search & Controls Header -->
    <div class="gmail-header-bar">
        <div class="d-flex align-items-center gap-2">
            <i class="ri-mail-star-fill fs-24 text-primary"></i>
            <h5 class="mb-0 fw-bold fs-18 text-dark d-none d-sm-inline">Webmail</h5>
        </div>
        
        <div class="gmail-search-box">
            <i class="ri-search-line fs-18 text-muted"></i>
            <input type="text" id="gmailSearchInput" class="gmail-search-input" placeholder="Search mail, senders, or subjects..." oninput="filterEmails()">
            <button type="button" class="btn-close d-none fs-12 ms-2" id="clearSearchBtn" onclick="clearSearch()" title="Clear search"></button>
        </div>

        <div class="d-flex align-items-center gap-1">
            <button type="button" class="gmail-icon-btn" onclick="loadInbox()" title="Refresh Inbox">
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
                <a href="#" class="gmail-nav-item active" onclick="clearSearch(); return false;">
                    <span class="d-flex align-items-center gap-3">
                        <i class="ri-inbox-archive-fill fs-18"></i>
                        <span>Inbox</span>
                    </span>
                    <span class="badge bg-primary rounded-pill fs-11 ms-2" id="inboxBadge">...</span>
                </a>
                <a href="#" class="gmail-nav-item" onclick="filterByUnread(); return false;" title="Filter unread messages">
                    <span class="d-flex align-items-center gap-3">
                        <i class="ri-mail-unread-line fs-18 text-muted"></i>
                        <span>Unread</span>
                    </span>
                    <span class="badge bg-secondary-subtle text-dark rounded-pill fs-11 ms-2" id="unreadBadge">0</span>
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
                        <h6 class="text-dark fw-semibold">No matching messages in your inbox</h6>
                        <p class="text-muted fs-13 mb-3">Try searching for a different keyword or check your spelling.</p>
                        <button class="btn btn-sm btn-outline-secondary px-4 rounded-pill" onclick="clearSearch()">Reset Search Filter</button>
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
                            <button type="button" class="gmail-icon-btn me-2" id="closeReadEmail" title="Back to Inbox">
                                <i class="ri-arrow-left-line fs-20"></i>
                            </button>
                            <button type="button" class="gmail-icon-btn delete-btn" id="deleteEmailBtn" title="Delete conversation">
                                <i class="ri-delete-bin-line fs-18"></i>
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
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
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

<!-- Compose Modal (Styled like Gmail Floating Window) -->
<div class="modal fade" id="composeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" method="post" action="email.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <h5 class="modal-title fw-semibold fs-16 mb-0"><i class="ri-mail-send-line me-2 text-info"></i>New Message</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <div class="mb-3 border-bottom pb-2">
                    <input type="email" name="to" class="form-control border-0 px-1 shadow-none fs-15 fw-medium" placeholder="Recipients (e.g. client@example.com)" required>
                </div>
                <div class="mb-3 border-bottom pb-2">
                    <input type="text" name="subject" class="form-control border-0 px-1 shadow-none fs-15 fw-bold" placeholder="Subject" required>
                </div>
                <div>
                    <textarea name="body" class="form-control border-0 px-1 shadow-none fs-15" rows="12" placeholder="Write your email here..." required style="resize: none; line-height: 1.6;"></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-3 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Discard</button>
                <button type="submit" class="btn btn-primary px-5 rounded-pill fw-semibold shadow-sm"><i class="ri-send-plane-2-fill me-2"></i>Send</button>
            </div>
        </form>
    </div>
</div>

<!-- Reply Modal -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" method="post" action="email.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="to" id="replyTo">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <h5 class="modal-title fw-semibold fs-16 mb-0"><i class="ri-reply-fill me-2"></i>Reply to Conversation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <div class="mb-3 border-bottom pb-2">
                    <input type="text" name="subject" id="replySubject" class="form-control border-0 px-1 shadow-none fs-15 fw-bold text-muted" required readonly>
                </div>
                <div>
                    <textarea name="body" class="form-control border-0 px-1 shadow-none fs-15" rows="10" placeholder="Type your response..." required style="resize: none; line-height: 1.6;"></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light px-4 py-3 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-5 rounded-pill fw-semibold shadow-sm"><i class="ri-send-plane-2-fill me-2"></i>Send Reply</button>
            </div>
        </form>
    </div>
</div>

<!-- Gmail Inspired Floating Toast Banner -->
<div id="gmailToast" class="position-fixed bottom-0 start-0 m-4 shadow-lg rounded-3 py-3 px-4 d-flex align-items-center gap-3 d-none z-3" style="background: #202124; color: #fff; min-width: 280px; max-width: 520px; border-radius: 8px; transition: all 0.2s ease;">
    <div id="gmailToastIcon"><span class="spinner-border spinner-border-sm text-white"></span></div>
    <span id="gmailToastMessage" class="fs-14 fw-medium flex-grow-1">Sending message...</span>
    <button type="button" class="btn-close btn-close-white ms-auto fs-12 d-none" id="gmailToastClose" onclick="document.getElementById('gmailToast').classList.add('d-none')"></button>
</div>

<script>
// Gmail-style Asynchronous Sending ("Send and forget" UI model)
function showGmailToast(message, type = 'loading', duration = 0) {
    const toast = document.getElementById('gmailToast');
    const icon = document.getElementById('gmailToastIcon');
    const msgEl = document.getElementById('gmailToastMessage');
    const closeBtn = document.getElementById('gmailToastClose');
    
    toast.classList.remove('d-none');
    msgEl.innerText = message;
    
    if (type === 'loading') {
        icon.innerHTML = '<span class="spinner-border spinner-border-sm text-white"></span>';
        closeBtn.classList.add('d-none');
        toast.style.background = '#202124';
    } else if (type === 'success') {
        icon.innerHTML = '<i class="ri-checkbox-circle-fill text-success fs-20"></i>';
        closeBtn.classList.remove('d-none');
        toast.style.background = '#202124';
    } else if (type === 'error') {
        icon.innerHTML = '<i class="ri-error-warning-fill text-danger fs-20"></i>';
        closeBtn.classList.remove('d-none');
        toast.style.background = '#3a1e20'; // Subtle dark red tone
    }
    
    if (duration > 0) {
        setTimeout(() => {
            toast.classList.add('d-none');
        }, duration);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#composeModal form, #replyModal form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            let formData = new FormData(this);
            formData.append('ajax', '1');
            
            // Optimistic UI: Immediately dismiss modal so user isn't frozen waiting for SMTP networks
            let modalEl = this.closest('.modal');
            let modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modalInstance.hide();
            
            // Display floating status banner
            showGmailToast('Sending message...', 'loading');
            
            let submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            
            fetch('email.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (submitBtn) submitBtn.disabled = false;
                if (data.success) {
                    form.reset();
                    showGmailToast(data.message || 'Message sent successfully!', 'success', 6000);
                } else {
                    showGmailToast('Failed to send: ' + (data.error || 'Server error'), 'error', 0);
                    // Automatically reopen compose modal so drafted text is not lost
                    modalInstance.show();
                }
            })
            .catch(err => {
                if (submitBtn) submitBtn.disabled = false;
                showGmailToast('Network communication error with mail server.', 'error', 0);
                modalInstance.show();
            });
        });
    });
});

let allEmails = [];
let currentEmailId = null;
let currentFilter = 'all'; // 'all' | 'unread'

// Google Material styling curated pastel avatar palette
const avatarPalettes = [
    '#d93025', '#188038', '#1a73e8', '#e37400', '#8e24aa', 
    '#0097a7', '#3949ab', '#c2185b', '#00796b', '#5c6bc0'
];

function getAvatarColor(str) {
    let hash = 0;
    if (!str) return avatarPalettes[0];
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    return avatarPalettes[Math.abs(hash) % avatarPalettes.length];
}

function loadInbox() {
    document.getElementById('loadingIndicator').classList.remove('d-none');
    document.getElementById('emptySearchState').classList.add('d-none');
    document.getElementById('mail-list').innerHTML = '';
    document.getElementById('inboxBadge').innerText = '...';
    document.getElementById('unreadBadge').innerText = '...';
    document.getElementById('inboxStatusText').innerText = 'Syncing with mail daemon...';
    
    fetch('email.php?action=inbox')
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
            document.getElementById('inboxStatusText').innerText = `${data.length} messages in vault`;
            
            renderEmails();
        })
        .catch(err => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            document.getElementById('mail-list').innerHTML = '<div class="p-5 text-center text-danger fw-medium"><i class="ri-wifi-off-line fs-1 d-block mb-2"></i>Failed to reach server. Please check your connection.</div>';
            document.getElementById('inboxStatusText').innerText = 'Network error';
        });
}

function renderEmails() {
    let query = (document.getElementById('gmailSearchInput').value || '').trim().toLowerCase();
    let clearBtn = document.getElementById('clearSearchBtn');
    if (query.length > 0) {
        clearBtn.classList.remove('d-none');
    } else {
        clearBtn.classList.add('d-none');
    }
    
    let filtered = allEmails.filter(mail => {
        // Filter by tab type (unread vs all)
        if (currentFilter === 'unread' && !mail.isUnread) {
            return false;
        }
        // Filter by query
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

    if (currentFilter === 'unread') {
        label.innerText = query ? `Searching Unread for "${query}"` : 'Showing Unread Messages';
    } else {
        label.innerText = query ? `Searching Inbox for "${query}"` : 'Showing All Messages';
    }
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
        
        html += `
        <li class="gmail-row ${unreadClass}" onclick="readEmail(${mail.id})">
            <div class="gmail-avatar" style="background-color: ${bg};">${initial}</div>
            <div class="gmail-sender">
                <span class="text-truncate d-block">${cleanName}</span>
            </div>
            <div class="gmail-subject-snippet">
                <span class="gmail-subject fw-medium">${mail.subject || 'No Subject'}</span>
                <span class="gmail-snippet">- ${mail.snippet || ''}</span>
            </div>
            <div class="gmail-meta-right">
                <span class="gmail-date">${mail.date}</span>
                <div class="gmail-hover-actions">
                    <button type="button" class="gmail-icon-btn" title="Open Conversation" onclick="event.stopPropagation(); readEmail(${mail.id})">
                        <i class="ri-mail-open-line fs-16"></i>
                    </button>
                    <button type="button" class="gmail-icon-btn delete-btn" title="Delete from Mailbox" onclick="event.stopPropagation(); deleteFromList(${mail.id})">
                        <i class="ri-delete-bin-line fs-16"></i>
                    </button>
                </div>
            </div>
        </li>
        `;
    });
    listEl.innerHTML = html;
}

function filterEmails() {
    renderEmails();
}

function clearSearch() {
    document.getElementById('gmailSearchInput').value = '';
    currentFilter = 'all';
    updateNavStates('all');
    renderEmails();
}

function filterByUnread() {
    document.getElementById('gmailSearchInput').value = '';
    currentFilter = 'unread';
    updateNavStates('unread');
    renderEmails();
}

function updateNavStates(mode) {
    let items = document.querySelectorAll('.gmail-sidebar .gmail-nav-item');
    items.forEach((el, idx) => {
        if (mode === 'all' && idx === 0) el.classList.add('active');
        else if (mode === 'unread' && idx === 1) el.classList.add('active');
        else el.classList.remove('active');
    });
}

function readEmail(id) {
    currentEmailId = id;
    
    // Switch views seamlessly
    document.getElementById('mailListView').classList.add('d-none');
    document.getElementById('mailListView').classList.remove('d-flex');
    document.getElementById('emailDetailsView').classList.remove('d-none');
    document.getElementById('emailDetailsView').classList.add('d-flex');
    
    document.getElementById('detailBody').innerHTML = '<div class="text-center my-5 py-5"><div class="spinner-border text-primary my-2" role="status"></div><div class="text-muted fs-14 mt-2">Opening conversation...</div></div>';
    if (document.getElementById('detailAttachmentsContainer')) {
        document.getElementById('detailAttachmentsContainer').classList.add('d-none');
    }
    
    fetch('email.php?action=message&id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger my-4">' + data.error + '</div>';
                return;
            }
            
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
            document.getElementById('detailBody').innerHTML = data.body;
            document.getElementById('quickReplySenderName').innerText = displaySender;
            
            // Render attachments smoothly
            let attachmentsContainer = document.getElementById('detailAttachmentsContainer');
            let attachmentsList = document.getElementById('detailAttachments');
            let attachmentsCount = document.getElementById('attachmentsCount');
            if (attachmentsContainer && attachmentsList && attachmentsCount) {
                attachmentsList.innerHTML = '';
                if (data.attachments && data.attachments.length > 0) {
                    attachmentsCount.innerText = data.attachments.length;
                    let attHtml = '';
                    data.attachments.forEach(att => {
                        let sizeStr = att.size ? ` (${Math.round(att.size / 1024)} KB)` : '';
                        let downloadLink = att.url ? att.url : '#';
                        let target = att.url ? 'target="_blank" download' : 'onclick="alert(\'Attachment file path unavailable on server.\'); return false;"';
                        
                        attHtml += `
                        <a href="${downloadLink}" ${target} class="btn btn-outline-secondary d-flex align-items-center gap-2 px-3 py-2 text-decoration-none rounded-3 bg-light-subtle text-dark border shadow-sm">
                            <i class="ri-file-download-fill fs-20 text-primary"></i>
                            <div class="text-start overflow-hidden">
                                <div class="fw-bold fs-13 text-truncate text-dark" style="max-width: 240px;">${att.name}</div>
                                <div class="text-muted fs-11">Click to download${sizeStr}</div>
                            </div>
                        </a>
                        `;
                    });
                    attachmentsList.innerHTML = attHtml;
                    attachmentsContainer.classList.remove('d-none');
                } else {
                    attachmentsContainer.classList.add('d-none');
                }
            }
            
            // Update reply defaults
            document.getElementById('replyTo').value = data.fromAddress;
            document.getElementById('replySubject').value = (data.subject || '').startsWith('Re:') ? data.subject : 'Re: ' + (data.subject || '');
            
            // Mark item as read in local cache & update badges
            let cached = allEmails.find(e => e.id == id);
            if (cached && cached.isUnread) {
                cached.isUnread = 0;
                let unreadCount = allEmails.filter(m => m.isUnread).length;
                document.getElementById('unreadBadge').innerText = unreadCount;
            }
        })
        .catch(err => {
            document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger my-4">Network error communicating with mail server.</div>';
        });
}

function deleteFromList(id) {
    if (!confirm('Move this message to trash / delete permanently from server?')) return;
    
    // Optimistically remove from UI
    allEmails = allEmails.filter(m => m.id !== id);
    renderEmails();
    
    let unreadCount = allEmails.filter(m => m.isUnread).length;
    document.getElementById('inboxBadge').innerText = allEmails.length;
    document.getElementById('unreadBadge').innerText = unreadCount;
    
    fetch('email.php?action=delete&id=' + id)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('Server warning: failed to execute remote IMAP delete.');
                loadInbox();
            }
        })
        .catch(() => {
            loadInbox();
        });
}

document.getElementById('closeReadEmail').addEventListener('click', function() {
    document.getElementById('emailDetailsView').classList.add('d-none');
    document.getElementById('emailDetailsView').classList.remove('d-flex');
    document.getElementById('mailListView').classList.remove('d-none');
    document.getElementById('mailListView').classList.add('d-flex');
    currentEmailId = null;
    renderEmails(); // Re-render cleanly with updated unread statuses
});

document.getElementById('deleteEmailBtn').addEventListener('click', function() {
    if (!currentEmailId || !confirm('Permanently delete this email from your mailbox?')) return;
    
    let btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
    
    fetch('email.php?action=delete&id=' + currentEmailId)
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ri-delete-bin-line fs-18"></i>';
            if (data.success) {
                allEmails = allEmails.filter(m => m.id !== currentEmailId);
                document.getElementById('inboxBadge').innerText = allEmails.length;
                document.getElementById('unreadBadge').innerText = allEmails.filter(m => m.isUnread).length;
                document.getElementById('closeReadEmail').click();
            } else {
                alert('Failed to delete email: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ri-delete-bin-line fs-18"></i>';
            alert('Network error while deleting.');
        });
});

// Boot application
document.addEventListener('DOMContentLoaded', loadInbox);
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
