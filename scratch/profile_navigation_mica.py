from pathlib import Path
p=Path('components/sidebar.php');s=p.read_text();needle='$sbMenus = $sbNav[$sbRole]';insert='''$sbProfile=permission_profile($sbUser);
if($sbProfile==='mis')$sbNav['admin']=['brand'=>'MIS / System Administrator','sections'=>[
 'Administration'=>[['dashboard','Dashboard','dashboard.php'],['users','People Management','users.php'],['activity','System Monitoring','system_monitoring.php'],['shield-lock','Security Center','settings.php?tab=security'],['clock','Activity & Audit Logs','activity_logs.php'],['database','Backup & Recovery','backups.php'],['settings','System Settings','settings.php']],
 'Continuity'=>[['shield-lock','Verified Profiles & Succession','role_profiles.php']],
 'Account'=>[['user','My Profile','profile.php'],['logout','Logout',null,'logout']]]];
if($sbProfile==='doctor')$sbNav['admin']=['brand'=>'Doctor / Clinical Co-Administrator','sections'=>[
 'Clinical oversight'=>[['dashboard','Clinical Dashboard','dashboard.php'],['users','Patient Records','patients.php'],['file-medical','Consultations','consultations.php'],['activity','Clinic Monitoring','dashboard.php'],['pill','Medicine Oversight','../admin/inventory.php'],['alert-triangle','Emergency Oversight','../admin/emergencies.php'],['file-medical','Clinical Reports','../admin/reports.php']],
 'Communication'=>[['chat','Messages','messages.php']],
 'Account'=>[['settings','Settings','settings.php'],['logout','Logout',null,'logout']]]];
''';s=s.replace(needle,insert+needle);s=s.replace("if ($sbRole === 'admin') {", "if ($sbRole === 'admin' && $sbProfile!=='doctor') {",1);s=s.replace("$sbDirectory = $sbRole === 'teacher' ? 'adviser' : $sbRole;", "$sbDirectory = $sbProfile==='doctor'?'doctor':($sbRole === 'teacher' ? 'adviser' : $sbRole);");s=s.replace("if($sbRole==='nurse')", "if(in_array($sbRole,['student','teacher','nurse'],true))$sbMenus['sections']['Physician communication'][]=['chat','Physician Messages','../doctor_messages.php'];\nif($sbRole==='nurse')",1);p.write_text(s)
p=Path('api/admin_dashboard.php');s=p.read_text().replace("require_role('admin');", "require_role('admin');\nif(permission_profile()==='mis'){require_once __DIR__.'/../includes/system_monitoring.php';json_response(true,['data'=>(new SystemMonitoringService(getDB()))->summary()]);}",1);p.write_text(s)
p=Path('includes/ai/MICAContextService.php');s=p.read_text();needle="        $role = canonical_role($this->user['role'] ?? 'student');";s=s.replace(needle,'''        $profile=permission_profile($this->user,$this->db);
        if($profile==='mis'){
            require_once __DIR__.'/../system_monitoring.php';
            return 'MIS technical assistance only. No clinical records, diagnoses, consultation counts, inventory or emergency details. Verified system data: '.json_encode((new SystemMonitoringService($this->db))->summary());
        }
        if($profile==='doctor'){
            require_once __DIR__.'/../doctor_clinical_service.php';
            return 'Doctor clinical oversight. Assistive recommendations require professional judgment; never independently diagnose or execute treatment. Verified aggregate clinic data: '.json_encode((new DoctorClinicalService($this->db,$this->user))->summary());
        }
'''+needle,1);s=s.replace("    public function getDailySummaryData(): array\n    {", "    public function getDailySummaryData(): array\n    {\n        if(permission_profile($this->user,$this->db)==='mis'){require_once __DIR__.'/../system_monitoring.php';return ['profile'=>'mis','system'=>(new SystemMonitoringService($this->db))->summary(),'generated_at'=>date('c')];}");p.write_text(s)
p=Path('includes/ai/MICAWorkflowService.php');s=p.read_text();needle="        $role = canonical_role($this->user['role'] ?? 'student');";s=s.replace(needle,'''        $profile=permission_profile($this->user,$this->db);
        if($profile==='mis'){
            require_once __DIR__.'/../system_monitoring.php';
            $system=(new SystemMonitoringService($this->db))->summary();
            if(preg_match('/(patient|diagnos|consultation|medicine|inventory|stock|sos|emergenc|clinical)/i',$message))return ['handled'=>true,'type'=>'access_denied','reply'=>'MIS assistance is limited to system health, accounts, authentication, security and backups. Clinical information requires a verified clinical profile.'];
            if(preg_match('/(account|registration|user|pending|login|security|backup|system|health)/i',$message))return ['handled'=>true,'type'=>'technical_grounded','reply'=>'Verified MIS monitoring (as of '.$system['generated_at'].'): '.json_encode($system)];
        }
        if($profile==='doctor' && preg_match('/(account|registration|password|backup|security polic|server|database)/i',$message))return ['handled'=>true,'type'=>'access_denied','reply'=>'Technical administration and account governance require MIS authorization.'];
'''+needle,1);s=s.replace("if($role==='admin')return ['handled'=>true,'reply'=>'Use your authorized Emergency Management", "if($role==='admin'&&permission_profile($this->user,$this->db)!=='doctor')return ['handled'=>true,'reply'=>'Use your authorized Emergency Management");p.write_text(s)
p=Path('api/ai_assistant.php');s=p.read_text().replace("    $aiSummary = ['generated_at'=>$summary['generated_at']", "    $aiSummary = ['generated_at'=>$summary['generated_at']");p.write_text(s)
p=Path('pages/ai_assistant.php');s=p.read_text();s=s.replace('      const metric = value =>', '''      if(m.profile==='mis') {
        const sys=m.system;const counts=sys.accounts;
        summaryRow.html(Object.entries(counts).map(([key,value])=>'<div class="col-6 col-md-3"><div class="card p-3"><strong>'+escapeHtml(value)+'</strong><span>'+escapeHtml(key)+'</span></div></div>').join(''));
        briefBox.text('Database: '+(sys.database_connected?'connected':'unavailable')+'; failed sign-ins (24h): '+sys.failed_logins_24h+'; latest registered backup: '+(sys.last_backup?.status || 'not recorded')+'. Updated '+sys.generated_at);return;
      }
      const metric = value =>''');p.write_text(s)
p=Path('includes/mica_clinic_service.php');s=p.read_text().replace("if (canonical_role($user['role'] ?? '') !== 'nurse')", "if (!has_permission('clinical_records_read',$user,$db))");p.write_text(s)
p=Path('api/mica_clinic_tools.php');s=p.read_text().replace("require_role('nurse');", "require_permission('clinical_records_read');");p.write_text(s)
