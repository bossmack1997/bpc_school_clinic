<?php
/**
 * pages/nurse/messages.php
 * =====================================================================
 * Nurse Clinic Communications Hub — Messenger-Style Triage & Patient Chat
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Clinic Communications Hub';
$user = current_user();
$currentClinicStatus = $user['clinic_status'] ?? 'available';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .nurse-status-selector {
      background: #ffffff;
      border: 1px solid var(--border, #E2E8F0);
      border-radius: 20px;
      padding: 4px 10px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.82rem;
    }
    .nurse-status-selector select {
      border: none;
      background: transparent;
      font-weight: 600;
      font-size: 0.82rem;
      color: var(--text, #1E293B);
      cursor: pointer;
      outline: none;
    }
    .quick-replies-bar {
      display: flex;
      gap: 6px;
      overflow-x: auto;
      padding: 6px 16px 2px;
      background: #ffffff;
      border-top: 1px solid var(--border, #E2E8F0);
    }
    .quick-reply-chip {
      white-space: nowrap;
      font-size: 0.75rem;
      padding: 4px 10px;
      border-radius: 12px;
      background: #F1F5F9;
      color: #334155;
      border: 1px solid #E2E8F0;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .quick-reply-chip:hover {
      background: var(--primary-light, #E8F5E9);
      color: var(--primary, #0B6B3A);
      border-color: var(--primary, #0B6B3A);
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
        
        <!-- Left Column: Conversations List & Triage Filters -->
        <div class="messenger-sidebar" id="convSidebar">
          
          <!-- Top Header with Status Selector -->
          <div class="p-3 border-bottom bg-white">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-chat-heart-fill text-success"></i> Clinic Inbox
              </h5>
              <span class="badge bg-success-subtle text-success rounded-pill px-2" id="unreadBadgeCounter" style="display:none;">0 New</span>
            </div>
            
            <!-- Quick Status Switcher for Nurse Availability -->
            <div class="d-flex align-items-center justify-content-between pt-1">
              <span class="text-muted small">My Clinic Status:</span>
              <div class="nurse-status-selector">
                <span class="presence-dot <?= $currentClinicStatus === 'available' ? 'status-online' : ($currentClinicStatus === 'busy' ? 'status-away' : 'status-offline') ?>" id="myStatusDot"></span>
                <select id="myClinicStatusSelect" onchange="updateMyClinicStatus(this.value)">
                  <option value="available" <?= $currentClinicStatus === 'available' ? 'selected' : '' ?>>Available</option>
                  <option value="busy" <?= $currentClinicStatus === 'busy' ? 'selected' : '' ?>>Busy / In Consult</option>
                  <option value="away" <?= $currentClinicStatus === 'away' ? 'selected' : '' ?>>Away from Desk</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Search Input -->
          <div class="messenger-search-bar">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control border-start-0 ps-0 bg-white" id="convSearchInput" placeholder="Search patient name, ID, or course..." oninput="filterConversations()">
            </div>
          </div>

          <!-- Triage Filter Pills -->
          <div class="messenger-filter-pills">
            <button class="messenger-filter-btn active" onclick="setFilter('all', this)">All</button>
            <button class="messenger-filter-btn" onclick="setFilter('student_nurse', this)">Students</button>
            <button class="messenger-filter-btn" onclick="setFilter('teacher_nurse', this)">Teachers</button>
            <button class="messenger-filter-btn" onclick="setFilter('unread', this)">Unread</button>
          </div>

          <!-- Conversation Threads List -->
          <div class="messenger-thread-list" id="convList">
            <div class="text-center text-muted p-4">
              <div class="spinner-border spinner-border-sm text-success me-2"></div>Loading triage threads...
            </div>
          </div>
        </div>

        <!-- Right Column: Active Chat View & Clinical Actions -->
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
                <div class="d-flex align-items-center gap-2">
                  <div class="fw-bold fs-6 text-dark" id="activeHeaderName">Select a Patient Thread</div>
                  <span class="badge bg-secondary-subtle text-secondary border py-0 px-2" style="font-size:0.68rem;" id="activeHeaderRoleBadge">Patient</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <small class="text-muted" id="activeHeaderSubtext">Select a thread from the left panel</small>
                  <span class="badge bg-light text-muted border py-0 px-2" style="font-size:0.68rem;" id="activeHeaderPresence">Offline</span>
                </div>
              </div>
            </div>
            
            <div class="d-flex align-items-center gap-2" id="activeHeaderActions" style="display:none !important;">
              <!-- Patient Profile / Workspace shortcut -->
              <a href="#" class="btn btn-outline-primary btn-sm rounded-pill d-none d-md-inline-flex align-items-center gap-1" id="patientWorkspaceBtn" target="_blank" title="Open Patient Workspace">
                <i class="bi bi-person-bounding-box"></i> <span>Patient Record</span>
              </a>
              <!-- MICA Triage Summary -->
              <button class="btn btn-outline-success btn-sm rounded-pill d-flex align-items-center gap-1" id="aiSummarizeBtn" onclick="requestAISummary()" title="MICA Clinical Extraction">
                <i class="bi bi-stars"></i> <span>MICA Summary</span>
              </button>
              <!-- Refresh button -->
              <button class="btn btn-sm btn-light border rounded-circle" onclick="refreshActiveConversation()" title="Refresh messages">
                <i class="bi bi-arrow-clockwise"></i>
              </button>
            </div>
          </div>

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

          <!-- Messages Stream Area -->
          <div class="messenger-messages-area" id="messageStream">
            <div class="empty-state-card my-auto text-center p-4" id="emptyPlaceholder">
              <div class="empty-state-icon text-success bg-success-light mx-auto mb-2" style="width:54px;height:54px;font-size:1.4rem;">
                <i class="bi bi-chat-heart"></i>
              </div>
              <h6 class="fw-bold text-dark mb-1">Clinic Communications Hub</h6>
              <p class="text-muted small mb-0">Select a student or teacher conversation thread from the left triage panel to view history and reply.</p>
            </div>
          </div>

          <!-- Quick Clinical Replies Bar -->
          <div class="quick-replies-bar" id="quickRepliesBar" style="display:none;">
            <span class="quick-reply-chip" onclick="insertQuickReply('Please report to the clinic for vital signs and physical assessment.')"> Report to Clinic</span>
            <span class="quick-reply-chip" onclick="insertQuickReply('Take a 15-minute rest, hydrate with water, and monitor your symptoms.')"> Rest & Hydrate</span>
            <span class="quick-reply-chip" onclick="insertQuickReply('An excuse slip / clinic clearance has been issued.')"> Slip Issued</span>
            <span class="quick-reply-chip" onclick="insertQuickReply('Please consult an external physician or hospital for further diagnostics.')"> External Referral</span>
          </div>

          <!-- Input Composer -->
          <form class="messenger-composer-area" id="chatForm" onsubmit="handleSendMessage(event)" style="display:none;">
            <input type="text" class="form-control messenger-input" id="messageInput" placeholder="Type clinical response or instructions..." required maxlength="3000" autocomplete="off" oninput="toggleSendButton()">
            <button type="submit" class="messenger-send-btn shadow-sm" id="sendBtn" title="Send Response (Enter)">
              <i class="bi bi-send-fill"></i>
            </button>
          </form>

        </div>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>

<script>
/**
 * Nurse Messenger Portal Logic
 */
