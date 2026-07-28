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
    
    if ($mailClient->sendMessage($to, $subject, $body)) {
        flash('success', 'Email sent successfully.');
    } else {
        flash('error', 'Failed to send email.');
    }
    redirect('email.php');
}

$page = ['title' => 'Email', 'section' => 'Applications', 'active' => 'email'];
require __DIR__ . '/partials/head.php';
?>

<!-- Email Specific CSS -->
<style>
    .mail-wrapper-card {
        border: 1px solid var(--bs-border-color);
        border-radius: 0.5rem;
        overflow: hidden;
        background: var(--bs-card-bg);
        min-height: calc(100vh - 190px);
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.04);
    }
    .mail-sidebar {
        width: 260px;
        flex-shrink: 0;
        border-right: 1px solid var(--bs-border-color);
        background: var(--bs-light-subtle, #fcfcfc);
    }
    .mail-box {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
        background: var(--bs-body-bg, #fff);
    }
    .message-list-content {
        flex-grow: 1;
        overflow-y: auto;
    }
    .message-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .inbox-data {
        transition: all 0.15s ease-in-out;
        border-bottom: 1px solid var(--bs-border-color-translucent, #f1f1f1);
    }
    .inbox-data:hover {
        background-color: var(--bs-light, #f8f9fa);
    }
    .inbox-data.unread {
        font-weight: 600;
        background-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.03);
    }
    .inbox-data.unread h6 {
        font-weight: 700;
        color: var(--bs-primary) !important;
    }
    .mail-preview {
        display: flex;
        flex-direction: column;
        height: 100%;
        background: var(--bs-body-bg, #fff);
    }
    .mail-body {
        font-size: 15px;
        line-height: 1.65;
        color: var(--bs-body-color, #333);
        word-break: break-word;
    }
    @media (max-width: 991.98px) {
        .mail-sidebar {
            width: 100%;
            border-right: none;
            border-bottom: 1px solid var(--bs-border-color);
        }
    }
</style>

<div class="mail-wrapper-card d-flex flex-column flex-lg-row mb-4">
    <!-- Sidebar -->
    <div class="mail-sidebar p-4 d-flex flex-column" id="email-sidebar">
        <div class="mb-4">
            <button class="btn btn-primary w-100 py-2 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#composeModal">
                <i class="ri-pencil-line me-2"></i> Compose Email
            </button>
        </div>
        <div class="mail-list flex-grow-1">
            <a href="#" class="d-flex align-items-center justify-content-between text-primary mb-2 text-decoration-none px-3 py-2 rounded-2 bg-primary-subtle fw-semibold">
                <span><i class="ri-inbox-archive-fill me-2 align-middle"></i> Inbox</span>
                <span class="badge bg-primary rounded-pill fs-11" id="inboxBadge">...</span>
            </a>
        </div>
        <div class="pt-3 border-top mt-auto">
            <div class="text-muted fs-12 text-center" id="inboxCount">Connecting to IMAP...</div>
        </div>
    </div>
    
    <!-- Main Content Area -->
    <div class="mail-box" id="emailWorkspace">
        <!-- VIEW 1: Message List View -->
        <div class="w-100 d-flex flex-column flex-grow-1" id="mailListView">
            <div class="p-3 px-4 border-bottom d-flex align-items-center justify-content-between gap-3 bg-light-subtle">
                <h5 class="mb-0 fw-semibold fs-16"><i class="ri-mail-unread-line me-2 text-primary"></i>Inbox Messages</h5>
                <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" onclick="loadInbox()" title="Refresh Inbox">
                    <i class="ri-refresh-line fs-14"></i> <span class="d-none d-sm-inline">Refresh</span>
                </button>
            </div>
            
            <div class="message-list-content">
                <div id="loadingIndicator" class="text-center p-5 my-5">
                    <div class="spinner-border text-primary my-2" role="status"></div>
                    <div class="text-muted fs-14 fw-medium mt-2">Connecting to IMAP server...</div>
                </div>
                <ul class="message-list" id="mail-list">
                    <!-- Emails populated here via JS -->
                </ul>
            </div>
        </div>
        
        <!-- VIEW 2: Email Detail / Reading View (hidden by default) -->
        <div class="w-100 d-none flex-column flex-grow-1" id="emailDetailsView">
            <div class="mail-preview p-4 d-flex flex-column h-100">
                <!-- Top Actions Bar -->
                <div class="d-flex align-items-center justify-content-between pb-3 border-bottom mb-4">
                    <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1 px-3" id="closeReadEmail" title="Back to Inbox">
                        <i class="ri-arrow-left-line fs-14"></i> <span>Back to Inbox</span>
                    </button>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#replyModal">
                            <i class="ri-reply-line fs-14"></i> <span>Reply</span>
                        </button>
                        <button class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1" id="deleteEmailBtn" title="Delete Email">
                            <i class="ri-delete-bin-line fs-14"></i> <span>Delete</span>
                        </button>
                    </div>
                </div>

                <!-- Email Header Section -->
                <div class="mail-header pb-4 border-bottom mb-4">
                    <h4 class="fw-bold text-dark mb-3" id="detailSubject">Subject</h4>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-16 flex-shrink-0" style="width:40px; height:40px;" id="detailAvatar">
                                ?
                            </div>
                            <div>
                                <h6 class="mb-0 fw-semibold fs-15 text-dark" id="detailFromName">Sender</h6>
                                <span class="text-muted fs-13" id="detailFromAddress">&lt;email@domain.com&gt;</span>
                            </div>
                        </div>
                        <span class="badge bg-light text-dark border fs-12 py-2 px-3" id="detailDate">Date</span>
                    </div>
                </div>
                
                <!-- Email Body Content -->
                <div class="mail-body flex-grow-1 p-2 overflow-auto" id="detailBody">
                    <!-- Body rendered here -->
                </div>
                
                <!-- Footer Reply Section -->
                <div class="quick-reply mt-5 pt-4 border-top d-flex align-items-center justify-content-between bg-light-subtle p-3 rounded-2">
                    <span class="text-muted fs-14">Click to reply to this message or discard it from your mailbox.</span>
                    <div>
                        <button class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#replyModal">
                            <i class="ri-reply-line me-1"></i> Reply
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Compose Modal -->
<div class="modal fade" id="composeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content border-0 shadow" method="post" action="email.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <div class="modal-header bg-light pb-3">
                <h5 class="modal-title fw-semibold"><i class="ri-mail-send-line me-2 text-primary"></i>Compose New Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fs-13 fw-semibold text-muted">Recipient Address</label>
                    <input type="email" name="to" class="form-control form-control-lg fs-14" placeholder="e.g. client@example.com" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fs-13 fw-semibold text-muted">Subject</label>
                    <input type="text" name="subject" class="form-control form-control-lg fs-14" placeholder="Enter message subject..." required>
                </div>
                <div>
                    <label class="form-label fs-13 fw-semibold text-muted">Message Body</label>
                    <textarea name="body" class="form-control fs-14" rows="10" placeholder="Write your message here..." required></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light pt-3">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Discard</button>
                <button type="submit" class="btn btn-primary px-4"><i class="ri-send-plane-2-line me-2"></i>Send Message</button>
            </div>
        </form>
    </div>
</div>

<!-- Reply Modal -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content border-0 shadow" method="post" action="email.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="to" id="replyTo">
            <div class="modal-header bg-light pb-3">
                <h5 class="modal-title fw-semibold"><i class="ri-reply-line me-2 text-primary"></i>Reply to Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fs-13 fw-semibold text-muted">Subject</label>
                    <input type="text" name="subject" id="replySubject" class="form-control form-control-lg fs-14" required>
                </div>
                <div>
                    <label class="form-label fs-13 fw-semibold text-muted">Your Reply</label>
                    <textarea name="body" class="form-control fs-14" rows="8" placeholder="Type your response here..." required></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light pt-3">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4"><i class="ri-send-plane-2-line me-2"></i>Send Reply</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentEmailId = null;

function loadInbox() {
    document.getElementById('loadingIndicator').classList.remove('d-none');
    document.getElementById('mail-list').innerHTML = '';
    document.getElementById('inboxBadge').innerText = '...';
    
    fetch('email.php?action=inbox')
        .then(res => res.json())
        .then(data => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            
            if (data.error) {
                document.getElementById('mail-list').innerHTML = '<div class="p-5 text-center text-danger fw-medium"><i class="ri-error-warning-line fs-1 d-block mb-2"></i>' + data.error + '</div>';
                document.getElementById('inboxCount').innerText = 'Error loading messages';
                document.getElementById('inboxBadge').innerText = '0';
                return;
            }
            
            if (data.length === 0) {
                document.getElementById('mail-list').innerHTML = '<div class="p-5 my-4 text-center text-muted"><i class="ri-mail-open-line fs-1 d-block mb-2 opacity-50"></i>Your inbox is currently empty</div>';
                document.getElementById('inboxCount').innerText = '0 total messages';
                document.getElementById('inboxBadge').innerText = '0';
                return;
            }
            
            let unreadCount = data.filter(m => m.isUnread).length;
            document.getElementById('inboxBadge').innerText = data.length;
            document.getElementById('inboxCount').innerText = `${data.length} total message${data.length === 1 ? '' : 's'}` + (unreadCount ? ` (${unreadCount} unread)` : '');
            
            let html = '';
            data.forEach(mail => {
                let unreadClass = mail.isUnread ? 'unread' : '';
                
                // Properly parse sender display name to avoid 'null' output
                let cleanName = (mail.fromName && mail.fromName !== 'null' && mail.fromName.trim() !== '') ? mail.fromName : mail.fromAddress;
                let avatarChar = cleanName.charAt(0).toUpperCase();
                
                html += `
                <li class="inbox-data d-flex gap-3 align-items-center py-3 px-4 cursor-pointer ${unreadClass}" onclick="readEmail(${mail.id})" style="cursor: pointer;">
                    <div class="avatar-item avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-semibold flex-shrink-0" style="width:38px;height:38px; font-size: 15px;">
                        ${avatarChar}
                    </div>
                    <div class="flex-grow-1 overflow-hidden" style="min-width: 0;">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h6 class="mb-0 text-truncate text-dark fs-15">${cleanName}</h6>
                            ${mail.fromName && mail.fromName !== 'null' && mail.fromAddress ? `<span class="text-muted fs-12 d-none d-lg-inline">&lt;${mail.fromAddress}&gt;</span>` : ''}
                        </div>
                        <div class="d-flex text-muted fs-13">
                            <span class="text-truncate fw-medium text-dark me-2" style="max-width: 280px;">${mail.subject || 'No Subject'}</span>
                            <span class="text-truncate d-none d-md-block opacity-75">- ${mail.snippet || ''}</span>
                        </div>
                    </div>
                    <div class="flex-shrink-0 text-muted fs-12 text-end">
                        <div class="mb-1">${mail.date}</div>
                        ${mail.isUnread ? '<span class="badge bg-primary-subtle text-primary rounded-pill px-2 fs-10">NEW</span>' : ''}
                    </div>
                </li>
                `;
            });
            document.getElementById('mail-list').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            document.getElementById('mail-list').innerHTML = '<div class="p-5 text-center text-danger fw-medium"><i class="ri-wifi-off-line fs-1 d-block mb-2"></i>Failed to fetch emails. Check your network or mail server configuration.</div>';
            document.getElementById('inboxCount').innerText = 'Connection error';
        });
}

function readEmail(id) {
    currentEmailId = id;
    
    // Smooth view switching without absolute overlay clipping
    document.getElementById('mailListView').classList.add('d-none');
    document.getElementById('mailListView').classList.remove('d-flex');
    document.getElementById('emailDetailsView').classList.remove('d-none');
    document.getElementById('emailDetailsView').classList.add('d-flex');
    
    document.getElementById('detailBody').innerHTML = '<div class="text-center my-5 py-5"><div class="spinner-border text-primary my-2" role="status"></div><div class="text-muted fs-14 mt-2">Loading message content...</div></div>';
    
    fetch('email.php?action=message&id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger my-4">' + data.error + '</div>';
                return;
            }
            
            document.getElementById('detailSubject').innerText = data.subject || 'No Subject';
            
            // Format sender display properly without 'null' literals
            let hasValidName = (data.fromName && data.fromName !== 'null' && data.fromName.trim() !== '' && data.fromName !== data.fromAddress);
            let displaySender = hasValidName ? data.fromName : data.fromAddress;
            
            document.getElementById('detailFromName').innerText = displaySender;
            document.getElementById('detailFromAddress').innerText = hasValidName ? `<${data.fromAddress}>` : '';
            document.getElementById('detailAvatar').innerText = displaySender.charAt(0).toUpperCase();
            
            document.getElementById('detailDate').innerText = data.date;
            document.getElementById('detailBody').innerHTML = data.body;
            
            // Setup reply modal defaults
            document.getElementById('replyTo').value = data.fromAddress;
            document.getElementById('replySubject').value = (data.subject || '').startsWith('Re:') ? data.subject : 'Re: ' + (data.subject || '');
            
            // Update inbox unread status in background
            fetch('email.php?action=inbox').then(r => r.json()).then(inboxData => {
                if (!inboxData.error) {
                    let unread = inboxData.filter(m => m.isUnread).length;
                    document.getElementById('inboxBadge').innerText = inboxData.length;
                }
            });
        })
        .catch(err => {
            document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger my-4">Error communicating with server to load message details.</div>';
        });
}

document.getElementById('closeReadEmail').addEventListener('click', function() {
    document.getElementById('emailDetailsView').classList.add('d-none');
    document.getElementById('emailDetailsView').classList.remove('d-flex');
    document.getElementById('mailListView').classList.remove('d-none');
    document.getElementById('mailListView').classList.add('d-flex');
    currentEmailId = null;
    loadInbox(); // refresh list so read/unread badges update cleanly
});

document.getElementById('deleteEmailBtn').addEventListener('click', function() {
    if (!currentEmailId || !confirm('Are you certain you want to permanently delete this email from your mailbox?')) return;
    
    let btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';
    
    fetch('email.php?action=delete&id=' + currentEmailId)
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ri-delete-bin-line fs-14"></i> <span>Delete</span>';
            
            if (data.success) {
                document.getElementById('closeReadEmail').click();
            } else {
                alert('Failed to delete email: ' + (data.error || 'Server rejected request'));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ri-delete-bin-line fs-14"></i> <span>Delete</span>';
            alert('Network error while deleting email.');
        });
});

// Initial load
document.addEventListener('DOMContentLoaded', loadInbox);
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
