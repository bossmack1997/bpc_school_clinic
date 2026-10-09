<?php
/**
 * pages/adviser/messages.php
 * =====================================================================
 * Teacher Clinic Coordination Messenger — Direct Nurse Link
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('teacher');
$pageTitle = 'Clinic Coordination Messages';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .chat-emergency-banner {
      background: #FEF2F2;
      border: 1px solid #FECACA;
      border-radius: 12px;
      padding: 10px 14px;
      font-size: 0.82rem;
      color: #991B1B;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin: 12px 16px 0;
    }
  </style>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter py-3">

      <!-- Messenger Container -->
      <div class="messenger-container shadow-sm border">
        
        <!-- Left Column: Coordination Threads & Available Staff -->
        <div class="messenger-sidebar" id="convSidebar">
          
          <!-- Top Header & New Coordination Action -->
          <div class="d-flex align-items-center justify-content-between p-3 border-bottom bg-white">
            <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
              <i class="bi bi-chat-heart-fill text-success"></i> Coordination
            </h5>
            <button class="btn btn-success btn-sm rounded-pill px-3 fw-semibold shadow-sm" onclick="openNewCoordinationModal()">
              <i class="bi bi-plus-lg me-1"></i> New Chat
            </button>
          </div>

          <!-- Search Input -->
          <div class="messenger-search-bar">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control border-start-0 ps-0 bg-white" id="convSearchInput" placeholder="Search nurse or thread..." oninput="filterConversations()">
            </div>
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

          <!-- Thread List Header -->
          <div class="px-3 pt-2 pb-1 bg-white border-bottom">
            <span class="messenger-section-label mb-0">Active Coordination Threads</span>
          </div>

          <!-- Conversation Threads List -->
          <div class="messenger-thread-list" id="convList">
            <div class="text-center text-muted p-4">
              <div class="spinner-border spinner-border-sm text-success me-2"></div>Loading coordination threads...
            </div>
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
                <img src="<?= asset_url('images/default-avatar.svg') ?>" class="messenger-avatar" id="activeHeaderAvatar" alt="Avatar">
                <span class="presence-dot status-offline" id="activePresenceDot"></span>
              </div>
              <div>
                <div class="fw-bold fs-6 text-dark" id="activeHeaderName">Select a Clinic Nurse</div>
                <div class="d-flex align-items-center gap-2">
                  <small class="text-muted" id="activeHeaderRole">Clinic Nurse Station</small>
                  <span class="badge bg-light text-muted border py-0 px-2" style="font-size:0.68rem;" id="activeHeaderPresence">Offline</span>
                </div>
              </div>
            </div>
            
            <div class="d-flex align-items-center gap-2">
              <a href="emergencies.php" class="btn btn-outline-danger btn-sm rounded-pill d-none d-md-flex align-items-center gap-1" title="Emergency Coordination">
                <i class="bi bi-broadcast"></i> <span>SOS</span>
              </a>
              <button class="btn btn-sm btn-light border rounded-circle" onclick="refreshActiveConversation()" title="Refresh messages">
                <i class="bi bi-arrow-clockwise"></i>
              </button>
            </div>
          </div>

          <!-- Emergency Banner -->
          <div class="chat-emergency-banner" id="chatEmergencyBanner">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-exclamation-octagon-fill fs-5"></i>
              <span><strong>Teacher Notice:</strong> Use this channel for student health coordination and updates. For critical student medical emergencies, trigger Emergency SOS.</span>
            </div>
            <button type="button" class="btn-close btn-close-sm" onclick="$('#chatEmergencyBanner').slideUp(150)"></button>
          </div>

          <!-- Messages Stream Area -->
          <div class="messenger-messages-area" id="messageStream">
            <div class="empty-state-card my-auto text-center p-4" id="emptyPlaceholder">
              <div class="empty-state-icon text-success bg-success-light mx-auto mb-2" style="width:54px;height:54px;font-size:1.4rem;">
                <i class="bi bi-chat-square-heart"></i>
              </div>
              <h6 class="fw-bold text-dark mb-1">Teacher &amp; Clinic Coordination</h6>
              <p class="text-muted small mb-3">Notify clinic nurses regarding student health situations, classroom symptoms, or inquiries.</p>
              <button class="btn btn-success btn-sm rounded-pill px-3 fw-semibold" onclick="openNewCoordinationModal()">
                <i class="bi bi-chat-plus me-1"></i> Start Coordination
              </button>
            </div>
          </div>

          <!-- Input Composer -->
          <form class="messenger-composer-area" id="chatForm" onsubmit="handleSendMessage(event)" style="display:none;">
            <input type="text" class="form-control messenger-input" id="messageInput" placeholder="Type coordination message to the clinic nurse..." required maxlength="3000" autocomplete="off" oninput="toggleSendButton()">
            <button type="submit" class="messenger-send-btn shadow-sm" id="sendBtn" title="Send Message (Enter)">
              <i class="bi bi-send-fill"></i>
            </button>
          </form>

        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Message Clinic Staff (Teacher) -->
<!-- ===================================================================== -->
<div class="modal fade" id="newCoordModal" tabindex="-1" aria-labelledby="newCoordModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="newCoordModalTitle"><?= bpc_icon('chat') ?>Coordinate with Clinic Nurse</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="app-muted mb-3">Select a clinic nurse to send a message about a student's health situation, classroom symptom, or inquiry.</p>
        <div class="mb-3">
          <label class="form-label" for="modalStaffSearch">Search nurses</label>
          <input type="text" class="form-control" id="modalStaffSearch" placeholder="Search by name..." oninput="filterModalStaff()">
        </div>

        <div class="form-label mb-2">Available Clinic Nurses</div>
        <div class="list-group list-group-flush border" id="modalStaffList" style="max-height: 280px; overflow-y: auto;">
          <div class="p-3 text-center text-muted small">Loading clinic nurses...</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
let currentConversationId = <?= (int)($_GET['id'] ?? 0) ?>;
let activeRecipientId = 0;
let availableStaff = [];
let conversationList = [];
let lastMessageId = 0;
let messagePollInterval = null;
let presenceInterval = null;
const csrfToken = $('meta[name="csrf-token"]').attr('content');
const currentUserId = <?= (int)$user['id'] ?>;

$(document).ready(function() {
  loadAvailableStaff();
  loadConversations();

  // Presence Heartbeat every 35 seconds
  presenceInterval = setInterval(sendPresencePing, 35000);

  // Auto-refresh conversations & active messages every 3.5 seconds
  messagePollInterval = setInterval(function() {
    if (!document.hidden) {
      pollActiveMessages();
      loadConversations(false);
    }
  }, 3500);

  // Keyboard shortcut: Enter sends message
  $('#messageInput').on('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      $('#chatForm').submit();
    }
  });
});

// -------------------------------------------------------------
// 1. Available Staff Loader & Presence
// -------------------------------------------------------------
function loadAvailableStaff() {
  $.get(APP_URL + '/api/messages.php?action=available_staff', function(res) {
    if (!res.success) return;
    availableStaff = res.staff || [];
    renderAvailableStaffBar(availableStaff);
    renderModalStaffList(availableStaff);
  });
}

function renderAvailableStaffBar(staff) {
  const container = $('#availableStaffGrid');
  if (staff.length === 0) {
    container.html('<div class="text-muted small py-1 px-2">No active clinic staff accounts found.</div>');
    $('#onlineStaffCount').text('0 Online');
    return;
  }

  const onlineCount = staff.filter(s => s.presence_status === 'online').length;
  $('#onlineStaffCount').text(`${onlineCount} Online`);

  let html = '';
  staff.forEach(s => {
    const isSelected = (activeRecipientId === s.id) ? 'active-staff' : '';
    const statusClass = s.presence_status === 'online' ? 'online' : '';
    html += `
      <div class="messenger-staff-card ${isSelected}" onclick="startChatWithUser(${s.id})" title="${escapeHtml(s.name)} · ${escapeHtml(s.availability_label)}">
        <div class="staff-avatar-wrap">
          <img src="${s.avatar}" class="staff-avatar" alt="${escapeHtml(s.name)}">
          <span class="presence-dot ${s.presence_badge}"></span>
        </div>
        <div class="staff-card-name">${escapeHtml(s.first_name || s.name)}</div>
        <div class="staff-card-status ${statusClass}">${s.presence_status === 'online' ? 'Online' : 'Offline'}</div>
      </div>
    `;
  });
  container.html(html);
}

function renderModalStaffList(staff) {
  const list = $('#modalStaffList');
  if (staff.length === 0) {
    list.html('<div class="p-3 text-center text-muted small">No clinic nurses available.</div>');
    return;
  }
  let html = '';
  staff.forEach(s => {
    html += `
      <a href="#" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-2 staff-modal-item" data-name="${escapeHtml(s.name).toLowerCase()}" onclick="startChatWithUser(${s.id}); $('#newCoordModal').modal('hide'); return false;">
        <div class="d-flex align-items-center gap-2">
          <div class="position-relative">
            <img src="${s.avatar}" class="staff-avatar" style="width:34px;height:34px;" alt="${escapeHtml(s.name)}">
            <span class="presence-dot ${s.presence_badge}"></span>
          </div>
          <div>
            <div class="fw-bold small text-dark mb-0">${escapeHtml(s.name)}</div>
            <div class="text-muted" style="font-size:0.75rem;">${escapeHtml(s.role)} · ${escapeHtml(s.availability_label)}</div>
          </div>
        </div>
        <span class="badge ${s.presence_status === 'online' ? 'bg-success-subtle text-success' : 'bg-light text-muted border'} rounded-pill" style="font-size:0.7rem;">
          ${s.presence_status.toUpperCase()}
        </span>
      </a>
    `;
  });
  list.html(html);
}

function filterModalStaff() {
  const q = $('#modalStaffSearch').val().toLowerCase().trim();
  $('.staff-modal-item').each(function() {
    const name = $(this).data('name') || '';
    $(this).toggle(name.indexOf(q) > -1);
  });
}

function sendPresencePing() {
  $.post(APP_URL + '/api/messages.php', { action: 'presence_ping', csrf_token: csrfToken });
}

// -------------------------------------------------------------
// 2. Start Chat With Specific Nurse (Reuses single thread)
// -------------------------------------------------------------
function startChatWithUser(recipientId) {
  activeRecipientId = recipientId;
  $.post(APP_URL + '/api/messages.php', {
    action: 'get_or_create_conversation',
    recipient_id: recipientId,
    csrf_token: csrfToken
  }, function(res) {
    if (res.success && res.conversation_id) {
      currentConversationId = res.conversation_id;
      loadConversations(false);
      openConversation(res.conversation_id, true);
    } else {
      toastError(res.message || 'Unable to open conversation.');
    }
  }).fail(function() {
    toastError('Failed to connect to messaging server.');
  });
}

function openNewCoordinationModal() {
  $('#modalStaffSearch').val('');
  $('.staff-modal-item').show();
  new bootstrap.Modal(document.getElementById('newCoordModal')).show();
}

// -------------------------------------------------------------
// 3. Conversation List Loader & Rendering
// -------------------------------------------------------------
function loadConversations(showSpinner = true) {
  $.get(APP_URL + '/api/messages.php?action=list_conversations', function(res) {
    if (!res.success) return;
    conversationList = res.conversations || [];
    renderConversationList(conversationList);

    if (currentConversationId && showSpinner) {
      openConversation(currentConversationId, true);
    } else if (!currentConversationId && conversationList.length > 0 && showSpinner && $(window).width() >= 992) {
      openConversation(conversationList[0].id, true);
    }
  });
}

function renderConversationList(convs) {
  const container = $('#convList');
  if (convs.length === 0) {
    container.html(`
      <div class="empty-state-card py-4 my-2 text-center">
        <div class="text-muted small mb-2">No coordination threads yet.</div>
        <button class="btn btn-outline-success btn-sm rounded-pill" onclick="openNewCoordinationModal()">
          <i class="bi bi-chat-plus me-1"></i> Coordinate with Clinic
        </button>
      </div>
    `);
    return;
  }

  let html = '';
  convs.forEach(c => {
    const other = c.counterpart || {};
    const isActive = (c.id == currentConversationId) ? 'active' : '';
    const isUnread = parseInt(c.unread_count) > 0;
    const time = c.last_message_time ? moment(c.last_message_time).fromNow() : '';
    const unreadClass = isUnread ? 'unread' : '';

    html += `
      <div class="messenger-thread-item ${isActive} ${unreadClass}" data-name="${escapeHtml(other.name || c.title || '').toLowerCase()}" onclick="openConversation(${c.id}, true)">
        <div class="messenger-avatar-wrap">
          <img src="${other.avatar || '<?= asset_url('images/default-avatar.svg') ?>'}" class="messenger-avatar" alt="${escapeHtml(other.name)}">
          <span class="presence-dot ${other.presence_badge || 'status-offline'}"></span>
        </div>
        <div class="messenger-thread-info">
          <div class="messenger-thread-top">
            <div class="messenger-thread-name">${escapeHtml(other.name || c.title || 'Clinic Nurse')}</div>
            <div class="messenger-thread-time">${time}</div>
          </div>
          <div class="messenger-thread-bottom">
            <div class="messenger-thread-snippet">${escapeHtml(c.last_message || 'Start coordination...')}</div>
            <div>
              ${isUnread ? `<span class="badge bg-danger rounded-pill">${c.unread_count}</span>` : ''}
            </div>
          </div>
        </div>
      </div>
    `;
  });
  container.html(html);
}

function filterConversations() {
  const query = $('#convSearchInput').val().toLowerCase().trim();
  $('.messenger-thread-item').each(function() {
    const name = $(this).data('name') || '';
    $(this).toggle(name.indexOf(query) > -1);
  });
}

// -------------------------------------------------------------
// 4. Open Conversation & Message Stream
// -------------------------------------------------------------
function openConversation(convId, resetView = true) {
  if (!convId) return;
  currentConversationId = convId;

  // Mobile layout switch
  if ($(window).width() < 992) {
    $('#convSidebar').addClass('is-mobile-hidden');
    $('#chatMain').removeClass('is-mobile-hidden');
  }

  $('.messenger-thread-item').removeClass('active');
  $(`.messenger-thread-item[onclick*="openConversation(${convId}"]`).addClass('active');

  $('#chatForm').show();
  $('#emptyPlaceholder').hide();

  if (resetView) {
    lastMessageId = 0;
    $('#messageStream').html('<div class="text-center text-muted my-auto"><div class="spinner-border spinner-border-sm text-success me-2"></div>Loading messages...</div>');
  }

  $.get(APP_URL + '/api/messages.php?action=get_messages&conversation_id=' + convId, function(res) {
    if (!res.success) {
      toastError(res.message || 'Failed to load messages.');
      return;
    }

    const conv = res.conversation || {};
    const other = conv.counterpart || {};
    activeRecipientId = other.id || 0;

    // Update Header
    $('#activeHeaderName').text(other.name || 'Clinic Nurse');
    $('#activeHeaderRole').text(other.role_label || 'Clinic Nurse');
    $('#activeHeaderAvatar').attr('src', other.avatar || '<?= asset_url('images/default-avatar.svg') ?>');
    $('#activePresenceDot').attr('class', `presence-dot ${other.presence_badge || 'status-offline'}`);
    $('#activeHeaderPresence').text(other.presence_label || 'Offline').attr('class', `badge ${other.is_online ? 'bg-success-subtle text-success' : 'bg-light text-muted border'} py-0 px-2`);

    // Render Full Message History
    renderMessageHistory(res.messages || []);
    scrollToBottom();
    $('#messageInput').focus();
  });
}

function renderMessageHistory(messages) {
  const container = $('#messageStream');
  if (messages.length === 0) {
    container.html('<div class="text-center text-muted my-auto py-5"><i class="bi bi-chat-heart fs-2 text-success d-block mb-2"></i>No messages yet. Send a note or coordination request below.</div>');
    lastMessageId = 0;
    return;
  }

  let html = '';
  let lastDateStr = '';
  messages.forEach(m => {
    const msgDate = new Date(m.created_at);
    const dateStr = msgDate.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    if (dateStr !== lastDateStr) {
      html += `<div class="messenger-date-divider"><span class="messenger-date-badge">${dateStr}</span></div>`;
      lastDateStr = dateStr;
    }

    const isOutgoing = (m.sender_id === currentUserId);
    const time = msgDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const senderName = isOutgoing ? 'You' : (m.first_name + ' ' + m.last_name + ' (Nurse)');

    html += `
      <div class="message-bubble-row ${isOutgoing ? 'outgoing' : 'incoming'}" id="msg-${m.id}">
        ${!isOutgoing ? `<div class="message-sender-name">${escapeHtml(senderName)}</div>` : ''}
        <div class="message-bubble-content">${escapeHtml(m.message_body)}</div>
        <div class="message-time-meta">${time}</div>
      </div>
    `;

    if (m.id > lastMessageId) lastMessageId = m.id;
  });

  container.html(html);
}

// -------------------------------------------------------------
// 5. Delta Message Polling
// -------------------------------------------------------------
function pollActiveMessages() {
  if (!currentConversationId || lastMessageId === 0) return;

  $.get(APP_URL + `/api/messages.php?action=get_messages&conversation_id=${currentConversationId}&after_id=${lastMessageId}`, function(res) {
    if (!res.success) return;
    const newMsgs = res.messages || [];
    if (newMsgs.length === 0) return;

    const container = $('#messageStream');
    newMsgs.forEach(m => {
      if ($(`#msg-${m.id}`).length > 0) return;

      const msgDate = new Date(m.created_at);
      const isOutgoing = (m.sender_id === currentUserId);
      const time = msgDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      const senderName = isOutgoing ? 'You' : (m.first_name + ' ' + m.last_name + ' (Nurse)');

      const msgHtml = `
        <div class="message-bubble-row ${isOutgoing ? 'outgoing' : 'incoming'}" id="msg-${m.id}">
          ${!isOutgoing ? `<div class="message-sender-name">${escapeHtml(senderName)}</div>` : ''}
          <div class="message-bubble-content">${escapeHtml(m.message_body)}</div>
          <div class="message-time-meta">${time}</div>
        </div>
      `;
      container.append(msgHtml);
      if (m.id > lastMessageId) lastMessageId = m.id;
    });

    scrollToBottom();
  });
}

// -------------------------------------------------------------
// 6. Message Sending
// -------------------------------------------------------------
function handleSendMessage(e) {
  e.preventDefault();
  const input = $('#messageInput');
  const text = input.val().trim();
  if (!text || !currentConversationId) return;

  const btn = $('#sendBtn');
  btn.prop('disabled', true);

  $.post(APP_URL + '/api/messages.php', {
    action: 'send_message',
    conversation_id: currentConversationId,
    message_body: text,
    csrf_token: csrfToken
  }, function(res) {
    btn.prop('disabled', false);
    if (res.success) {
      input.val('');
      toggleSendButton();
      pollActiveMessages();
      loadConversations(false);
    } else {
      toastError(res.message || 'Failed to send message.');
    }
  }).fail(function() {
    btn.prop('disabled', false);
    toastError('Network error while sending message.');
  });
}

function toggleSendButton() {
  const text = $('#messageInput').val().trim();
  $('#sendBtn').prop('disabled', text.length === 0);
}

function refreshActiveConversation() {
  if (currentConversationId) {
    openConversation(currentConversationId, false);
  }
}

function backToList() {
  $('#convSidebar').removeClass('is-mobile-hidden');
  $('#chatMain').addClass('is-mobile-hidden');
}

function scrollToBottom() {
  const el = document.getElementById('messageStream');
  if (el) {
    el.scrollTop = el.scrollHeight;
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return $('<div>').text(str).html();
}
</script>
</body>
</html>
