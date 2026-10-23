<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/ai/ChatHistory.php';
function current_user(){return $_SESSION['user']??null;}
function permission_profile($user){return $user['profile']??$user['role'];}
function verifyHistory($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$_SESSION=['user'=>['id'=>1,'role'=>'admin','profile'=>'doctor'],'credential_version'=>1,'ai_chat_history_v2'=>[['role'=>'assistant','content'=>'unattributed legacy secret']]];
$h=new ChatHistory();verifyHistory($h->getHistory()===[],'Unscoped legacy messages discarded');
$h->addMessage('assistant','Local clinical secret','local_only');$h->addMessage('user','Hello');
verifyHistory(count($h->getHistory())===2,'Local records retained for current user display');
verifyHistory(count($h->getMessagesForPrompt())===1,'Local grounded records excluded from provider history');
$_SESSION['user']['profile']='mis';verifyHistory($h->getHistory()===[],'Profile change clears clinical history');
$h->addMessage('assistant','Technical secret');$_SESSION['user']['id']=2;verifyHistory($h->getHistory()===[],'User change clears previous history');
$h->addMessage('user','Before password change');$_SESSION['credential_version']=2;verifyHistory($h->getHistory()===[],'Credential change clears old history');
$h->addMessage('user','Before restriction');$_SESSION['user']['self_service_only']=1;verifyHistory($h->getHistory()===[],'Scope restriction clears old history');
echo "7 MICA history checks passed.\n";