let currentFilter = 'all';
let currentConversationId = null;
let activeCounterpart = null;
let rawConversations = [];
let highestMessageId = 0;
let messagePollingTimer = null;
let isFetchingMessages = false;
let isFirstLoad = true;

const csrfToken = $('meta[name="csrf-token"]').attr('content');
const currentUserId = <?= (int)$user['id'] ?>;

$(document).ready(function() {
  loadConversations(true);

  // Periodic Polling
  // 1. Refresh thread list every 8 seconds
  setInterval(function() {
    loadConversations(false);
  }, 8000);

  // 2. Presence Heartbeat every 30 seconds
  setInterval(function() {
    pingPresence();
  }, 30000);

  // Enter to send in composer
  $('#messageInput').on('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      $('#chatForm').submit();
    }
  });
});

// Update Nurse Clinic Status (Available / Busy / Away)
function updateMyClinicStatus(status) {
  const dot = $('#myStatusDot');
  dot.removeClass('status-online status-away status-offline');
  if (status === 'available') dot.addClass('status-online');
  else if (status === 'busy') dot.addClass('status-away');
  else dot.addClass('status-offline');

  $.post(APP_URL + '/api/messages.php', {
    action: 'presence_ping',
    clinic_status: status,
    csrf_token: csrfToken
  }, function(res) {
    if (typeof showToast === 'function') {
      showToast('Clinic status updated to ' + status, 'info');
    }
  });
}

