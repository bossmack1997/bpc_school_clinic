<?php
/** Exercise People Management detail rendering using temporary tables only. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);});
$db=getDB();
foreach(['users','students','teachers','nurses','student_qrs','teacher_qrs','account_security_events','activity_logs','notifications','account_security_exceptions'] as $table){
    $db->exec("CREATE TEMPORARY TABLE people_test_$table LIKE $table");
    $db->exec("ALTER TABLE people_test_$table RENAME TO $table");
}
$db->exec("INSERT INTO users(id,username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified) VALUES (700,'people_admin','people_admin@example.invalid','unused','People','Admin','admin','primary_admin','active',1),(701,'people_pending','people_pending@example.invalid','unused','Pending','Fixture','student',NULL,'pending',1)");
$db->exec("INSERT INTO account_security_events(user_id,event_type,subject_key,details) VALUES (701,'identity_review',REPEAT('a',64),'<script>Fixture evidence</script>')");
$db->exec("INSERT INTO activity_logs(user_id,action,module,details) VALUES (701,'Fixture sign-in','auth','Authentication history fixture'),(701,'Private clinical activity','consultation','CLINICAL_DETAIL_MUST_NOT_RENDER')");
$_SESSION['user']=$db->query('SELECT * FROM users WHERE id=700')->fetch();
$_SESSION['credential_version']=0;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();
$_SERVER['REQUEST_METHOD']='GET';$_GET=['view'=>701];
register_shutdown_function(function(){
    $html=ob_get_clean();
    $ok=str_contains($html,'Eligibility Verification Required')&&str_contains($html,'Authentication history fixture')&&str_contains($html,'&lt;script&gt;Fixture evidence&lt;/script&gt;')&&!str_contains($html,'CLINICAL_DETAIL_MUST_NOT_RENDER')&&!str_contains($html,'name="action" value="approve_account"');
    echo ($ok?'PASS':'FAIL')." People Management labels, escaped audit history and account-only activity scope\n";
    if(!$ok)exit(1);
});
require __DIR__.'/../pages/admin/users.php';
