<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', __DIR__);
require __DIR__.'/../config/config.php';
$db=getDB();
$role=$argv[1] ?? 'nurse';
$u=$db->prepare("SELECT * FROM users WHERE role=? AND status='active' LIMIT 1");$u->execute([$role]);$u=$u->fetch();
$_SESSION['user']=$u;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['csrf_token']='messenger-test';
$action=$argv[2] ?? 'list_conversations';
$conversationId=(int)($argv[3]??0);
if (($argv[3]??'') === 'own') {
    $own=$db->prepare('SELECT conversation_id FROM conversation_participants WHERE user_id = ? ORDER BY conversation_id LIMIT 1');
    $own->execute([(int)$u['id']]);$conversationId=(int)$own->fetchColumn();
}
$_GET=['action'=>$action,'conversation_id'=>$conversationId];
$_POST=['action'=>$action,'csrf_token'=>'messenger-test','message_id'=>(int)($argv[4]??0),'operation'=>$argv[5]??'react','emoji'=>json_decode('"\uD83D\uDC4D"')];
if ($action === 'send_message') { $_POST['conversation_id']=$conversationId; $_POST['message_body']='Local transactional messaging verification'; $_POST['reply_to_id']=(int)($argv[4]??0); }
$_REQUEST=['action'=>$action];$_SERVER['REQUEST_METHOD']=in_array($action,['message_action','send_message'],true)?'POST':'GET';$_SERVER['REQUEST_URI']='/api/messages.php';
$db->beginTransaction();
ob_start();register_shutdown_function(function()use($db,$u,$conversationId){
 $raw=ob_get_clean();$r=json_decode($raw,true);$stored=false;
 if (isset($r['message_id'])) {
    $verify=$db->prepare('SELECT COUNT(*) FROM messages WHERE id = ? AND conversation_id = ? AND sender_id = ? AND message_body = ?');
    $verify->execute([(int)$r['message_id'],$conversationId,(int)$u['id'],'Local transactional messaging verification']);$stored=(int)$verify->fetchColumn()===1;
 }
 if($db->inTransaction())$db->rollBack();
 echo json_encode(['success'=>$r['success']??null,'message'=>$r['message']??null,'conversations'=>isset($r['conversations'])?count($r['conversations']):null,'messages'=>isset($r['messages'])?count($r['messages']):null,'interactions'=>$r['interactions_enabled']??null,'sent_and_stored'=>$stored]).PHP_EOL;
});
require __DIR__.'/../api/messages.php';
