/* Shared data and navigation controller; presentation/actions live in messenger.js. */
const messagingIsNurse = window.MESSENGER_CONFIG.role === 'nurse';
const currentUserId = window.MESSENGER_CONFIG.userId;
const defaultChatAvatar = window.MESSENGER_CONFIG.avatar;
const csrfToken = $('meta[name="csrf-token"]').attr('content');
let currentConversationId = window.MESSENGER_CONFIG.conversationId;
let messageListFilter = 'all', activeRecipientId = 0, availableStaff = [], conversationList = [];
function pollActiveMessages() {} // Bound by the shared interaction controller on ready.
function renderMessageHistory() {}
function handleSendMessage(event) { event.preventDefault(); }
function loadAvailableStaff() {
  $.get(APP_URL + '/api/messages.php?action=' + (messagingIsNurse ? 'available_contacts' : 'available_staff'), function(res) {
    if (!res.success) return;
    availableStaff = res.staff || [];
    if (!messagingIsNurse) renderAvailableStaffBar(availableStaff);
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
          <img src="${escapeHtml(s.avatar)}" class="staff-avatar" alt="${escapeHtml(s.name)}">
          <span class="presence-dot ${s.presence_badge}"></span>
        </div>
        <div class="staff-card-name">${escapeHtml(s.first_name || s.name)}</div>
        <div class="staff-card-status ${statusClass}">${s.presence_status === 'online' ? 'Online' : s.presence_status === 'away' ? 'Away' : 'Offline'}</div>
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
      <a href="#" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-2 staff-modal-item" data-name="${escapeHtml(s.name).toLowerCase()}" onclick="startChatWithUser(${s.id}); BPCModal.hide('newMessageModal'); return false;">
        <div class="d-flex align-items-center gap-2">
          <div class="position-relative">
            <img src="${escapeHtml(s.avatar)}" class="staff-avatar" style="width:34px;height:34px;" alt="${escapeHtml(s.name)}">
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
  $.post(APP_URL + '/api/messages.php', { action: 'presence_ping', csrf_token: csrfToken, clinic_status: $('#myClinicStatusSelect').val() || '' });
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
      loadConversations(false);
      openConversation(res.conversation_id, true);
    } else {
      toastError(res.message || 'Unable to open conversation.');
    }
  }).fail(function() {
    toastError('Failed to connect to messaging server.');
  });
}

function openNewMessageModal() {
  loadAvailableStaff();
  $('#modalStaffSearch').val('');
  $('.staff-modal-item').show();
  BPCModal.show('newMessageModal');
}

// -------------------------------------------------------------
// 3. Conversation List Loader & Rendering
// -------------------------------------------------------------
function loadConversations(showSpinner = true) {
  $.get(APP_URL + '/api/messages.php?action=list_conversations', function(res) {
    if (!res.success) return;
    conversationList = res.conversations || [];
    if (messagingIsNurse && currentConversationId) {
      const grouped = conversationList.find(c => (c.conversation_ids || [c.id]).some(id => Number(id) === Number(currentConversationId)));
      if (grouped) currentConversationId = Number(grouped.id);
    }
    renderConversationList(conversationList);
    const active = conversationList.find(c => Number(c.id) === Number(currentConversationId));
    if (active) updateConversationHeader(active.counterpart);

    // If a conversation ID was requested in URL or current, open it
    if (currentConversationId && showSpinner) {
      openConversation(currentConversationId, true);
    } else if (!currentConversationId && conversationList.length > 0 && showSpinner && $(window).width() >= 992) {
      openConversation(conversationList[0].id, true);
    }
  }).fail(function() {
    $('#convList').html('<div class="empty-state-card p-4 m-3 text-center" role="alert"><p class="mb-2">Could not load messages.</p><button class="btn btn-outline-primary btn-sm" type="button" onclick="loadConversations()">Retry</button></div>');
  });
}

