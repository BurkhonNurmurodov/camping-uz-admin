<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();
require __DIR__ . '/partials/widgets.php';
require_once __DIR__ . '/app/MailClient.php';

$mailClient = new \App\MailClient();

// Handle AJAX requests
if (isset($_GET['action'])) {
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
    .mail-sidebar { width: 280px; flex-shrink: 0; background: var(--bs-card-bg); border-right: 1px solid var(--bs-border-color); height: calc(100vh - 70px); overflow-y: auto; }
    .mail-box { flex-grow: 1; height: calc(100vh - 70px); display: flex; flex-direction: column; }
    .message-list-content { flex-grow: 1; overflow-y: auto; }
    .message-list { list-style: none; padding: 0; margin: 0; }
    .inbox-data { transition: all 0.2s; }
    .inbox-data:hover { background-color: var(--bs-light); }
    .inbox-data.unread { font-weight: bold; background-color: var(--bs-light-subtle); }
    #emailDetails { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: var(--bs-body-bg); z-index: 10; display: flex; flex-direction: column; }
</style>

<div class="d-flex mail-wrapper" style="margin: -1.5rem; height: calc(100vh - 70px);">
    <div class="mail-sidebar active p-4" id="email-sidebar">
        <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between position-relative">
                <div>
                    <h4>Inbox <span class="fs-6 text-muted fw-normal">Messages</span></h4>
                    <span class="fs-12 text-muted" id="inboxCount">Loading...</span>
                </div>
                <div data-bs-toggle="tooltip" title="New Mail">
                    <button class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#composeModal">
                        <i class="ri-pencil-line me-1"></i> Compose
                    </button>
                </div>
            </div>
        </div>
        <div class="mail-list">
            <a href="#" class="d-flex text-primary mb-2 text-decoration-none">
                <i class="ri-inbox-archive-fill me-3"></i> Inbox
            </a>
            <!-- Placeholder for folders if needed -->
        </div>
    </div>
    
    <div class="mail-box position-relative" id="emailList">
        <div class="p-4 border-bottom bg-white">
            <div class="d-flex align-items-center justify-content-between gap-4">
                <h5 class="mb-0 flex-shrink-0 d-none d-md-block">Mail inbox</h5>
                <div class="d-flex gap-3">
                    <button type="button" class="btn btn-light icon-btn" onclick="loadInbox()">
                        <i class="ri-refresh-line"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="message-list-content bg-white">
            <div id="loadingIndicator" class="text-center p-5">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="mt-2 text-muted">Connecting to IMAP server...</div>
            </div>
            <ul class="message-list" id="mail-list">
                <!-- Emails will be populated here via JS -->
            </ul>
        </div>
        
        <!-- Mail Preview -->
        <div class="w-100 d-none" id="emailDetails">
            <div class="mail-preview p-4 d-flex flex-column h-100">
                <div class="mail-header border-bottom pb-3 mb-4 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <button class="btn btn-light rounded-pill icon-btn text-body me-4" id="closeReadEmail">
                            <i class="ri-arrow-left-line"></i>
                        </button>
                        <div>
                            <h5 class="mb-0 flex-shrink-0" id="detailSubject">Subject</h5>
                            <p class="mb-0 text-muted">From: <span id="detailFrom">Sender</span></p>
                        </div>
                    </div>
                    <div>
                        <span class="text-muted d-none d-md-inline-block" id="detailDate">Date</span>
                    </div>
                </div>
                
                <div class="mail-body flex-grow-1 overflow-auto" id="detailBody">
                    <!-- Body here -->
                </div>
                
                <div class="quick-reply mt-4 pt-3 border-top">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#replyModal">
                        <i class="ri-reply-line me-1"></i> Reply
                    </button>
                    <button class="btn btn-outline-danger ms-2" id="deleteEmailBtn">
                        <i class="ri-delete-bin-line me-1"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Compose Modal -->
<div class="modal fade" id="composeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="email.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <div class="modal-header">
                <h5 class="modal-title">New Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="email" name="to" class="form-control" placeholder="To" required>
                </div>
                <div class="mb-3">
                    <input type="text" name="subject" class="form-control" placeholder="Subject" required>
                </div>
                <div>
                    <textarea name="body" class="form-control" rows="10" placeholder="Message body..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Discard</button>
                <button type="submit" class="btn btn-primary"><i class="ri-send-plane-line me-1"></i> Send</button>
            </div>
        </form>
    </div>
</div>

<!-- Reply Modal -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="email.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="to" id="replyTo">
            <div class="modal-header">
                <h5 class="modal-title">Reply</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" name="subject" id="replySubject" class="form-control" required>
                </div>
                <div>
                    <textarea name="body" class="form-control" rows="8" placeholder="Type your reply..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="ri-send-plane-line me-1"></i> Send</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentEmailId = null;

function loadInbox() {
    document.getElementById('loadingIndicator').classList.remove('d-none');
    document.getElementById('mail-list').innerHTML = '';
    
    fetch('email.php?action=inbox')
        .then(res => res.json())
        .then(data => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            
            if (data.error) {
                document.getElementById('mail-list').innerHTML = '<div class="p-4 text-danger">Error: ' + data.error + '</div>';
                return;
            }
            
            if (data.length === 0) {
                document.getElementById('mail-list').innerHTML = '<div class="p-4 text-center text-muted">Inbox is empty</div>';
                document.getElementById('inboxCount').innerText = '0 messages';
                return;
            }
            
            document.getElementById('inboxCount').innerText = data.length + ' messages';
            
            let html = '';
            data.forEach(mail => {
                let unreadClass = mail.isUnread ? 'unread' : '';
                html += `
                <li class="inbox-data d-flex gap-3 align-items-center py-3 px-4 border-bottom cursor-pointer ${unreadClass}" onclick="readEmail(${mail.id})">
                    <div class="avatar-item avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;">
                        ${mail.fromName ? mail.fromName.charAt(0).toUpperCase() : '<i class="ri-user-line"></i>'}
                    </div>
                    <div class="flex-grow-1 overflow-hidden" style="min-width: 0;">
                        <h6 class="mb-1 text-truncate">${mail.fromName || mail.fromAddress}</h6>
                        <div class="d-flex text-muted fs-13">
                            <span class="text-truncate fw-medium text-dark me-2" style="max-width: 200px;">${mail.subject}</span>
                            <span class="text-truncate d-none d-md-block">- ${mail.snippet}</span>
                        </div>
                    </div>
                    <div class="flex-shrink-0 text-muted fs-12">${mail.date}</div>
                </li>
                `;
            });
            document.getElementById('mail-list').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('loadingIndicator').classList.add('d-none');
            document.getElementById('mail-list').innerHTML = '<div class="p-4 text-danger">Failed to load emails. Check server configuration.</div>';
        });
}