function pingPresence() {
  const currentStatus = $('#myClinicStatusSelect').val();
  $.post(APP_URL + '/api/messages.php', {
    action: 'presence_ping',
    clinic_status: currentStatus,
    csrf_token: csrfToken
  });
}

function setFilter(type, btn) {
  currentFilter = type;
  $('.messenger-filter-btn').removeClass('active');
  $(btn).addClass('active');
  filterConversations();
}

function loadConversations(showLoading = false) {
  if (showLoading && rawConversations.length === 0) {
    $('#convList').html('<div class="text-center text-muted p-4"><div class="spinner-border spinner-border-sm text-success me-2"></div>Loading triage threads...</div>');
  }

  $.get(APP_URL + '/api/messages.php?action=list_conversations&type=all', function(res) {
    if (res.success) {
      rawConversations = res.conversations || [];
      updateTotalUnreadBadge(rawConversations);
      renderConversationList();
    }
  });
}

function updateTotalUnreadBadge(convs) {
  let totalUnread = 0;
  convs.forEach(c => {
    totalUnread += parseInt(c.unread_count || 0);
  });
  if (totalUnread > 0) {
    $('#unreadBadgeCounter').text(totalUnread + 'New').show();
  } else {
    $('#unreadBadgeCounter').hide();
  }
}

function filterConversations() {
  const query = ($('#convSearchInput').val() || '').toLowerCase().trim();
  renderConversationList(query);
}

