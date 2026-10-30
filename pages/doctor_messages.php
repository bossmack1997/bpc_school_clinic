<?php
require_once __DIR__.'/../config/config.php';
if(permission_profile()==='doctor')require_once __DIR__.'/../includes/doctor_page.php';
require_once __DIR__.'/../config/config.php';require_role(['nurse','admin']);$u=current_user();if($u['role']==='admin'&&permission_profile($u)!=='doctor')admin_access_denied('Only verified clinical personnel and conversation participants may use physician messages.');require_once __DIR__.'/../includes/doctor_messaging_service.php';$s=new DoctorMessagingService(getDB(),$u);$pageTitle='Physician Messages';$error='';$thread=(int)($_GET['thread']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){csrf_verify_or_die();try{if(($_POST['action']??'')==='start'){$thread=$s->start((int)($_POST['recipient']??0));}else{$thread=(int)($_POST['thread']??0);$s->send($thread,is_string($_POST['message']??null)?$_POST['message']:'');}redirect(APP_URL.'/pages/doctor_messages.php?thread='.$thread);}catch(PDOException $e){require_once __DIR__.'/../includes/clinical_diagnostics.php';clinical_database_failure('physician.messages.save',$e);http_response_code(503);$error='Message operation could not be completed.';}catch(RuntimeException|InvalidArgumentException $e){$error=$e->getMessage();}}
require_once __DIR__.'/../includes/clinical_diagnostics.php';
$threads=null;$contacts=null;$messages=[];$messagingUnavailable=false;
try {
    $threads=$s->threads();
    if($thread){try{$messages=$s->messages($thread);}catch(PDOException $e){throw $e;}catch(RuntimeException $e){$error=$e->getMessage();$thread=0;}}
    $contacts=$s->contacts();
} catch(PDOException $e) {
    clinical_database_failure('physician.messages.load',$e);http_response_code(503);
    $messagingUnavailable=true;$thread=0;
    $error='Clinical messages are temporarily unavailable. Reload to retry or contact system support if this continues.';
}
?>
<!DOCTYPE html><html lang="en"><head><?php include __DIR__.'/../includes/head.php'; ?>
<link rel="stylesheet" href="<?=e(asset_url('css/messenger.css'))?>">
<style>.clinical-chat-layout{display:grid;grid-template-columns:minmax(240px,1fr) minmax(0,2fr);gap:1rem}.clinical-chat-layout>*{min-width:0}.clinical-chat-bubble{max-width:90%;padding:12px 16px;border-radius:16px;background:var(--bs-tertiary-bg,#eef4f1);overflow-wrap:anywhere;margin-bottom:12px}.clinical-chat-bubble.own{margin-left:auto;background:#d9f2e6}.clinical-chat-history{max-height:55vh;overflow-y:auto;padding:1rem}.clinical-chat-shell{padding-bottom:90px}.clinical-chat-shell textarea{font-size:16px}@media(max-width:767px){.clinical-chat-layout{grid-template-columns:minmax(0,1fr)}.clinical-chat-shell{padding-bottom:110px}.clinical-chat-history{max-height:45vh}}.clinical-chat-contacts nav a{min-height:44px}@media(max-width:767px){.clinical-chat-layout:not(.has-active-chat) .clinical-chat-active,.clinical-chat-layout.has-active-chat .clinical-chat-contacts{display:none}.clinical-chat-layout.has-active-chat .clinical-chat-active{min-height:65dvh}.clinical-chat-active form{position:sticky;bottom:0;background:var(--bs-body-bg,#fff);padding-top:.5rem}.clinical-chat-history{flex:1;max-height:48dvh}}
</style>
</head><body class="clinic-messages-page"><div class="app-wrapper">
<?php include __DIR__.'/../components/sidebar.php'; ?><div class="main-content"><?php include __DIR__.'/../components/navbar.php'; ?>
<main id="mainContent" class="content-area clinical-chat-shell">
<header class="app-page-head mb-3"><div><h1 class="h3 mb-1">Clinical Messages</h1><p class="small text-muted mb-0">Private coordination between authorized Nurses and Physicians.</p></div></header>
<?php if($u['role']==='nurse'): ?><nav class="d-flex gap-2 mb-3" aria-label="Messaging categories"><a class="btn btn-outline-success btn-sm" href="<?=e(app_url('pages/nurse/messages.php'))?>">Patients</a><a class="btn btn-success btn-sm" aria-current="page" href="<?=e(app_url('pages/doctor_messages.php'))?>">Physicians</a></nav><?php endif; ?>
<?php if($error): ?><p class="alert alert-danger" role="alert"><?=e($error)?></p><?php endif; ?>
<?php if($messagingUnavailable): ?><a class="btn btn-outline-success" href="<?=e(app_url('pages/doctor_messages.php'))?>">Reload messages</a><?php else: ?>
<div class="clinical-chat-layout <?=$thread?'has-active-chat':''?>">
<aside class="card p-3 clinical-chat-contacts"><label for="clinical-contact-search" class="form-label">Search contacts and conversations</label><input id="clinical-contact-search" class="form-control mb-3" type="search" placeholder="Search by name"><h2 class="h6">Contacts</h2>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="start">
<label for="physician-recipient" class="form-label">Choose an authorized <?=permission_profile($u)==='doctor'?'Nurse':'Physician'?></label>
<select name="recipient" id="physician-recipient" required class="form-select"><option value="">Select contact</option><?php foreach($contacts as $contact): ?><option value="<?=(int)$contact['id']?>"><?=e($contact['first_name'].' '.$contact['last_name'])?></option><?php endforeach; ?></select>
<button class="btn btn-success mt-2" <?=!$contacts?'disabled':''?>>Open conversation</button></form>
<?php if(!$contacts): ?><p class="small text-muted mt-2">No authorized contacts available.</p><?php endif; ?>
<nav class="mt-4" aria-label="Physician conversations"><h2 class="h6">Conversations</h2>
<?php foreach($threads as $t): ?><a class="d-block border rounded p-2 mb-2 text-break" href="<?=e(app_url('pages/doctor_messages.php?thread='.(int)$t['id']))?>" <?=($thread===(int)$t['id'])?'aria-current="page"':''?>><?=e($t['contact_name']?:'Clinical contact')?> <?php if($t['unread_count']): ?><span class="badge bg-success"><?=(int)$t['unread_count']?> unread</span><?php endif; ?><span class="d-block small text-truncate"><?=e(mb_substr($t['preview']??'No messages yet.',0,120))?></span><small class="d-block text-muted"><?=e($t['updated_at'])?></small></a><?php endforeach; ?>
<?php if(!$threads): ?><p class="text-muted">No conversations available.</p><?php endif; ?></nav></aside>
<section class="card p-3 clinical-chat-active" aria-label="Conversation"><a class="btn btn-outline-success d-md-none mb-3" href="<?=e(app_url('pages/doctor_messages.php'))?>">Back to conversations</a><h2 class="h6"><?=$thread?'Conversation #'.$thread:'Choose a conversation'?></h2>
<div class="clinical-chat-history" role="log" aria-label="Message history">
<?php foreach($messages as $m): ?><article class="clinical-chat-bubble <?=(int)$m['sender_id']===(int)$u['id']?'own':''?>"><img class="message-avatar rounded-circle me-1" width="28" height="28" src="<?=e(avatar_url($m['profile_picture']??null))?>" alt=""><strong><?=e($m['first_name'].' '.$m['last_name'])?></strong><p class="my-1"><?=nl2br(e($m['message_body']))?></p><small class="text-muted"><?=e($m['created_at'])?><?php if((int)$m['sender_id']===(int)$u['id']): ?> · <?=$m['read_at']?'Read':'Sent'?><?php endif; ?></small></article><?php endforeach; ?>
<?php if($thread&&!$messages): ?><p class="text-muted">No messages yet.</p><?php endif; ?></div>
<?php if($thread): ?><form method="post" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="send"><input type="hidden" name="thread" value="<?=$thread?>"><label class="form-label" for="physician-message">Message</label><textarea name="message" id="physician-message" maxlength="4000" required class="form-control" rows="3"></textarea><button class="btn btn-success mt-2">Send message</button></form><?php endif; ?>
</section></div><?php endif; ?></main></div></div><?php include __DIR__.'/../components/footer.php'; ?><script>
// Refresh received messages without discarding an unfinished reply or contact search.
setInterval(function(){
 if(document.hidden||document.querySelector('[role="alert"]'))return;
 const reply=document.getElementById('physician-message');
 const search=document.getElementById('clinical-contact-search');
 const recipient=document.getElementById('physician-recipient');
 if((reply&&reply.value.trim())||(search&&search.value)||(recipient&&recipient.value))return;
 if(document.activeElement?.matches('textarea,input,select'))return;
 window.location.reload();
},30000);
document.getElementById('clinical-contact-search')?.addEventListener('input',function(){
 const term=this.value.trim().toLocaleLowerCase();
 document.querySelectorAll('.clinical-chat-contacts nav a').forEach(item=>{item.classList.toggle('d-none',!item.textContent.toLocaleLowerCase().includes(term));});
 document.querySelectorAll('#physician-recipient option').forEach(item=>{if(item.value)item.hidden=!item.textContent.toLocaleLowerCase().includes(term);});
});
</script></body></html>
