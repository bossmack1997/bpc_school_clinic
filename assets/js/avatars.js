(() => {
  'use strict';
  const fallback=window.BPC_AVATAR_FALLBACK;
  if(!fallback)return;
  // Capture image errors from dynamically rendered contacts, lists and messages.
  document.addEventListener('error',event=>{const image=event.target;if(image instanceof HTMLImageElement&&image.matches('.avatar,.message-avatar,.staff-avatar,.messenger-avatar,#profileAvatar,#studentPhoto,[data-avatar-user]')&&image.src!==fallback){image.src=fallback;}},true);
  const refresh=event=>{const detail=event.detail||event;document.querySelectorAll('[data-avatar-user]').forEach(image=>{if(Number(image.dataset.avatarUser)===Number(detail.userId))image.src=detail.url;});if(typeof loadAvailableStaff==='function')loadAvailableStaff();if(typeof loadConversations==='function')loadConversations(false);};
  window.addEventListener('bpc:avatar-updated',refresh);
  window.addEventListener('storage',event=>{if(event.key==='bpc-avatar-update'&&event.newValue){try{refresh(JSON.parse(event.newValue));}catch{}}});
  window.BPC_AVATARS={updated(userId,url){const detail={userId,url,at:Date.now()};window.dispatchEvent(new CustomEvent('bpc:avatar-updated',{detail}));try{localStorage.setItem('bpc-avatar-update',JSON.stringify(detail));}catch{}}};
})();
