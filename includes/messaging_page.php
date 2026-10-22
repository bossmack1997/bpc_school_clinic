<?php
// Shared page: callers authorize their route, and this view enforces the module boundary.
require_once __DIR__ . '/../config/config.php';
require_role(['student', 'teacher', 'nurse']);
$user = current_user();
$messagingRole = canonical_role($user['role']);
$isNurse = $messagingRole === 'nurse';
$pageTitle = 'Clinic Messages';
$currentClinicStatus = 'available';
if ($isNurse) {
    $status = getDB()->prepare('SELECT clinic_status FROM users WHERE id = ?');
    $status->execute([(int)$user['id']]);
    $currentClinicStatus = $status->fetchColumn() ?: 'available';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/head.php'; ?>
<link rel="stylesheet" href="<?= asset_url('css/messenger.css') . '?v=' . filemtime(__DIR__ . '/../assets/css/messenger.css') ?>">
</head>
<body class="clinic-messages-page">
<div class="app-wrapper">
  <?php include __DIR__ . '/../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <div class="content-area page-enter py-3 student-messages-page unified-messages-page">

      <!-- Messenger Container -->
      <div class="messenger-container shadow-sm border">
        
        <!-- Left Column: Conversations & Available Staff -->
        <div class="messenger-sidebar" id="convSidebar">
          
          <!-- Top Header & New Message Action -->
          <div class="messenger-list-header d-flex align-items-center justify-content-between p-3 border-bottom bg-white">
            <h1 class="fw-bold mb-0 text-dark">Chats</h1>
            <div class="d-flex align-items-center gap-2">
              <button class="btn btn-success btn-sm rounded-circle messenger-icon-action" aria-label="New message" title="New message" onclick="openNewMessageModal()"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
              <div class="dropdown">
                <button class="btn btn-light rounded-circle messenger-icon-action" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More message options" title="More options"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                <ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item" type="button" onclick="loadConversations(false)"><i class="bi bi-arrow-clockwise me-2"></i>Refresh conversations</button></li></ul>
              </div>
            </div>
          </div>

          <!-- Search Input -->
          <div class="messenger-search-bar">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
              <input type="search" class="form-control border-start-0 ps-0 bg-white" id="convSearchInput" placeholder="Search conversations" aria-label="Search messages or people" oninput="filterConversations()">
            </div>
          </div>

<?php if ($isNurse): ?>
          <div class="messenger-filter-pills" role="group" aria-label="Filter conversations">
            <?php foreach (['all'=>'All','unread'=>'Unread','student'=>'Students','teacher'=>'Teachers'] as $filter=>$label): ?>
            <button class="messenger-filter-btn <?= $filter === 'all' ? 'active' : '' ?>" type="button" data-filter="<?= e($filter) ?>" onclick="setMessageFilter('<?= e($filter) ?>', this)"><?= e($label) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="messenger-availability px-3 pb-2 d-flex align-items-center justify-content-between">
            <label for="myClinicStatusSelect" class="small text-muted">My clinic status</label>
            <select class="form-select form-select-sm w-auto" id="myClinicStatusSelect" data-saved-status="<?= e($currentClinicStatus) ?>" onchange="updateMyClinicStatus(this.value)" aria-label="Clinic availability">
            <?php foreach (['available'=>'Available','busy'=>'Busy / In Consult','away'=>'Away from Desk'] as $value=>$label): ?>
              <option value="<?= e($value) ?>" <?= $value === $currentClinicStatus ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
            </select>
          </div>
<?php else: ?>
          <div class="messenger-filter-pills" role="group" aria-label="Filter conversations">
            <button class="messenger-filter-btn active" type="button" data-filter="all" onclick="setMessageFilter('all', this)">All</button>
            <button class="messenger-filter-btn" type="button" data-filter="unread" onclick="setMessageFilter('unread', this)">Unread</button>
            <button class="messenger-filter-btn" type="button" data-filter="staff" onclick="setMessageFilter('staff', this)">Staff</button>
          </div>

          <!-- Available Clinic Staff Section -->
          <div class="messenger-available-section" id="availableStaffSection">
            <div class="messenger-section-label">
              <span><i class="bi bi-hospital text-success me-1"></i> Clinic Staff</span>
              <span class="text-muted" style="font-size:0.65rem;" id="onlineStaffCount">Loading...</span>
            </div>
            <div class="messenger-staff-grid" id="availableStaffGrid">
              <div class="text-muted small py-2 px-1">Loading clinic staff...</div>
            </div>
          </div>

<?php endif; ?>
          <!-- Thread List Header -->
          <div class="px-3 pt-2 pb-1 bg-white border-bottom">
            <span class="messenger-section-label mb-0">Recent Conversations</span>
          </div>

          <!-- Conversation Threads List -->
          <div class="messenger-thread-list" id="convList">
            <div class="messenger-skeleton-row" aria-hidden="true"><span class="messenger-skeleton-avatar"></span><span class="messenger-skeleton-copy"><span class="messenger-skeleton-line"></span><span class="messenger-skeleton-line"></span></span></div>
            <div class="messenger-skeleton-row" aria-hidden="true"><span class="messenger-skeleton-avatar"></span><span class="messenger-skeleton-copy"><span class="messenger-skeleton-line"></span><span class="messenger-skeleton-line"></span></span></div>
            <div class="messenger-skeleton-row" aria-hidden="true"><span class="messenger-skeleton-avatar"></span><span class="messenger-skeleton-copy"><span class="messenger-skeleton-line"></span><span class="messenger-skeleton-line"></span></span></div>
          </div>
        </div>

        <!-- Right Column: Active Chat View -->
        <div class="messenger-main is-mobile-hidden" id="chatMain">
          
          <!-- Header -->
          <div class="messenger-header" id="activeChatHeader">
            <div class="messenger-header-info">
              <button class="btn btn-sm btn-outline-secondary d-lg-none me-1" onclick="backToList()" title="Back to list">
                <i class="bi bi-arrow-left"></i>
              </button>
              <div class="position-relative" id="activeAvatarWrap">
                  <img src="<?= asset_url('images/default-avatar.svg') ?>" class="messenger-avatar" id="activeHeaderAvatar" alt="Clinic staff profile">
                <span class="presence-dot status-offline" id="activePresenceDot"></span>
              </div>
              <div>
                <div class="fw-bold fs-6 text-dark" id="activeHeaderName"><?= $isNurse ? 'Select a conversation' : 'Select a Clinic Nurse' ?></div>
                <div class="d-flex align-items-center gap-2">
                  <small class="text-muted" id="activeHeaderRole">Clinic Station</small>
                  <span class="badge bg-light text-muted border py-0 px-2" id="activeHeaderPresence" role="status" aria-live="polite">Offline</span>
                </div>
              </div>
            </div>
            
            <div class="d-flex align-items-center gap-2">
<?php if ($messagingRole === 'student'): ?>
              <button class="btn btn-outline-danger btn-sm rounded-pill d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#sosModal" title="Report Emergency">
                <i class="bi bi-shield-fill-exclamation"></i> <span>SOS</span>
              </button>
<?php elseif ($messagingRole === 'teacher'): ?>
              <a href="<?= app_url('pages/adviser/emergencies.php') ?>" class="btn btn-outline-danger btn-sm rounded-pill d-flex align-items-center gap-1" title="Emergency assistance"><i class="bi bi-broadcast" aria-hidden="true"></i><span>SOS</span></a>
<?php else: ?>
              <a class="btn btn-outline-primary btn-sm rounded-pill" id="patientWorkspaceBtn" hidden target="_blank" rel="noopener">Patient Record</a>
              <button class="btn btn-outline-success btn-sm rounded-pill" id="aiSummarizeBtn" type="button" onclick="requestAISummary()" disabled title="MICA Summary"><i class="bi bi-stars" aria-hidden="true"></i><span class="d-none d-md-inline"> MICA Summary</span></button>
<?php endif; ?>
              <button class="btn btn-sm btn-light border rounded-circle" onclick="refreshActiveConversation()" title="Refresh messages">
                <i class="bi bi-arrow-clockwise"></i>
              </button>
            </div>
          </div>

          <!-- Emergency Warning Notice (Clinical Safety) -->
          <div class="chat-emergency-banner" id="chatEmergencyBanner">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-exclamation-triangle-fill fs-5"></i>
              <span><strong>Medical Note:</strong> Messages are for non-urgent clinic inquiries. For acute emergencies, use SOS or proceed to the clinic.</span>
            </div>
            <button type="button" class="btn-close btn-close-sm" aria-label="Dismiss medical notice" onclick="$('#chatEmergencyBanner').slideUp(150)"></button>
          </div>

<?php if ($isNurse): ?>
          <!-- MICA AI Summary Box (Dismissible) -->
          <div id="aiSummaryBox" class="p-3 bg-light border-bottom" style="display:none;">
            <div class="mica-triage-summary-card p-3 bg-white rounded-3 border shadow-sm">
              <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                <div class="fw-bold d-flex align-items-center gap-1 text-success">
                  <i class="bi bi-robot"></i> MICA Assistive Triage Summary
                </div>
                <div class="d-flex align-items-center gap-2">
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copyAISummary()" title="Copy to clipboard">
                    <i class="bi bi-clipboard me-1"></i>Copy
                  </button>
                  <button type="button" class="btn-close btn-sm" onclick="$('#aiSummaryBox').slideUp(200)"></button>
                </div>
              </div>
              <div id="aiSummaryContent" class="small text-dark" style="white-space: pre-line; line-height: 1.6;"></div>
            </div>
          </div>

<?php endif; ?>
          <!-- Messages Stream Area -->
          <div class="messenger-messages-area" id="messageStream" role="log" aria-live="polite" aria-label="Conversation messages">
            <div class="empty-state-card my-auto text-center p-4" id="emptyPlaceholder">
              <div class="empty-state-icon text-success bg-success-light mx-auto mb-2" style="width:54px;height:54px;font-size:1.4rem;">
                <i class="bi bi-chat-heart"></i>
              </div>
              <h6 class="fw-bold text-dark mb-1">BPC School Clinic Chat</h6>
              <p class="text-muted small mb-3">Select an available Nurse from the staff list above or choose an ongoing conversation.</p>
              <button class="btn btn-success btn-sm rounded-pill px-3 fw-semibold" onclick="openNewMessageModal()">
                <i class="bi bi-pencil-square me-1"></i> Start Conversation
              </button>
            </div>
          </div>

<?php if ($isNurse): ?>
          <!-- Quick Clinical Replies Bar -->
          <div class="quick-replies-bar" id="quickRepliesBar" style="display:none;">
            <button type="button" class="quick-reply-chip" onclick="insertQuickReply('Please report to the clinic for vital signs and physical assessment.')"> Report to Clinic</button>
            <button type="button" class="quick-reply-chip" onclick="insertQuickReply('Take a 15-minute rest, hydrate with water, and monitor your symptoms.')"> Rest & Hydrate</button>
            <button type="button" class="quick-reply-chip" onclick="insertQuickReply('An excuse slip / clinic clearance has been issued.')"> Slip Issued</button>
            <button type="button" class="quick-reply-chip" onclick="insertQuickReply('Please consult an external physician or hospital for further diagnostics.')"> External Referral</button>
          </div>

<?php endif; ?>
          <!-- Input Composer -->
          <form class="messenger-composer-area" id="chatForm" onsubmit="handleSendMessage(event)" style="display:none;" aria-label="Send a message">
            <input type="file" id="messageAttachment" class="visually-hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" aria-label="Choose an attachment">
            <div class="messenger-compose-row">
            <button type="button" class="messenger-attach-btn" aria-label="Attach a file" title="Attach PDF, image, or document (max 10 MB)" onclick="$('#messageAttachment').trigger('click')">
              <i class="bi bi-paperclip" aria-hidden="true"></i>
            </button>
            <textarea class="form-control messenger-input" id="messageInput" placeholder="Type a message..." rows="1" maxlength="3000" autocomplete="off" aria-label="Type a message" oninput="toggleSendButton(); resizeMessageInput(this)"></textarea>
            <button type="submit" class="messenger-send-btn shadow-sm" id="sendBtn" title="Send Message (Enter)" disabled>
              <i class="bi bi-send-fill" aria-hidden="true"></i><span class="visually-hidden">Send message</span>
            </button>
            </div>
            <span class="messenger-attachment-name" id="attachmentName" aria-live="polite"></span>
          </form>

        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Start New Message with Clinic Staff -->
<!-- ===================================================================== -->
<div class="modal fade" id="newMessageModal" tabindex="-1" aria-labelledby="newMessageModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="newMessageModalTitle"><?= bpc_icon('chat') ?><?= $isNurse ? 'New patient conversation' : 'Message Clinic Staff' ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="app-muted mb-3"><?= $isNurse ? 'Choose a student or teacher to start a clinic conversation.' : 'Choose a clinic staff member to discuss your health or clinic appointment.' ?></p>
        <div class="mb-3">
          <label class="form-label" for="modalStaffSearch">Search contacts</label>
          <input type="text" class="form-control" id="modalStaffSearch" placeholder="Search by name..." oninput="filterModalStaff()">
        </div>

        <div class="form-label mb-2"><?= $isNurse ? 'Students and Teachers' : 'Available Clinic Staff' ?></div>
        <div class="list-group list-group-flush border" id="modalStaffList" style="max-height: 280px; overflow-y: auto;">
          <div class="p-3 text-center text-muted small">Loading clinic staff...</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../components/footer.php'; ?>
<script>window.MESSENGER_CONFIG = <?= json_encode(['role'=>$messagingRole,'userId'=>(int)$user['id'],'conversationId'=>(int)($_GET['id'] ?? 0),'avatar'=>asset_url('images/default-avatar.svg')], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script src="<?= asset_url('js/messenger-client.js') . '?v=' . filemtime(__DIR__ . '/../assets/js/messenger-client.js') ?>"></script>
<script src="<?= asset_url('js/messenger.js') . '?v=' . filemtime(__DIR__ . '/../assets/js/messenger.js') ?>"></script>
</body>
</html>