function renderConversationList(convs) {
  const container = $('#convList');
  if (convs.length === 0) {
    container.html(`
      <div class="empty-state-card py-4 my-2 text-center">
        <div class="text-muted small mb-2"><i class="bi bi-chat-square-text d-block fs-3 mb-2" aria-hidden="true"></i>No messages yet</div>
        <button class="btn btn-outline-success btn-sm rounded-pill" onclick="openNewMessageModal()">
          <i class="bi bi-chat-dots me-1"></i> Message Clinic
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
    const time = c.last_message_time ? formatMessageListTime(c.last_message_time) : '';
    const unreadClass = isUnread ? 'unread' : '';
    const lastMessage = c.last_message || 'Start consultation...';
    const senderLabel = Number(c.last_sender_id) === currentUserId ? 'You: ' : (other.name ? `${other.name.split(' ')[0]}: ` : '');
    const preview = /^\[Attachment\]/i.test(lastMessage) ? `${senderLabel}sent an attachment` : `${senderLabel}${lastMessage}`;

    html += `
      <div class="messenger-thread-item ${isActive} ${unreadClass}" role="button" tabindex="0" aria-label="Open conversation with ${escapeHtml(other.name || c.title || 'Clinic Nurse')}" data-name="${escapeHtml(other.name || c.title || '').toLowerCase()}" data-unread="${isUnread ? '1' : '0'}" data-role="${messagingIsNurse ? (other.role === 'teacher' || other.role === 'adviser' ? 'teacher' : 'student') : 'staff'}" onclick="openConversation(${c.id}, true)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openConversation(${c.id},true)}">
        <div class="messenger-avatar-wrap">
          <img src="${escapeHtml(other.avatar || defaultChatAvatar)}" class="messenger-avatar" alt="${escapeHtml(other.name)}">
          <span class="presence-dot ${other.presence_badge || 'status-offline'}"></span>
        </div>
        <div class="messenger-thread-info">
          <div class="messenger-thread-top">
            <div class="messenger-thread-name">${escapeHtml(other.name || c.title || 'Clinic Nurse')}${messagingIsNurse ? `<small class="messenger-role-badge">${escapeHtml(other.role_label)}</small>` : ''}</div>
            <div class="messenger-thread-time">${time}</div>
          </div>
          <div class="messenger-thread-bottom">
            <div class="messenger-thread-snippet">${escapeHtml(preview)}</div>
            <div>
              ${isUnread ? `<span class="badge bg-danger rounded-pill">${c.unread_count}</span>` : ''}
            </div>
          </div>
        </div>
      </div>
    `;
  });
  container.html(html);
  filterConversations();
}

function filterConversations() {
  const query = ($('#convSearchInput').val() || '').toLowerCase().trim();
  $('.messenger-thread-item').each(function() {
    const name = $(this).data('name') || '';
    const preview = ($(this).find('.messenger-thread-snippet').text() || '').toLowerCase();
    const role = $(this).data('role');
    const unreadMatch = messageListFilter !== 'unread' || $(this).data('unread') === 1;
    const roleMatch = messageListFilter === 'all' || messageListFilter === 'unread' || role === messageListFilter;
    $(this).prop('hidden', !(unreadMatch && roleMatch && (name.indexOf(query) > -1 || preview.indexOf(query) > -1)));
    $(this).toggle(unreadMatch && roleMatch && (name.indexOf(query) > -1 || preview.indexOf(query) > -1));
  });
}

function setMessageFilter(filter, button) {
  messageListFilter = filter;
  $('.messenger-filter-btn').removeClass('active');
  $(button).addClass('active');
  filterConversations();
}

// -------------------------------------------------------------
// 4. Open Conversation & Message Stream
// -------------------------------------------------------------
function renderAttachment(message) {
  if (!message.attachment_url || !message.attachment_name) return '';
  const size = Number(message.attachment_size || 0);
  const sizeLabel = size ? (size < 1048576 ? `${Math.max(1, Math.round(size / 1024))} KB` : `${(size / 1048576).toFixed(1)} MB`) : 'Attachment';
  return `<a class="message-attachment" href="${escapeHtml(message.attachment_url)}" target="_blank" rel="noopener"><i class="bi bi-paperclip" aria-hidden="true"></i><span>${escapeHtml(message.attachment_name)}<small>${sizeLabel}</small></span><i class="bi bi-download" aria-hidden="true"></i></a>`;
}

// -------------------------------------------------------------
// 5. Delta Message Polling (Appends new messages without flicker)
// -------------------------------------------------------------
function formatMessageListTime(value) {
  const input = String(value || '');
  const parsed = new Date(/[zZ]|[+-]\d{2}:?\d{2}$/.test(input) ? input : input.replace(' ', 'T') + '+08:00');
  if (Number.isNaN(parsed.getTime())) return '';
  const minutes = Math.max(0, Math.floor((Date.now() - parsed.getTime()) / 60000));
  if (minutes < 1) return 'now';
  if (minutes < 60) return `${minutes}m`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h`;
  const dayKey = date => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
  const today = dayKey(new Date());
  const yesterday = dayKey(new Date(Date.now() - 86400000));
  if (dayKey(parsed) === today) return `${Math.floor(hours)}h`;
  if (dayKey(parsed) === yesterday) return 'Yesterday';
  return new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric' }).format(parsed);
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
}
function updateMyClinicStatus(status) {
  const select = $('#myClinicStatusSelect');
  const previousStatus = select.data('saved-status') || select.val();
  if (!['available', 'busy', 'away'].includes(status)) return;

  const dot = $('#myStatusDot');
  dot.removeClass('status-online status-away status-offline');
  if (status === 'available') dot.addClass('status-online');
  else if (status === 'busy') dot.addClass('status-away');
  else dot.addClass('status-offline');
  select.prop('disabled', true);

  $.post(APP_URL + '/api/messages.php', {
    action: 'presence_ping',
    clinic_status: status,
    csrf_token: csrfToken
  }, function(res) {
    if (res && res.success) {
      select.data('saved-status', status);
      if (typeof showToast === 'function') showToast('Clinic status updated to ' + status.replace('_', ' '), 'success');
    } else {
      select.val(previousStatus);
      applyClinicStatusDot(previousStatus);
      if (typeof toastError === 'function') toastError(res?.message || 'Unable to update clinic status.');
    }
  }, 'json').fail(function() {
    select.val(previousStatus);
    applyClinicStatusDot(previousStatus);
    if (typeof toastError === 'function') toastError('Unable to update clinic status. Check your connection and try again.');
  }).always(function() { select.prop('disabled', false); });
}

function applyClinicStatusDot(status) {
  const dot = $('#myStatusDot');
  dot.removeClass('status-online status-away status-offline');
  dot.addClass(status === 'available' ? 'status-online' : (status === 'busy' ? 'status-away' : 'status-offline'));
}

function pingPresence() {
  const currentStatus = $('#myClinicStatusSelect').val();
  $.post(APP_URL + '/api/messages.php', {
    action: 'presence_ping',
    clinic_status: currentStatus,
    csrf_token: csrfToken
  });
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


function updateConversationHeader(other) {
  if (!other) return;
  activeRecipientId = Number(other.id);
  $('#activeHeaderName').text(other.name || 'Clinic conversation');
  $('#activeHeaderRole').text(other.role_label || '');
  $('#activeHeaderAvatar').attr('src', other.avatar || defaultChatAvatar);
  $('#activePresenceDot').attr('class', 'presence-dot ' + (other.presence_badge || 'status-offline'));
  $('#activeHeaderPresence').text(other.presence_label || (other.presence_status === 'online' ? 'Online' : other.presence_status === 'away' ? 'Away' : 'Offline'));
  $('#aiSummarizeBtn').prop('disabled', false);
  if (messagingIsNurse) {
    const patient = conversationList.find(c => Number(c.id) === Number(currentConversationId))?.counterpart || other;
    const studentId = Number(patient.student_record_id);
    const isStudent = patient.role === 'student';
    $('#patientWorkspaceBtn').prop('hidden', !isStudent).attr('href', isStudent ? APP_URL + '/pages/nurse/patient_history.php?' + (studentId ? 'id='+studentId : 'user_id='+Number(patient.id)) : '');
  }
}
function openConversation(id) {
  if (!id) return;
  currentConversationId = Number(id);
  if (window.innerWidth < 992) { $('#convSidebar').addClass('is-mobile-hidden'); $('#chatMain').removeClass('is-mobile-hidden'); }
  $('#chatForm').css('display','flex'); $('#quickRepliesBar').show(); $('#aiSummaryBox').hide();
  const conv = conversationList.find(c => Number(c.id) === Number(id));
  if (conv) updateConversationHeader(conv.counterpart);
  renderConversationList(conversationList);
  $('#messageStream').html('<div class="messenger-empty" role="status">Loading messages?</div>');
}
function refreshActiveConversation() { pollActiveMessages(); loadConversations(false); }
function backToList() { $('#convSidebar').removeClass('is-mobile-hidden'); $('#chatMain').addClass('is-mobile-hidden'); }
function toggleSendButton() {
  $('#sendBtn').prop('disabled', !currentConversationId || (!$('#messageInput').val().trim() && !$('#messageAttachment')[0].files.length));
}
function resizeMessageInput(field) { field.style.height='auto'; field.style.height=Math.min(field.scrollHeight,140)+'px'; }
function insertQuickReply(text) { $('#messageInput').val(text).trigger('input').focus(); toggleSendButton(); }
$(function() {
  loadAvailableStaff(); loadConversations(); toggleSendButton();
  const poll = setInterval(function() { if (!document.hidden) { pollActiveMessages(); loadConversations(false); } }, 5000);
  const presence = setInterval(function() { if (!document.hidden) { sendPresencePing(); if (!messagingIsNurse) loadAvailableStaff(); } }, 35000);
  sendPresencePing();
  $('#messageAttachment').on('change', function() { $('#attachmentName').text(this.files[0]?.name || ''); $('#chatForm').toggleClass('has-attachment', !!this.files.length); toggleSendButton(); });
  window.addEventListener('pagehide',function() { clearInterval(poll); clearInterval(presence); });
  function fitViewport() {
    const panel = document.querySelector('.messenger-container');
    if (panel && window.innerWidth < 768) {
      const available = Math.max(180, (window.visualViewport?.height || window.innerHeight) - Math.max(0,panel.getBoundingClientRect().top) - 12);
      panel.style.setProperty('--messenger-available-height', available+'px');
    }
  }
  window.visualViewport?.addEventListener('resize',fitViewport);
  window.addEventListener('resize',fitViewport);
  fitViewport();
});
