<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require __DIR__.'/../includes/ai/OpenRouter.php';
$offline=(new OpenRouter(['api_key'=>'']))->complete('Connection test only.',[['role'=>'user','content'=>'OK']]);
echo 'Provider disabled fallback: '.(!$offline['success']?'PASS':'FAIL').PHP_EOL;
$client=new OpenRouter(['max_retries'=>1,'timeout'=>12,'connect_timeout'=>5]);
if(!$client->isEnabled()){echo 'Live OpenRouter: NOT CONFIGURED'.PHP_EOL;exit;}
$r=$client->chat('Connectivity test. Reply OK only. No clinic data.',[['role'=>'user','content'=>'OK']]);
echo json_encode(['live_openrouter_success'=>$r['success'],'http_code'=>$r['http_code']??null,'error_type'=>$r['error_type']??null,'content_received'=>!empty($r['content'])]).PHP_EOL;
