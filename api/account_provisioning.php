<?php
require_once __DIR__.'/../config/config.php';require_permission('manage_users');
if(permission_profile()!=='mis')admin_access_denied('MIS account provisioning required.');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if(($_GET['action']??'')!=='email_check'||$_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(400);json_response(false,[],'Unsupported operation.');}
$email=$_GET['email']??'';
if(!is_string($email)||strlen($email)>120||!filter_var($email,FILTER_VALIDATE_EMAIL)){http_response_code(422);json_response(false,[],'Enter a valid email address.');}
try{$q=getDB()->prepare('SELECT COUNT(*) FROM users WHERE email=?');$q->execute([strtolower(trim($email))]);json_response(true,['available'=>(int)$q->fetchColumn()===0]);}
catch(PDOException $e){http_response_code(503);json_response(false,[],'Email availability could not be checked.');}