function renderConversationList(query = '') {
  let filtered = rawConversations.slice();

  // Apply Filter Pill
  if (currentFilter === 'student_nurse') {
    filtered = filtered.filter(c => c.conversation_type === 'student_nurse');
  } else if (currentFilter === 'teacher_nurse') {
    filtered = filtered.filter(c => c.conversation_type === 'teacher_nurse');
  } else if (currentFilter === 'unread') {
    filtered = filtered.filter(c => parseInt(c.unread_count || 0) > 0);
  }

  // Apply Search Query
  if (query) {
    filtered = filtered.filter(c => {
      const title = (c.title || '').toLowerCase();
      const name = (c.counterpart && c.counterpart.name ? c.counterpart.name : '').toLowerCase();
      const sub = (c.counterpart && c.counterpart.subtext ? c.counterpart.subtext : '').toLowerCase();
      const msg = (c.last_message || '').toLowerCase();
      return title.includes(query) || name.includes(query) || sub.includes(query) || msg.includes(query);
    });
  }

  if (filtered.length === 0) {
    $('#convList').html(`
      <div class="empty-state-card py-4 my-2">
        <div class="text-muted small">${query ? 'No matching conversations found.' : 'No active triage threads.'}</div>
      </div>
    `);
    return;
  }

  let html = '';
  filtered.forEach(c => {
    const isActive = (c.id == currentConversationId) ? 'active' : '';
    const isUnread = parseInt(c.unread_count || 0) > 0;
    const time = c.last_message_time ? formatRelativeTime(c.last_message_time) : '';
    const cp = c.counterpart || {};
    const avatar = cp.avatar || (APP_URL + '/assets/images/default-avatar.svg');
    const name = cp.name || c.title || 'Patient Thread';
    const subtext = cp.subtext || (c.conversation_type === 'teacher_nurse' ? 'Teacher' : 'Student');
    const presenceClass = cp.presence_badge || 'status-offline';
    const isTeacher = (c.conversation_type === 'teacher_nurse');
    const roleBadge = isTeacher 
      ? '<span class="badge bg-primary-subtle text-primary ms-1" style="font-size:0.65rem;">Teacher</span>'
      : '<span class="badge bg-success-subtle text-success ms-1" style="font-size:0.65rem;">Student</span>';

    html += `
      <div class="messenger-thread-item ${isActive} ${isUnread ? 'unread' : ''}" onclick="selectConversation(${c.id})">
        <div class="messenger-avatar-wrap">
          <img src="${avatar}" class="messenger-avatar" alt="${escapeHtml(name)}" onerror="this.src='${APP_URL}/assets/images/default-avatar.svg'">
          <span class="presence-dot ${presenceClass}"></span>
        </div>
        <div class="messenger-thread-info">
          <div class="messenger-thread-top">
            <div class="messenger-thread-name">${escapeHtml(name)} ${roleBadge}</div>
            <div class="messenger-thread-time">${time}</div>
          </div>
          <div class="text-muted" style="font-size:0.72rem; margin-top:-2px; margin-bottom:2px;">${escapeHtml(subtext)}</div>
          <div class="messenger-thread-bottom">
            <div class="messenger-thread-snippet">${escapeHtml(c.last_message || 'No messages yet')}</div>
            ${isUnread ? `<span class="badge bg-danger rounded-pill px-2" style="font-size:0.68rem;">${c.unread_count}</span>` : ''}
          </div>
        </div>
      </div>
    `;
  });

  $('#convList').html(html);
}

function selectConversation(convId) {
  currentConversationId = convId;
  highestMessageId = 0;
  isFirstLoad = true;

  // Find conversation object
  const conv = rawConversations.find(c => c.id == convId);
  if (conv) {
    activeCounterpart = conv.counterpart || {};
    const cp = activeCounterpart;
    const name = cp.name || conv.title || 'Patient Thread';
    const isTeacher = (conv.conversation_type === 'teacher_nurse');

    $('#activeHeaderName').text(name);
    $('#activeHeaderAvatar').attr('src', cp.avatar || (APP_URL + '/assets/images/default-avatar.svg'));
    
    // Role Badge & Subtext
    $('#activeHeaderRoleBadge').text(isTeacher ? 'Teacher' : 'Student')
      .attr('class', isTeacher ? 'badge bg-primary-subtle text-primary border py-0 px-2' : 'badge bg-success-subtle text-success border py-0 px-2');
    
    $('#activeHeaderSubtext').text(cp.subtext || (isTeacher ? 'Faculty Member' : 'Student Patient'));

    // Presence Indicator
    const pDot = $('#activePresenceDot');
    pDot.removeClass('status-online status-away status-offline').addClass(cp.presence_badge || 'status-offline');
    
    const pLabel = $('#activeHeaderPresence');
    pLabel.removeClass('bg-success-subtle text-success bg-warning-subtle text-warning bg-light text-muted');
    if (cp.presence_status === 'online') {
      pLabel.addClass('bg-success-subtle text-success').text('Active now');
    } else if (cp.presence_status === 'away') {
      pLabel.addClass('bg-warning-subtle text-warning').text('Away');
    } else {
      pLabel.addClass('bg-light text-muted').text('Offline');
    }

    // Patient Workspace / Record link (for students)
    if (!isTeacher && (cp.student_record_id || cp.id)) {
      const studentParam = cp.student_record_id ? ('id=' + cp.student_record_id) : ('user_id=' + cp.id);
      $('#patientWorkspaceBtn').attr('href', APP_URL + '/pages/nurse/patient-workspace.php?' + studentParam).show();
    } else {
      $('#patientWorkspaceBtn').hide();
    }

    // Mark as read in local array
    conv.unread_count = 0;
    updateTotalUnreadBadge(rawConversations);
  }

  // Show Header Actions, Quick Replies Bar, and Composer
  $('#activeHeaderActions').attr('style', 'display: flex !important;');
  $('#quickRepliesBar').show();
  $('#chatForm').show();
  $('#aiSummaryBox').hide();

  // Highlight active thread in left sidebar
  $('.messenger-thread-item').removeClass('active');
  renderConversationList($('#convSearchInput').val() || '');

  // Mobile Layout Switch
  if ($(window).width() < 992) {
    $('#convSidebar').addClass('is-mobile-hidden');
    $('#chatMain').removeClass('is-mobile-hidden');
  }

  // Clear previous message stream and start delta polling
  $('#messageStream').html('<div class="text-center text-muted my-auto"><div class="spinner-border spinner-border-sm text-success me-2"></div>Loading message history...</div>');
  
  fetchDeltaMessages(true);

  // Set delta polling timer
  if (messagePollingTimer) clearInterval(messagePollingTimer);
  messagePollingTimer = setInterval(function() {
    if (currentConversationId && !isFetchingMessages) {
      fetchDeltaMessages(false);
    }
  }, 3500);

  setTimeout(() => $('#messageInput').focus(), 150);
}

