/* Shared conversation behavior for the three clinic messaging portals. */
$(() => {
  'use strict';
  const api = APP_URL + '/api/messages.php';
  const stream = document.getElementById('messageStream');
  const input = document.getElementById('messageInput');
  let records = [], reply = null, sending = false, request = null, loadedThread = 0, enabled = false, hasMore = false, renderSignature = '';
  const drafts = new Map();
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const date = value => new Date(String(value).replace(' ', 'T') + (/[zZ]|[+-]\d{2}:?\d{2}$/.test(value) ? '' : '+08:00'));
  const nearBottom = () => stream.scrollHeight - stream.scrollTop - stream.clientHeight < 100;
  const notice = message => { $('#messengerNotice').text(message).toggle(!!message); };
  $('#chatForm').prepend('<div id="replyPreview" hidden></div><div id="messengerNotice" role="status" aria-live="polite" style="display:none"></div>');
  $('#messageStream').attr({'role':'log','aria-label':'Conversation messages','aria-live':'polite'});
  $('#emptyPlaceholder h6').text('Your Messages');
  $('#emptyPlaceholder p').text('Select a conversation to start communicating with the clinic.');
  $('.messenger-header').append('<button type="button" class="btn btn-sm btn-light messenger-info" aria-label="Conversation information" title="Conversation information"><i class="bi bi-info-circle" aria-hidden="true"></i></button>');
  $('.messenger-info').on('click',function() {
    if (!currentConversationId) return;
    Swal.fire({title:$('#activeHeaderName').text(),text:[$('#activeHeaderRoleBadge, #activeHeaderRole').text(),$('#activeHeaderPresence').text()].filter(Boolean).join(' · '),confirmButtonColor:'#087443'});
  });
  $('.messenger-filter-btn').each(function() { $(this).attr('aria-pressed', $(this).hasClass('active') ? 'true' : 'false'); });
  $(document).on('click', '.messenger-filter-btn', function() {
    $('.messenger-filter-btn').attr('aria-pressed','false'); $(this).attr('aria-pressed','true');
  });

  function draw(messages) {
    const signature = JSON.stringify([messages, hasMore, enabled]);
    if (signature === renderSignature) return;
    renderSignature = signature;
    const bottom = nearBottom(), oldTop = stream.scrollTop;
    records = messages;
    let html = hasMore ? '<button class="messenger-history btn btn-sm btn-light mx-auto" type="button">Load earlier messages</button>' : '', previous = null;
    for (const m of messages) {
      const time = date(m.created_at), own = Number(m.sender_id) === currentUserId;
      const gap = !previous || time - date(previous.created_at) > 300000 || time.toDateString() !== date(previous.created_at).toDateString();
      const grouped = !gap && Number(previous.sender_id) === Number(m.sender_id);
      if (gap) html += `<div class="messenger-date-divider"><span>${esc(time.toLocaleString('en-PH',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit',timeZone:'Asia/Manila'}))}</span></div>`;
      const exact = esc(time.toLocaleString('en-PH',{timeZone:'Asia/Manila'}));
      html += `<div class="message-bubble-row ${own ? 'outgoing':'incoming'} ${grouped ? 'is-continuation':''} ${m.deleted ? 'is-removed':''}" id="msg-${Number(m.id)}" data-sender-id="${Number(m.sender_id)}">
        <div class="message-bubble-line">${!own ? `<img class="message-avatar" src="${esc(m.avatar)}" alt="" ${grouped ? 'style="visibility:hidden"':''}>` : ''}
        <div class="message-bubble-content" title="${exact}">${m.reply_to_id ? `<button class="message-quote" data-jump="${Number(m.reply_to_id)}">Reply · ${esc(m.reply_preview || 'Attachment')}</button>`:''}${esc(m.message_body)}${!m.deleted && typeof renderAttachment === 'function' ? renderAttachment(m):''}</div></div>
        ${m.reactions?.length ? `<div class="message-reactions">${m.reactions.map(r => `<button data-action="react" data-id="${Number(m.id)}" data-emoji="${esc(r.emoji)}" aria-label="React ${esc(r.emoji)}">${esc(r.emoji)} ${Number(r.count)}</button>`).join('')}</div>`:''}
        <div class="message-actions" aria-label="Message actions"><button data-action="copy" data-id="${Number(m.id)}" aria-label="Copy message">Copy</button>${enabled && !m.deleted ? `<button data-action="reply" data-id="${Number(m.id)}">Reply</button><button data-action="react" data-id="${Number(m.id)}" data-emoji="👍" aria-label="Like message">👍</button><button data-action="react" data-id="${Number(m.id)}" data-emoji="❤️" aria-label="React with heart">❤️</button>${own ? `<button data-action="delete" data-id="${Number(m.id)}">Remove</button>`:''}`:''}</div>
        ${own && m === messages[messages.length-1] ? `<div class="message-time-meta">${m.read_at ? 'Seen':'Sent'}</div>`:''}</div>`;
      previous = m;
    }
    if (!messages.length) html = `<div class="messenger-empty"><i class="bi bi-chat-heart"></i><h3>Start your conversation</h3><p>Send a message to ${esc($('#activeHeaderName').text())}.</p></div>`;
    if (stream.innerHTML !== html) { stream.innerHTML = html; stream.scrollTop = bottom ? stream.scrollHeight : oldTop; }
  }

  async function refresh(initial = false) {
    const id = Number(currentConversationId);
    if (!id || document.hidden) return;
    if (window.innerWidth < 768 && $('#chatMain').hasClass('is-mobile-hidden')) return;
    if (request) { if (request.thread === id) return; request.xhr.abort(); }
    const xhr = $.get(api, {action:'get_messages', conversation_id:id});
    const pending = {thread:id, xhr}; request = pending;
    try {
      const res = await xhr;
      if (Number(currentConversationId) !== id) return;
      if (!res.success) { notice(res.message || 'Conversation unavailable.'); return; }
      enabled = !!res.interactions_enabled;
      if (typeof updateConversationHeader === 'function') updateConversationHeader(res.conversation?.counterpart);
      const active = conversationList.find(c => Number(c.id) === id);
      if (active && Number(active.unread_count)) { active.unread_count = 0; renderConversationList(conversationList); }
      const fresh = loadedThread !== id;
      loadedThread = id;
      if (fresh) hasMore = !!res.has_more;
      const merged = fresh ? new Map() : new Map(records.map(m => [Number(m.id),m]));
      (res.messages || []).forEach(m => merged.set(Number(m.id),m));
      draw([...merged.values()].sort((a,b) => Number(a.id)-Number(b.id)));
      if (fresh || initial) stream.scrollTop = stream.scrollHeight;
      notice('');
    } catch (e) { if (e.statusText !== 'abort') notice('Connection interrupted. Messages will retry automatically.'); }
    finally { if (request === pending) request = null; }
  }

  // Existing timers call these functions; share one in-flight request.
  if (typeof fetchDeltaMessages === 'function') fetchDeltaMessages = refresh;
  if (typeof pollActiveMessages === 'function') pollActiveMessages = () => refresh();
  if (typeof renderMessageHistory === 'function') renderMessageHistory = messages => { draw(messages); refresh(); };

  function wrapSelection(original) {
    return function(id, ...args) {
      if (currentConversationId) drafts.set(Number(currentConversationId), input.value);
      if (Number(id) !== Number(currentConversationId)) $('#messageAttachment').val('').trigger('change');
      reply = null; $('#replyPreview').prop('hidden', true);
      renderSignature = '';
      input.value = drafts.get(Number(id)) || '';
      original(id, ...args);
      if (window.innerWidth < 992) { $('#convSidebar').addClass('is-mobile-hidden'); $('#chatMain').removeClass('is-mobile-hidden'); }
      refresh();
      toggleSendButton();
    };
  }
  if (typeof selectConversation === 'function') selectConversation = wrapSelection(selectConversation);
  if (typeof openConversation === 'function') openConversation = wrapSelection(openConversation);

  handleSendMessage = async function(event) {
    event.preventDefault();
    const text = input.value, file = $('#messageAttachment')[0]?.files[0], id = Number(currentConversationId);
    if (sending || !id || (!text.trim() && !file)) return;
    sending = true; $('#sendBtn').prop('disabled',true).attr('aria-label','Sending message');
    const data = new FormData();
    Object.entries({action:'send_message',conversation_id:id,message_body:text,csrf_token:csrfToken,reply_to_id:reply?.id || 0}).forEach(([k,v]) => data.append(k,v));
    if (file) data.append('attachment',file);
    notice('Sending…');
    try {
      const res = await $.ajax({url:api,method:'POST',data,processData:false,contentType:false});
      if (!res.success) throw new Error(res.message || 'Message could not be sent.');
      if (!drafts.has(id) || drafts.get(id) === text) drafts.delete(id);
      if (Number(currentConversationId) === id) {
        if (input.value === text) input.value = '';
        if ($('#messageAttachment')[0]?.files[0] === file) $('#messageAttachment').val('').trigger('change');
        input.style.height = '';
        reply = null; $('#replyPreview').prop('hidden',true); await refresh();
      }
      loadConversations(false); notice('');
    } catch (error) { notice((error.message || 'Connection failed.') + ' Your message is saved here. Press Send to retry.'); }
    finally { sending = false; $('#sendBtn').attr('aria-label','Send message'); toggleSendButton(); }
  };
  $('#chatForm').off('submit').on('submit',handleSendMessage).removeAttr('onsubmit');
  $('#messageInput').off('keydown').removeAttr('onkeydown').on('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.originalEvent.isComposing) { e.preventDefault(); handleSendMessage(e); }
  }).on('input', function() { this.style.height='auto'; this.style.height=Math.min(this.scrollHeight,140)+'px'; if (sending) $('#sendBtn').prop('disabled',true); });
  $(document).on('click','[data-jump]',function() { document.getElementById('msg-'+this.dataset.jump)?.scrollIntoView({block:'center',behavior:'smooth'}); });
  $(document).on('click','.message-actions button, .message-reactions button', async function() {
    const id = Number(this.dataset.id), m = records.find(m => Number(m.id) === id), operation = this.dataset.action;
    if (!m) return;
    if (operation === 'copy') { try { await navigator.clipboard.writeText(m.message_body || ''); notice('Message copied.'); } catch { notice('Copy unavailable. Select the message text to copy it.'); } return; }
    if (operation === 'reply') { reply=m; $('#replyPreview').prop('hidden',false).html(`<span>Replying to: ${esc((m.message_body || 'Attachment').slice(0,120))}</span><button type="button" aria-label="Cancel reply">×</button>`); input.focus(); return; }
    if (operation === 'delete' && !window.confirm('Remove this message for everyone?')) return;
    try { const res = await $.post(api,{action:'message_action',operation,message_id:id,emoji:this.dataset.emoji,csrf_token:csrfToken}); if (!res.success) throw new Error(res.message); await refresh(); }
    catch (e) { notice(e.message || 'Could not update message. Try again.'); }
  });
  $('#replyPreview').on('click','button',() => { reply=null; $('#replyPreview').prop('hidden',true); });
  $(stream).on('click','.messenger-history',async function() {
    const id = Number(currentConversationId), height = stream.scrollHeight, top = stream.scrollTop;
    this.disabled = true;
    try {
      const res = await $.get(api,{action:'get_messages',conversation_id:id,before_id:records[0]?.id});
      if (id !== Number(currentConversationId)) return;
      if (!res.success) throw new Error(res.message);
      hasMore = !!res.has_more;
      const merged = new Map([...res.messages,...records].map(m => [Number(m.id),m]));
      draw([...merged.values()].sort((a,b) => Number(a.id)-Number(b.id)));
      stream.scrollTop = top + stream.scrollHeight-height;
    } catch { notice('Earlier messages could not load. Try again.'); this.disabled=false; }
  });
  window.addEventListener('pagehide',() => { request?.xhr.abort(); });
});
