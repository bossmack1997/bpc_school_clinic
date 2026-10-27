<?php
function account_settings_identity(string $role): array {
    return match(canonical_role($role)) {
        'student'=>['students','birthdate','Student Portal','Your own clinic and account information'],
        'teacher'=>['teachers','date_of_birth','Teacher Portal','Your own clinic and account information'],
        'nurse'=>['nurses','date_of_birth','Clinic Nurse Portal','Authorized clinic personnel'],
        default=>throw new InvalidArgumentException('Settings are unavailable for this role.')
    };
}
function account_settings_save(PDO $db, array $user, array $input): array {
    [$table,$birthColumn]=account_settings_identity($user['role']);
    $values=[];
    foreach(['first_name','last_name','email','phone'] as $key) {
        if(!is_string($input[$key]??''))throw new InvalidArgumentException('Invalid personal information.');
        $values[$key]=trim($input[$key]??'');
    }
    if(!$values['first_name']||!$values['last_name']||mb_strlen($values['first_name'])>60||mb_strlen($values['last_name'])>60)throw new InvalidArgumentException('First and last names are required (maximum 60 characters each).');
    if(!filter_var($values['email'],FILTER_VALIDATE_EMAIL)||strlen($values['email'])>150)throw new InvalidArgumentException('Enter a valid email address.');
    if($values['phone']!==''&&!preg_match('/^[+0-9() .-]{7,30}$/',$values['phone']))throw new InvalidArgumentException('Enter a valid phone number.');
    $gender=$input['gender']??'';$birth=$input['date_of_birth']??'';
    if(!in_array($gender,['','Male','Female','Other'],true))throw new InvalidArgumentException('Select a valid gender.');
    if(!is_string($birth))throw new InvalidArgumentException('Enter a valid date of birth.');
    $date=$birth!==''?DateTimeImmutable::createFromFormat('!Y-m-d',$birth):null;
    if($birth!==''&&(!$date||$date->format('Y-m-d')!==$birth||$birth>date('Y-m-d')))throw new InvalidArgumentException('Enter a valid date of birth that is not in the future.');
    $check=$db->prepare('SELECT id FROM users WHERE email=? AND id<>?');$check->execute([$values['email'],$user['id']]);
    if($check->fetchColumn())throw new InvalidArgumentException('That email is already used by another account.');
    $owner=!$db->inTransaction();if($owner)$db->beginTransaction();
    try {
        $emailCheck=$db->prepare('SELECT email FROM users WHERE id=? FOR UPDATE');$emailCheck->execute([$user['id']]);
        if(strtolower($values['email'])!==strtolower((string)$emailCheck->fetchColumn()))throw new InvalidArgumentException('Official email changes require MIS verification. Your current verified email was preserved.');
        $check=$db->prepare("SELECT id FROM $table WHERE user_id=?");$check->execute([$user['id']]);
        if(!$check->fetchColumn())throw new InvalidArgumentException('Your role profile is unavailable. Contact support.');
        $db->prepare('UPDATE users SET first_name=?,last_name=?,email=?,phone=? WHERE id=?')->execute([$values['first_name'],$values['last_name'],$values['email'],$values['phone'],$user['id']]);
        $db->prepare("UPDATE $table SET gender=?, $birthColumn=? WHERE user_id=?")->execute([$gender?:null,$birth?:null,$user['id']]);
        if($table==='teachers')$db->prepare('UPDATE teachers SET first_name=?,last_name=?,email=?,phone=? WHERE user_id=?')->execute([$values['first_name'],$values['last_name'],$values['email'],$values['phone'],$user['id']]);
        if($owner)$db->commit();
    }catch(Throwable $e){if($owner&&$db->inTransaction())$db->rollBack();throw $e;}
    foreach($values as $key=>$value)$_SESSION['user'][$key]=$value;
    return $values;
}
function account_settings_password(PDO $db,array $user,array $input): void {
    $current=$input['current_password']??'';$new=$input['new_password']??'';$confirm=$input['confirm_password']??'';
    if(!is_string($current)||!is_string($new)||!is_string($confirm)||strlen($new)>72)throw new InvalidArgumentException('Invalid password input (maximum 72 bytes).');
    $stmt=$db->prepare('SELECT password_hash FROM users WHERE id=?');$stmt->execute([$user['id']]);
    if(!password_verify($current,(string)$stmt->fetchColumn()))throw new InvalidArgumentException('Current password is incorrect.');
    if($new!==$confirm)throw new InvalidArgumentException('New passwords do not match.');
    $errors=validate_password_strength($new);if($errors)throw new InvalidArgumentException(implode(' ',$errors));
    if(password_verify($new,password_hash($current,PASSWORD_DEFAULT)))throw new InvalidArgumentException('Choose a different new password.');
    $db->prepare('UPDATE users SET password_hash=?,credential_version=credential_version+1,remember_token=NULL WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$user['id']]);
    $stmt=$db->prepare('SELECT credential_version FROM users WHERE id=?');$stmt->execute([$user['id']]);$_SESSION['credential_version']=(int)$stmt->fetchColumn();
}
