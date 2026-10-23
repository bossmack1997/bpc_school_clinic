<?php
/** Loopback SMTP capture only. No remote connection or real recipient. */
if(PHP_SAPI!=='cli')exit;
if(($argv[1]??'')==='--sink'){
    $port=(int)($argv[2]??0);$capture=$argv[3]??'';
    if($port<1024||dirname($capture)!==realpath(__DIR__.'/../scratch'))exit(1);
    $server=stream_socket_server('tcp://127.0.0.1:'.$port,$errno,$message);
    $client=stream_socket_accept($server,20);if(!$client)exit(1);stream_set_timeout($client,20);fwrite($client,"220 fixture ESMTP\r\n");$auth=0;$data=false;$body='';
    while(($line=fgets($client))!==false){
        if($data){if(rtrim($line)==='.'){$data=false;file_put_contents($capture,$body);fwrite($client,"250 accepted locally\r\n");}else $body.=$line;continue;}
        if($auth===1){$auth=2;fwrite($client,"334 UGFzc3dvcmQ6\r\n");continue;}
        if($auth===2){$auth=0;fwrite($client,"235 authenticated\r\n");continue;}
        $verb=strtoupper(strtok($line,' '));
        if(in_array($verb,['EHLO','HELO']))fwrite($client,"250-fixture\r\n250 AUTH LOGIN\r\n");
        elseif($verb==='AUTH'){$auth=1;fwrite($client,"334 VXNlcm5hbWU6\r\n");}
        elseif(trim($verb)==='DATA'){$data=true;fwrite($client,"354 data\r\n");}
        elseif(trim($verb)==='QUIT'){fwrite($client,"221 bye\r\n");break;}
        else fwrite($client,"250 ok\r\n");
    }
    fclose($client);fclose($server);exit;
}
ini_set('session.save_path',__DIR__.'/../scratch');require __DIR__.'/../config/config.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL Doctor SMTP capture: '.$e->getMessage().PHP_EOL);exit(1);});
if(APP_ENV!=='local')throw new RuntimeException('Local only');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$capture=realpath(__DIR__.'/../scratch').DIRECTORY_SEPARATOR.'doctor-mail-'.bin2hex(random_bytes(5)).'.eml';$server=null;
try{
$server=proc_open([PHP_BINARY,__FILE__,'--sink',(string)$port,$capture],[0=>['pipe','r'],1=>['file','NUL','a'],2=>['file','NUL','a']],$pipes,__DIR__,null,['bypass_shell'=>true]);fclose($pipes[0]);usleep(300000);
foreach(['MAIL_HOST'=>'127.0.0.1','MAIL_PORT'=>(string)$port,'MAIL_USERNAME'=>'fixture','MAIL_PASSWORD'=>'synthetic','MAIL_ENCRYPTION'=>'none','MAIL_FROM_ADDRESS'=>'fixture@example.invalid']as$k=>$v)putenv($k.'='.$v);
$error=null;$ok=send_doctor_invitation_email('doctor@example.invalid','Synthetic Doctor','https://example.invalid/auth/setup_account.php#token='.str_repeat('a',64),'2026-10-24 12:00:00','SYNTHETIC-APPOINTMENT',$error);
if(!$ok||!is_file($capture))throw new RuntimeException('Loopback SMTP handoff failed');
$body=file_get_contents($capture);$body.="\n".quoted_printable_decode($body);
if(!str_contains($body,'#token='.str_repeat('a',64))||!str_contains($body,'SYNTHETIC-APPOINTMENT')||!str_contains($body,'doctor@example.invalid'))throw new RuntimeException('Captured invitation content missing');
proc_close($server);$server=null;
$error=null;$failed=send_doctor_invitation_email('doctor@example.invalid','Synthetic Doctor','https://example.invalid/auth/setup_account.php#token='.str_repeat('b',64),'2026-10-24 12:00:00','SYNTHETIC-FAILURE',$error);
if($failed)throw new RuntimeException('Closed loopback SMTP port incorrectly reported success');
echo "PASS Closed loopback SMTP port reports delivery failure\n";
echo "PASS Real PHPMailer invitation handed off to loopback SMTP capture\nPASS Captured recipient, professional reference and fragment setup link\nExternal inbox delivery NOT verified. No real email sent.\n";
}finally{if(is_resource($server)){proc_terminate($server);proc_close($server);}if(is_file($capture))unlink($capture);}
