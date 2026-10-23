<?php
/** Configuration contract only; no user fixtures or database writes. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',__DIR__.'/../scratch');
$cookieMode=$argv[1]??'http';if(!in_array($cookieMode,['http','https'],true))throw new RuntimeException('Expected http or https.');
if($cookieMode==='https')$_SERVER['HTTPS']='on';else unset($_SERVER['HTTPS'],$_SERVER['HTTP_X_FORWARDED_PROTO']);
require __DIR__.'/../config/config.php';
$cookieParameters=session_get_cookie_params();
if(!$cookieParameters['httponly']||$cookieParameters['samesite']!=='Lax'||$cookieParameters['path']!=='/'||$cookieParameters['secure']!==($cookieMode==='https')||(int)ini_get('session.use_strict_mode')!==1||(int)ini_get('session.use_only_cookies')!==1||(int)ini_get('session.use_trans_sid')!==0)throw new RuntimeException('Session cookie contract failed.');
session_destroy();echo 'PASS '.$cookieMode." session cookie flags, strict IDs, cookie-only transport and disabled URL rewriting.\n";
