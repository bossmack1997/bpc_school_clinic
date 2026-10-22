<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require __DIR__.'/../includes/account_settings.php';
$db=getDB();
foreach(['student','teacher','nurse'] as $role){
 $stmt=$db->prepare("SELECT * FROM users WHERE role=? AND status='active' LIMIT 1");$stmt->execute([$role]);$user=$stmt->fetch();
 $_SESSION['user']=$user;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['credential_version']=(int)$user['credential_version'];
 [$table]=account_settings_identity($role);$profile=$db->prepare("SELECT * FROM $table WHERE user_id=?");$profile->execute([$user['id']]);$before=$profile->fetch();
 $db->beginTransaction();
 try{
  $data=['first_name'=>'Settings QA','last_name'=>$user['last_name'],'email'=>$user['email'],'phone'=>'+639123456789','gender'=>'Other','date_of_birth'=>'2000-01-01','user_id'=>999999];
  account_settings_save($db,$user,$data);
  $profile->execute([$user['id']]);$after=$profile->fetch();
  foreach(['student_number','employee_id','license_number','course','year_level','section','specialization'] as $key)if(array_key_exists($key,$before)&&$before[$key]!==$after[$key])throw new RuntimeException('Protected identifier changed.');
  if($_SESSION['user']['first_name']!=='Settings QA')throw new RuntimeException('Session profile not refreshed.');
  try{account_settings_save($db,$user,array_replace($data,['date_of_birth'=>'2999-01-01']));throw new LogicException('Future birthday accepted');}catch(InvalidArgumentException $e){}
  $current='Test-Original9!';$new='Test-Updated8!';$db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($current,PASSWORD_DEFAULT),$user['id']]);
  try{account_settings_password($db,$user,['current_password'=>'wrong','new_password'=>$new,'confirm_password'=>$new]);throw new LogicException('Wrong current password accepted');}catch(InvalidArgumentException $e){}
  account_settings_password($db,$user,['current_password'=>$current,'new_password'=>$new,'confirm_password'=>$new]);
  $check=$db->prepare('SELECT password_hash,credential_version,remember_token FROM users WHERE id=?');$check->execute([$user['id']]);$saved=$check->fetch();
  if(!password_verify($new,$saved['password_hash'])||$saved['remember_token']!==null)throw new RuntimeException('Password change failed.');
  if(!validate_session())throw new RuntimeException('Current session invalidated.');
  $_SESSION['credential_version']=(int)$user['credential_version'];if(validate_session())throw new RuntimeException('Other session remained valid.');
  $db->rollBack();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 echo "PASS $role: own-account save, protected identifiers, session refresh, date validation, current-password verification, hashing and other-session invalidation; writes rolled back.\n";
}
