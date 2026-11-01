<?php
require_once __DIR__.'/../../config/config.php';
require_role('nurse');
// Preserve legacy student-record bookmarks without confusing profile and user IDs.
$legacy=$_GET['student_id']??$_GET['id']??null;
if(!isset($_GET['patient'])&&is_scalar($legacy)&&ctype_digit((string)$legacy)){
 $q=getDB()->prepare('SELECT user_id FROM students WHERE id=?');$q->execute([(int)$legacy]);$_GET['patient']=(string)($q->fetchColumn()?:0);
}
$nurseWorkspace=true;
require __DIR__.'/../../includes/doctor_patient_workspace.php';
