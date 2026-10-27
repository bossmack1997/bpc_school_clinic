<?php
/**
 * MICAWorkflowService.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * Natural Language Database Querying and Contextual Reasoning Service.
 * Formats factual responses grounded directly in MySQL data and
 * explicitly demarcates Confirmed Facts vs. AI Recommendations.
 * ------------------------------------------------------------------
 */

class MICAWorkflowService
{
    private PDO $db;
    private array $user;

    public function __construct(PDO $db, array $user)
    {
        $this->db = $db;
        $this->user = $user;
    }

    /**
     * Process natural language queries with direct database grounding.
     * Returns an array with ['handled' => bool, 'reply' => string, 'type' => string]
     */
    public function handleNaturalLanguageQuery(string $message): ?array
    {
        $profile=permission_profile($this->user,$this->db);
        if($profile==='legacy' || (($this->user['role']??'')==='nurse' && !has_permission('clinic_monitor',$this->user,$this->db)))return ['handled'=>true,'type'=>'access_denied','reply'=>'This account does not have clinical or technical MICA access. Use your account portal for authorization.'];
        if (preg_match('/nurse.*(verif|credential|approv)|(?:pending|verif|approv).*nurse/i',$message)) {
            if($profile!=='doctor')return ['handled'=>true,'type'=>'access_denied','reply'=>'Nurse credential reviews require a verified Doctor profile.'];
            require_once __DIR__.'/../nurse_verification_service.php';
            $staff=new NurseVerificationService($this->db,$this->user);
            $reply='Clinical Staff Verification is temporarily unavailable; ask MIS to check the reviewed migration.';
            if($staff->ready()) {
                $counts=$staff->summaryCounts();
                $reply='Recorded Nurse applications pending review: '.$counts['pending'].'. More information required: '.$counts['needs_information'].'. As of '.date('c').'.';
                if(preg_match('/show|list|applicants|sino|sinu/i',$message)) {
                    $rows=$staff->listNurses('pending','',10);
                    $reply.="\nPending applicants (latest 10 at most):";
                    foreach($rows as $row)$reply.="\n- ".trim($row['first_name'].' '.$row['last_name']).' | '.$row['email'].' | Registered '.$row['registered_at'].' | Pending clinical review';
                }
                $reply.=' Open Clinical Staff Verification: '.app_url('pages/doctor/nurse_verification.php').'. A Doctor must review supporting credentials and appointment authorization and confirm their password. MICA cannot approve credentials.';
                $this->auditLookup('MICA staff review lookup','Read pending Nurse summary; no credential decision.');
            }
            return ['handled'=>true,'type'=>'staff_verification_grounded','reply'=>$reply];
        }
        if(preg_match('/(?:draft|summari[sz]e|summary).*patient\s*#?\s*(\d+)/i',$message,$match)){
            if($profile!=='doctor')return ['handled'=>true,'type'=>'access_denied','reply'=>'Patient history drafts require a verified Doctor profile. Open your own authorized health record for personal information.'];
            require_once __DIR__.'/../doctor_clinical_service.php';
            try{return ['handled'=>true,'type'=>'clinical_draft','reply'=>(new DoctorClinicalService($this->db,$this->user))->draft((int)$match[1])];}
            catch(InvalidArgumentException $e){return ['handled'=>true,'type'=>'clinical_draft','reply'=>'The selected patient is unavailable. Choose a patient from Patient & Consultation Records.'];}
            catch(PDOException $e){error_log('MICA clinical draft source unavailable SQLSTATE='.$e->getCode());return ['handled'=>true,'type'=>'clinical_draft','reply'=>'The clinical draft source is temporarily unavailable. No summary or clinical change was produced. Please use the patient record or retry later.'];}
        }
        if($profile==='doctor'&&preg_match('/(pending.*doctor|doctor.*consultation|physician queue|clinical queue)/i',$message)){
            require_once __DIR__.'/../doctor_clinical_service.php';$facts=(new DoctorClinicalService($this->db,$this->user))->commandCenter();
            $this->auditLookup('MICA physician queue lookup','Verified aggregate handoff status; no clinical changes.');
            return ['handled'=>true,'type'=>'clinical_draft','reply'=>'Verified physician handoffs awaiting a Doctor: '.($facts['awaiting_doctor']??'Unavailable').'. Unresolved urgent physician cases: '.($facts['urgent_cases']??'Unavailable').'. As of '.$facts['generated_at'].'. Open Clinical Queue: '.app_url('pages/doctor/queue.php').'. A Doctor must explicitly claim and complete assessments; MICA cannot diagnose, prescribe or finalize care.'];
        }
        if($profile==='doctor'&&preg_match('/(follow.?up|nurse escalation|clinical report|clinical priorit|clinical workflow|patient records)/i',$message)){
            require_once __DIR__.'/../doctor_clinical_service.php';$facts=(new DoctorClinicalService($this->db,$this->user))->commandCenter();
            return ['handled'=>true,'type'=>'clinical_draft','reply'=>'MICA DRAFT — clinical oversight brief for Doctor review. Consultations today: '.($facts['today_consultations']??'Unavailable').'. Upcoming dated follow-ups (up to 10): '.($facts['followups']===null?'Unavailable':count($facts['followups'])).'. Nurse referrals addressed to Doctor/Physician (up to 10): '.($facts['escalations']===null?'Unavailable':count($facts['escalations'])).'. Low/out-of-stock medicine concerns (up to 10): '.($facts['stock']===null?'Unavailable':count($facts['stock'])).'. Open Clinical Command Center for priorities, Patient & Consultation Records for patient history, Medicine Oversight for stock, or Clinical Reports for reviewed exports. No diagnosis, treatment or record change has been made.'];
        }
        if($profile==='mis'){
            require_once __DIR__.'/../system_monitoring.php';
            $system=(new SystemMonitoringService($this->db))->summary();
            if(preg_match('/(patient|diagnos|consultation|medicine|inventory|stock|sos|emergenc|clinical)/i',$message))return ['handled'=>true,'type'=>'access_denied','reply'=>'MIS assistance is limited to system health, accounts, authentication, security and backups. Clinical information requires a verified clinical profile.'];
            if(preg_match('/(account|registration|user|pending|login|security|backup|system|health)/i',$message))return ['handled'=>true,'type'=>'technical_grounded','reply'=>'Verified MIS monitoring (as of '.$system['generated_at'].'): '.json_encode($system)];
        }
        if($profile==='doctor' && preg_match('/(account|registration|password|backup|security polic|server|database)/i',$message))return ['handled'=>true,'type'=>'access_denied','reply'=>'Technical administration and account governance require MIS authorization.'];
        $role = canonical_role($this->user['role'] ?? 'student');
        $msg = mb_strtolower(trim($message));

        if (in_array($role,['student','teacher'],true) && preg_match('/\b(my|own|akin|aking|ko)\b/i',$msg) && preg_match('/queue|record|histor|visit|consultation|follow.?up/i',$msg)) {
            // Resolve ownership from the trusted user, never a name/ID in chat.
            $table=$role==='teacher'?'teachers':'students';
            $column=$role==='teacher'?'teacher_id':'student_id';
            $q=$this->db->prepare("SELECT id FROM $table WHERE user_id=?");$q->execute([(int)$this->user['id']]);$id=$q->fetchColumn();
            if(!$id)return ['handled'=>true,'type'=>'personal_grounded','reply'=>'Your patient profile is unavailable. Contact the clinic.'];
            if(preg_match('/queue/i',$msg)) {
                $q=$this->db->prepare("SELECT queue_number,status,created_at FROM queue_numbers WHERE $column=? AND created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY ORDER BY id DESC LIMIT 10");
            } else {
                $q=$this->db->prepare("SELECT consultation_date,chief_complaint,diagnosis,treatment,follow_up_date FROM consultations WHERE $column=? ORDER BY consultation_date DESC,id DESC LIMIT 10");
            }
            $q->execute([(int)$id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $this->auditLookup('MICA personal records lookup','Own patient records only; bounded to 10.');
            return ['handled'=>true,'type'=>'personal_grounded','reply'=>'Your recorded '.(str_contains($msg,'queue')?'queue activity today':'clinic history').' (latest 10 at most), as of '.date('c').":\n".($rows?json_encode($rows,JSON_UNESCAPED_UNICODE):'No matching records.')];
        }
        if($profile==='nurse' && preg_match('/queue|pila/i',$msg)) {
            $q=$this->db->query("SELECT queue_number,status,visit_type,created_at FROM queue_numbers WHERE created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY AND status IN ('waiting','called','serving') ORDER BY created_at,id LIMIT 20");
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $this->auditLookup('MICA queue lookup','Current queue; bounded to 20; no clinical changes.');
            return ['handled'=>true,'type'=>'queue_grounded','reply'=>'Current active queue (first 20 at most), as of '.date('c').":\n".($rows?json_encode($rows,JSON_UNESCAPED_UNICODE):'No active queue entries.')];
        }

        if (in_array($role,['admin','nurse'],true) && preg_match('/(visit|patient|consultation).*(today)|today.*(visit|patient|consultation)/i',$msg)) {
            $count=(int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE consultation_date >= CURDATE() AND consultation_date < CURDATE()+INTERVAL 1 DAY")->fetchColumn();
            return ['handled'=>true,'reply'=>"Verified consultations today: $count. This counts encounters, not unique patients. As of ".date('Y-m-d H:i:s').' Asia/Manila.','type'=>'daily_grounded'];
        }
        if (in_array($role,['admin','nurse'],true) && preg_match('/(issues?.*(attention|today)|attention.*today)/i',$msg)) {
            require_once __DIR__.'/MICAActionService.php';
            $rows=(new MICAActionService($this->db,$this->user))->getRecommendedActions(null,50);
            return ['handled'=>true,'reply'=>"Active alerts visible to your role (last scan evidence): ".count($rows)."\n".implode("\n",array_column($rows,'title'))."\nRun an intelligence scan to refresh source verification.",'type'=>'alerts_grounded'];
        }

        // 1. Low stock / reorder queries
        if (preg_match('/(low(\s+in)?\s*stock|reorder|kailangan.*reorder|anong gamot.*reorder|paubos|ubos na|out of stock|running out|stock.*level|medicine.*stock|inventory|requisition|printable.*medicine|medicine order|restock)/i', $msg)) {
            return $this->handleLowStockQuery($role);
        }

        // 2. Expiration queries
        if (preg_match('/(expir|mag-expire|magexpire|panis|mapapanis|paso|shelf life)/i', $msg)) {
            return $this->handleExpirationQuery($role);
        }

        // 3. Appointment / checkup queries
        if (preg_match('/(appointment|checkup|check-up|magpacheckup|magpa-checkup|iskedyul|schedule.*bukas|schedule.*today|appointments.*tomorrow|tomorrow.*appointment)/i', $msg)) {
            return $this->handleAppointmentQuery($role, $msg);
        }

        // 4. User account / Pending account queries (Admin only)
        if (preg_match('/(pending.*(?:account|registration)|registration.*pending|inactive.*account|active.*user|ilan.*user|ilan.*account|ilang.*estudyante|user.*count|how many.*account|how many.*user|how many.*active)/i', $msg)) {
            return $this->handleAccountQuery($role);
        }

        // 5. Clinic visit volume / Demand trends
        if (preg_match('/(clinic visit|ilang.*bisita|ilang.*pasyente|busiest|volume|trends|istatistika|statistics|demand|how many.*visit)/i', $msg)) {
            return $this->handleClinicTrendQuery($role);
        }

        // 6. Emergency / SOS queries
        if (preg_match('/(sos|emergenc(?:y|ies)|emerhensya|saklolo)/i', $msg)) {
            return $this->handleEmergencyQuery($role);
        }

        return null;
    }

    /**
     * Handler: Low Stock & Reorder Queries
     */
    private function handleLowStockQuery(string $role): array
    {
        if (!can_view_inventory($this->user,$this->db)) {
            return ['handled' => true, 'reply' => 'Detailed inventory information is restricted to authorized clinic personnel.', 'type' => 'access_denied'];
        }
        require_once __DIR__ . '/../medicine_inventory_service.php';
        $low = MedicineInventoryService::low((new MedicineInventoryService($this->db))->snapshot());
        $lines = ['Current verified low-stock inventory records: ' . count($low)];
        foreach ($low as $m) {
            $lines[] = $m['medicine_name'] . ': ' . $m['usable_stock'] . ' ' . $m['unit'] . '; minimum ' . ($m['reorder_level'] ?? 'not configured') . '; ' . $m['stock_status'];
        }
        $lines[] = 'Open Medicine Inventory in MICA to review or prepare a staff-confirmed requisition. Requested quantities require human confirmation; no order has been placed.';
        return ['handled' => true, 'reply' => implode("\n", $lines), 'type' => 'inventory_grounded'];
    }

    private function auditLookup(string $action, string $details): void
    {
        $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,?,'mica',?)")
            ->execute([(int)$this->user['id'],$action,$details]);
    }

    /**
     * Handler: Expiration Queries
     */
    private function handleExpirationQuery(string $role): array
    {
        if (in_array($role,['student','teacher'],true)) {
            return [
                'handled' => true,
                'reply'   => 'Ask the nurse to verify medicine safety and expiration before use.',
                'type'    => 'student_info'
            ];
        }

        if(!can_view_inventory($this->user,$this->db))return ['handled'=>true,'reply'=>'Inventory access is not assigned to this account.','type'=>'access_denied'];
        $stmt = $this->db->query("
            SELECT id, medicine_name, quantity, unit, expiration_date,
                   DATEDIFF(expiration_date, CURDATE()) as days_left
            FROM medicines
            WHERE expiration_date IS NOT NULL 
              AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
              AND is_archived = 0
            ORDER BY expiration_date ASC
        ");
        $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$expiring) {
            return [
                'handled' => true,
                'reply'   => "**Confirmed:** No medicines in the inventory are expiring within the next 60 days.\n\n**Recommendation:** Current stock expiration profile is safe and compliant.",
                'type'    => 'expiration_grounded'
            ];
        }

        $lines = ["**Confirmed System Fact:** Found **" . count($expiring) . "** medicine item(s) nearing or past expiration:\n"];
        foreach ($expiring as $m) {
            $days = (int)$m['days_left'];
            if ($days < 0) {
                $status = "**EXPIRED (" . abs($days) . " days ago)**";
            } elseif ($days <= 7) {
                $status = "**Critical: Expires in $days days**";
            } elseif ($days <= 30) {
                $status = "**Warning: Expires in $days days**";
            } else {
                $status = "Expiring in $days days";
            }
            $lines[] = "- **{$m['medicine_name']}**: {$m['quantity']} {$m['unit']} (Exp: " . date('M j, Y', strtotime($m['expiration_date'])) . ") — $status";
        }

        $lines[] = "\n**AI Recommendation:**";
        $lines[] = "- Immediately quarantine and dispose of any expired stock.";
        $lines[] = "- Prioritize batches expiring within 30 days for primary dispensing where medically appropriate.";

        return [
            'handled' => true,
            'reply'   => implode("\n", $lines),
            'type'    => 'expiration_grounded'
        ];
    }

    /**
     * Handler: Appointment Queries
     */
    private function handleAppointmentQuery(string $role, string $msg): array
    {
        if($role==='admin')return ['handled'=>true,'reply'=>'Patient consultation details are available only through authorized clinical workflows.','type'=>'access_denied'];
        if (in_array($role,['student','teacher'],true)) {
            $portal=$role==='teacher'?'adviser':'student';
            return [
                'handled' => true,
                'reply'   => "**Clinic Consultation Information:**\n\n" .
                             "- **Direct Walk-ins Welcome:** You do not need to book an advance appointment to visit the BPC School Clinic. Walk-in consultations and emergency care are available Monday to Friday from 8:00 AM to 5:00 PM.\n" .
                             "- **QR Check-in:** Present your digital **QR Health Pass** (`pages/{$portal}/my_qr.php`) or Student ID upon arrival.\n" .
                             "- **Direct Clinic Messaging:** If you have an inquiry or want to notify the nurse before visiting, open **Clinic Messages** (`pages/{$portal}/messages.php`).",
                'type'    => 'student_walkin_info'
            ];
        }

        // Clinical staff query for today's visits and consultations
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT c.*, s.student_number, u.first_name, u.last_name
            FROM consultations c
            JOIN students s ON s.id = c.student_id
            JOIN users u ON u.id = s.user_id
            WHERE DATE(c.consultation_date) = ?
            ORDER BY c.consultation_date DESC
        ");
        $stmt->execute([$today]);
        $todayVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lines = ["**Confirmed Clinic Visits Today (" . date('M j, Y') . "):** **" . count($todayVisits) . "** recorded visit(s).\n"];
        if ($todayVisits) {
            $lines[] = "**Recent Patient Consultations:**";
            foreach (array_slice($todayVisits, 0, 5) as $tv) {
                $time = date('h:i A', strtotime($tv['consultation_date']));
                $lines[] = "- **{$time}**: {$tv['first_name']} {$tv['last_name']} (#{$tv['student_number']}) — {$tv['chief_complaint']} [Diagnosis: {$tv['diagnosis']}]";
            }
        } else {
            $lines[] = "No patient consultations recorded yet for today.";
        }
        $lines[] = "\n*Students and teachers may walk in or communicate via the Clinic Communications Hub.*";

        return ['handled' => true, 'reply' => implode("\n", $lines), 'type' => 'clinical_visits_grounded'];
    }

    /**
     * Handler: User & Account Queries (Admin)
     */
    private function handleAccountQuery(string $role): array
    {
        if ($role !== 'admin') {
            return [
                'handled' => true,
                'reply'   => "**Access Notice:** User and account statistics are restricted to System Administrators for privacy and security compliance.",
                'type'    => 'access_restricted'
            ];
        }

        $totalUsers = (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $activeUsers = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
        $pendingUsers = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
        $inactiveUsers = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE status = 'inactive'")->fetchColumn();

        $roleCounts = $this->db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);

        $lines = ["**Confirmed System Fact:** Account Management Statistics:\n"];
        $lines[] = "- **Total Registered Users:** $totalUsers";
        $lines[] = "- **Active Accounts:** $activeUsers";
        $lines[] = "- **Pending Verification:** $pendingUsers";
        $lines[] = "- **Inactive Accounts:** $inactiveUsers\n";

        $lines[] = "**Role Breakdown:**";
        foreach ($roleCounts as $r => $cnt) {
            $lines[] = "- " . get_role_display_name($r) . ": **$cnt**";
        }

        if ($pendingUsers > 0) {
            $lines[] = "\n**AI Recommendation:** There are {$pendingUsers} account(s) pending review in the **User Management** portal.";
        }

        return ['handled' => true, 'reply' => implode("\n", $lines), 'type' => 'admin_account_grounded'];
    }

    /**
     * Handler: Clinic Trend & Demand Insights
     */
    private function handleClinicTrendQuery(string $role): array
    {
        if (in_array($role,['student','teacher'],true)) {
            return [
                'handled' => true,
                'reply'   => "**Clinic Utilization Info:** Ask clinic staff about current queue conditions. Aggregate clinic demand data is restricted to authorized personnel.",
                'type'    => 'student_trend_info'
            ];
        }

        // Consultations over the past 30 days
        $past30d = (int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE consultation_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $past7d = (int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE consultation_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        
        // Common complaints
        $complaintsStmt = $this->db->query("
            SELECT chief_complaint, COUNT(*) as cnt 
            FROM consultations 
            WHERE chief_complaint != '' AND consultation_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            GROUP BY chief_complaint 
            ORDER BY cnt DESC 
            LIMIT 3
        ");
        $commonComplaints = $complaintsStmt->fetchAll(PDO::FETCH_ASSOC);

        $lines = ["**Confirmed System Fact:** Clinic Activity & Demand Insights:\n"];
        $lines[] = "- **Clinic Consultations (Past 7 Days):** $past7d visits";
        $lines[] = "- **Clinic Consultations (Past 30 Days):** $past30d visits\n";

        if ($commonComplaints) {
            $lines[] = "**Top Consultation Reasons (Last 60 Days):**";
            foreach ($commonComplaints as $cc) {
                $lines[] = "- {$cc['chief_complaint']} ({$cc['cnt']} occurrences)";
            }
        } else {
            $lines[] = "*Insufficient historical visit categorization to generate top reason breakdown.*";
        }



        return ['handled' => true, 'reply' => implode("\n", $lines), 'type' => 'trend_grounded'];
    }

    /**
     * Handler: Emergency / SOS Queries
     */
    private function handleEmergencyQuery(string $role): array
    {
        if($role==='admin'&&permission_profile($this->user,$this->db)!=='doctor')return ['handled'=>true,'reply'=>'Use your authorized Emergency Management page for emergency coordination. MICA does not disclose patient emergency details.','type'=>'access_denied'];
        if (in_array($role,['student','teacher'],true)) {
            return [
                'handled' => true,
                'reply'   => "**EMERGENCY ASSISTANCE:** If you are experiencing a medical emergency right now, please use the **RED SOS EMERGENCY BUTTON** on your screen or alert the nearest faculty/clinic staff immediately.",
                'type'    => 'sos_info'
            ];
        }

        $activeEmergencies = $this->db->query("
            SELECT e.*, u.first_name, u.last_name, u.phone
            FROM sos_emergencies e
            LEFT JOIN users u ON u.id = e.user_id
            WHERE e.status IN ('active','new','acknowledged','responding')
            ORDER BY e.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!$activeEmergencies) {
            return [
                'handled' => true,
                'reply'   => "**Confirmed:** There are currently **0 active SOS emergency alerts**.",
                'type'    => 'emergency_grounded'
            ];
        }

        $lines = ["**CRITICAL ALERT: " . count($activeEmergencies) . " ACTIVE EMERGENCY REQUEST(S):**\n"];
        foreach ($activeEmergencies as $em) {
            $lines[] = "- **{$em['emergency_type']}** from {$em['first_name']} {$em['last_name']} (Location: {$em['location']}) — Status: **" . strtoupper($em['status']) . "**";
        }
        $lines[] = "\n*Action:* Please respond immediately in the **Emergency Response Console**.";

        return ['handled' => true, 'reply' => implode("\n", $lines), 'type' => 'emergency_grounded'];
    }
}
