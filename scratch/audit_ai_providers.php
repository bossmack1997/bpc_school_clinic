<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require __DIR__.'/../includes/ai/OpenRouter.php';
$client=new OpenRouter(['max_retries'=>1,'timeout'=>20,'connect_timeout'=>8]);
if(!$client->isEnabled())echo "OpenRouter NOT TESTED: not configured.\n";
else{
 $result=$client->chat('You are a connectivity tester. Reply with OK only.',[['role'=>'user','content'=>'Connection test. No personal or medical information.']]);
 echo json_encode(['provider'=>'OpenRouter','success'=>$result['success'],'model'=>$result['model']??null,'http_code'=>$result['http_code']??null,'error_type'=>$result['error_type']??null,'content_received'=>!empty($result['content'])]).PHP_EOL;
}
if(!ELEVENLABS_API_KEY)echo "ElevenLabs NOT TESTED: not configured.\n";
else{
 $curl=curl_init(ELEVENLABS_API_ENDPOINT);
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['text'=>'BPC clinic connection test.','model_id'=>'eleven_multilingual_v2']),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: audio/mpeg','xi-api-key: '.ELEVENLABS_API_KEY],CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$type=curl_getinfo($curl,CURLINFO_CONTENT_TYPE);$errno=curl_errno($curl);curl_close($curl);
 echo json_encode(['provider'=>'ElevenLabs','http_code'=>$status,'content_type'=>$type,'audio_received'=>$status===200&&str_contains((string)$type,'audio/')&&strlen((string)$body)>100,'curl_errno'=>$errno]).PHP_EOL;
}