function readEmail(id) {
    currentEmailId = id;
    document.getElementById('emailDetails').classList.remove('d-none');
    document.getElementById('detailBody').innerHTML = '<div class="text-center mt-5"><div class="spinner-border text-primary"></div></div>';
    
    fetch('email.php?action=message&id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                document.getElementById('detailBody').innerHTML = '<div class="text-danger">' + data.error + '</div>';
                return;
            }
            document.getElementById('detailSubject').innerText = data.subject || 'No Subject';
            document.getElementById('detailFrom').innerText = data.fromName + ' <' + data.fromAddress + '>';
            document.getElementById('detailDate').innerText = data.date;
            document.getElementById('detailBody').innerHTML = data.body;
            
            // Setup reply modal
            document.getElementById('replyTo').value = data.fromAddress;
            document.getElementById('replySubject').value = (data.subject || '').startsWith('Re:') ? data.subject : 'Re: ' + data.subject;
            
            // Re-load inbox in background to update read status
            loadInbox();
        });
}

document.getElementById('closeReadEmail').addEventListener('click', function() {
    document.getElementById('emailDetails').classList.add('d-none');
    currentEmailId = null;
});

document.getElementById('deleteEmailBtn').addEventListener('click', function() {
    if (!currentEmailId || !confirm('Are you sure you want to delete this email?')) return;
    
    fetch('email.php?action=delete&id=' + currentEmailId)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('emailDetails').classList.add('d-none');
                loadInbox();
            } else {
                alert('Failed to delete email.');
            }
        });
});

// Initial load
document.addEventListener('DOMContentLoaded', loadInbox);
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