function fetchDeltaMessages(initialLoad = false) {
  if (!currentConversationId || isFetchingMessages) return;
  isFetchingMessages = true;

  const afterParam = (initialLoad || highestMessageId === 0) ? '' : ('&after_id=' + highestMessageId);

  $.get(APP_URL + '/api/messages.php?action=get_messages&conversation_id=' + currentConversationId + afterParam, function(res) {
    isFetchingMessages = false;
    if (res.success) {
      const messages = res.messages || [];

      if (initialLoad) {
        $('#messageStream').empty();
        if (messages.length === 0) {
          $('#messageStream').html(`
            <div class="empty-state-card my-auto text-center p-4">
              <div class="empty-state-icon text-muted bg-light mx-auto mb-2" style="width:48px;height:48px;font-size:1.2rem;">
                <i class="bi bi-chat-text"></i>
              </div>
              <h6 class="fw-bold text-dark mb-1">No Messages Yet</h6>
              <p class="text-muted small mb-0">Type a message below to start or respond to this patient consultation.</p>
            </div>
          `);
          return;
        }
      }

      if (messages.length > 0) {
        // Remove empty placeholder if any
        $('#emptyPlaceholder, .empty-state-card').remove();

        let appendHtml = '';
        let lastDateBadge = $('.messenger-date-badge').last().text() || '';

        messages.forEach(m => {
          const mId = parseInt(m.id);
          if (mId > highestMessageId) {
            highestMessageId = mId;
          }

          const msgDate = new Date(m.created_at);
          const dateStr = formatChatDate(msgDate);
          if (dateStr !== lastDateBadge) {
            appendHtml += `<div class="messenger-date-divider"><span class="messenger-date-badge">${dateStr}</span></div>`;
            lastDateBadge = dateStr;
          }

          const isOutgoing = (parseInt(m.sender_id) === currentUserId);
          const time = formatMessageTime(msgDate);
          const senderName = isOutgoing ? 'You (Nurse)' : (m.first_name + ' ' + m.last_name);

          appendHtml += `
            <div class="message-bubble-row ${isOutgoing ? 'outgoing' : 'incoming'}" id="msg-${m.id}">
              ${!isOutgoing ? `<div class="message-sender-name">${escapeHtml(senderName)}</div>` : ''}
              <div class="message-bubble-content">${escapeHtml(m.message_body)}</div>
              <div class="message-time-meta">${time}</div>
            </div>
          `;
        });

        $('#messageStream').append(appendHtml);
        scrollToBottom();
      }
    }
  }).fail(function() {
    isFetchingMessages = false;
  });
}

function handleSendMessage(e) {
  e.preventDefault();
  const text = $('#messageInput').val().trim();
  if (!text || !currentConversationId) return;

  $('#sendBtn').prop('disabled', true);

  $.post(APP_URL + '/api/messages.php', {
    action: 'send_message',
    conversation_id: currentConversationId,
    message_body: text,
    csrf_token: csrfToken
  }, function(res) {
    $('#sendBtn').prop('disabled', false);
    if (res.success) {
      $('#messageInput').val('');
      toggleSendButton();
      fetchDeltaMessages(false);
      loadConversations(false);
    } else {
      Swal.fire('Error', res.message || 'Failed to send message.', 'error');
    }
  }).fail(function() {
    $('#sendBtn').prop('disabled', false);
    Swal.fire('Error', 'Network error. Failed to send message.', 'error');
  });
}

function insertQuickReply(text) {
  $('#messageInput').val(text).focus();
  toggleSendButton();
}

function toggleSendButton() {
  const hasText = ($('#messageInput').val() || '').trim().length > 0;
  $('#sendBtn').css('opacity', hasText ? '1' : '0.6');
}

function refreshActiveConversation() {
  if (currentConversationId) {
    highestMessageId = 0;
    fetchDeltaMessages(true);
  }
}

function requestAISummary() {
  if (!currentConversationId) return;
  
  $('#aiSummarizeBtn').prop('disabled', true);
  $('#aiSummaryContent').html('<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-success me-2"></div>Analyzing conversation & generating MICA Triage Summary...</div>');
  $('#aiSummaryBox').slideDown(200);

  $.post(APP_URL + '/api/messages.php', {
    action: 'ai_summarize',
    conversation_id: currentConversationId,
    csrf_token: csrfToken
  }, function(res) {
    $('#aiSummarizeBtn').prop('disabled', false);
    if (res.success) {
      $('#aiSummaryContent').text(res.summary);
    } else {
      $('#aiSummaryContent').html('<span class="text-danger">' + escapeHtml(res.message || 'Unable to generate summary.') + '</span>');
    }
  }).fail(function() {
    $('#aiSummarizeBtn').prop('disabled', false);
    $('#aiSummaryContent').html('<span class="text-danger">Failed to connect to MICA AI summary service.</span>');
  });
}

function copyAISummary() {
  const text = $('#aiSummaryContent').text();
  if (text) {
    navigator.clipboard.writeText(text).then(function() {
      if (typeof showToast === 'function') {
        showToast('MICA Summary copied to clipboard!', 'success');
      } else {
        Swal.fire({
          icon: 'success',
          title: 'Copied!',
          text: 'MICA Summary copied to clipboard.',
          timer: 1500,
          showConfirmButton: false
        });
      }
    });
  }
}

function backToList() {
  $('#convSidebar').removeClass('is-mobile-hidden');
  $('#chatMain').addClass('is-mobile-hidden');
}

function scrollToBottom() {
  const container = document.getElementById('messageStream');
  if (container) {
    container.scrollTop = container.scrollHeight;
  }
}

function formatChatDate(d) {
  const today = new Date();
  const yesterday = new Date(today);
  yesterday.setDate(yesterday.getDate() - 1);

  if (d.toDateString() === today.toDateString()) return 'Today';
  if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
  return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatMessageTime(d) {
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatRelativeTime(dateStr) {
  if (typeof moment !== 'undefined') {
    return moment(dateStr).fromNow();
  }
  const d = new Date(dateStr);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(str) {
  if (!str) return '';
  return $('<div>').text(str).html();
}
</script>
</body>
</html>
