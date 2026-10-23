<?php
/**
 * MICAContextService.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * Centralized, role-aware context provider for MICA AI.
 * Queries live database state and prepares structured data and
 * prompt injection context without any hallucination.
 * ------------------------------------------------------------------
 */

class MICAContextService
{
    private PDO $db;
    private array $user;
    private string $page;

    public function __construct(PDO $db, array $user, string $page = '')
    {
        $this->db = $db;
        $this->user = $user;
        $this->page = $page;
    }

    /**
     * Build comprehensive live system context string tailored to the user's role and permissions.
     */
    public function getSystemContextString(bool $includePrivate = false): string
    {
        $profile=permission_profile($this->user,$this->db);
        if($profile==='legacy') return 'General clinic navigation only. No technical or clinical data access has been assigned to this legacy profile.';
        if($profile==='mis'){
            require_once __DIR__.'/../system_monitoring.php';
            return 'MIS technical assistance only. No clinical records, diagnoses, consultation counts, inventory or emergency details. Verified system data: '.json_encode((new SystemMonitoringService($this->db))->summary());
        }
        if($profile==='doctor'){
            require_once __DIR__.'/../doctor_clinical_service.php';
            return 'Doctor clinical oversight. Assistive recommendations require professional judgment; never independently diagnose or execute treatment. Verified aggregate clinic data: '.json_encode((new DoctorClinicalService($this->db,$this->user))->summary());
        }
        $role = canonical_role($this->user['role'] ?? 'student');
        $name = trim(($this->user['first_name'] ?? '') . ' ' . ($this->user['last_name'] ?? ''));
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $lines = [];
        $lines[] = "=== SYSTEM & USER CONTEXT ===";
        $lines[] = "System: AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement";
        $lines[] = "Current Date/Time: $now (Today is " . date('l, F j, Y') . ")";
        $lines[] = "Logged-in User: $name (User ID #{$this->user['id']})";
        $lines[] = "Role: " . get_role_display_name($role);
        $lines[] = "Current Active Page: " . ($this->page ?: 'General / Dashboard');

        // Role-specific database context
        if ($role === 'student') {
            $lines[] = $includePrivate ? $this->getStudentContextString() : 'Student portal: access is limited to this account. Ask explicitly for your own records when needed.';
        } elseif ($role === 'nurse') {
            $lines[] = 'Clinic tools provide authorized patient information and verified inventory. No patient data is included in this general conversation context.';
        } elseif ($role === 'admin') {
            $lines[] = $this->getAdminContextString();
        } elseif ($role === 'teacher') {
            $lines[] = $includePrivate ? $this->getTeacherPatientContextString() : 'Teacher patient portal: access is limited to this account.';
        }

        // Shared clinic operational context
        $lines[] = $this->getSharedClinicContextString();

        return implode("\n", array_filter($lines));
    }

    /**
     * Student-specific context
     */
    private function getStudentContextString(): string
    {
        $lines = ["\n=== STUDENT HEALTH CONTEXT ==="];
        try {
            $stmt = $this->db->prepare("
                SELECT s.*, u.email, u.phone, u.gender, u.date_of_birth
                FROM students s
                JOIN users u ON u.id = s.user_id
                WHERE s.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$this->user['id']]);
            $stud = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($stud) {
                $lines[] = "Student No: " . ($stud['student_number'] ?? 'N/A');
                $lines[] = "Course/Year/Section: " . trim(($stud['course'] ?? '') . ' ' . ($stud['year_level'] ?? '') . '-' . ($stud['section'] ?? ''));
                $lines[] = "Blood Type: " . ($stud['blood_type'] ?? 'Not recorded');
                $lines[] = "Known Allergies: " . ($stud['allergies'] ?? 'None recorded');
                $lines[] = "Lifecycle Status: " . ucfirst($stud['lifecycle_status'] ?? 'Active');

                // Live Queue Ticket
                $qStmt = $this->db->prepare("
                    SELECT queue_number, status, visit_type, created_at
                    FROM queue_numbers
                    WHERE student_id = ? AND status IN ('waiting', 'called', 'serving')
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $qStmt->execute([$stud['id']]);
                $activeQueue = $qStmt->fetch(PDO::FETCH_ASSOC);
                if ($activeQueue) {
                    $lines[] = "Active Clinic Queue Ticket: {$activeQueue['queue_number']} (Status: " . ucfirst($activeQueue['status']) . ") Purpose: {$activeQueue['visit_type']}";
                } else {
                    $lines[] = "Active Clinic Queue: Not currently in clinic queue.";
                }

                // Recent Consultations / Visits
                $cStmt = $this->db->prepare("
                    SELECT consultation_date, chief_complaint, diagnosis, treatment
                    FROM consultations
                    WHERE student_id = ?
                    ORDER BY consultation_date DESC
                    LIMIT 2
                ");
                $cStmt->execute([$stud['id']]);
                $consults = $cStmt->fetchAll(PDO::FETCH_ASSOC);
                if ($consults) {
                    $lines[] = "Recent Clinic Visits:";
                    foreach ($consults as $c) {
                        $lines[] = " - " . date('M j, Y', strtotime($c['consultation_date'])) . ": " . $c['chief_complaint'] . " (Diagnosis: " . ($c['diagnosis'] ?: 'Under evaluation') . ")";
                    }
                }
            }
        } catch (Exception $e) {
            $lines[] = "Context temporarily unavailable.";
        }
        return implode("\n", $lines);
    }

    /**
     * Nurse and Doctor clinical context
     * Nurse clinical context
     */
    private function getClinicalStaffContextString(): string
    {
        $lines = ["\n=== CLINICAL STAFF LIVE DATA ==="];
        try {
            // Low Stock Medicines
            $lowStock = $this->getLowStockMedicines();
            $lines[] = "Low Stock Medicines (" . count($lowStock) . " items):";
            if ($lowStock) {
                foreach (array_slice($lowStock, 0, 5) as $m) {
                    $lines[] = " - {$m['medicine_name']} (Qty: {$m['quantity']} {$m['unit']}, Reorder Level: {$m['reorder_level']})";
                }
            } else {
                $lines[] = " - All medicine stocks are currently sufficient.";
            }

            // Expiring Medicines (within 30 days)
            $expiring = $this->getExpiringMedicines(30);
            $lines[] = "Medicines Expiring within 30 days (" . count($expiring) . " items):";
            if ($expiring) {
                foreach (array_slice($expiring, 0, 5) as $e) {
                    $lines[] = " - {$e['medicine_name']} (Expires: {$e['expiration_date']}, Qty: {$e['quantity']})";
                }
            } else {
                $lines[] = " - No medicines expiring in the next 30 days.";
            }

            // Today's Appointments & Pending Requests
            $todayApps = $this->db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status NOT IN ('cancelled','rejected')")->fetchColumn();
            $pendingApps = $this->db->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending'")->fetchColumn();
            $lines[] = "Today's Appointments: $todayApps | Pending Approval: $pendingApps";
            // Today's Queue & Consultations
            $waitingQueue = (int)$this->db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'")->fetchColumn();
            $servingQueue = (int)$this->db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'serving'")->fetchColumn();
            $todayVisits = (int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()")->fetchColumn();
            $lines[] = "Live Queue: {$waitingQueue} waiting, {$servingQueue} in consultation | Consultations completed today: {$todayVisits}";

            // Active Emergency Requests
            $activeEmergencies = $this->db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('active','new','acknowledged','responding')")->fetchColumn();
            $lines[] = "Active SOS Emergencies: $activeEmergencies";

            // Pending Reorder Requests
            $pendingReorders = $this->db->query("SELECT COUNT(*) FROM medicine_reorders WHERE status = 'for_approval'")->fetchColumn();
            $lines[] = "Pending Medicine Reorder Requests: $pendingReorders";

        } catch (Exception $e) {
            $lines[] = "Context temporarily unavailable.";
        }
        return implode("\n", $lines);
    }

    /**
     * Administrator-specific context
     */
    private function getAdminContextString(): string
    {
        $lines = ["\n=== ADMINISTRATOR LIVE DATA ==="];
        try {
            // User stats
            $totalUsers = $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $activeUsers = $this->db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
            $pendingUsers = $this->db->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
            $inactiveUsers = $this->db->query("SELECT COUNT(*) FROM users WHERE status = 'inactive'")->fetchColumn();
            $lines[] = "User Accounts: Total: $totalUsers | Active: $activeUsers | Pending Approval: $pendingUsers | Inactive: $inactiveUsers";

            // Role breakdown
            $roleCounts = $this->db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
            $roleStr = [];
            foreach ($roleCounts as $r => $cnt) {
                $roleStr[] = ucfirst($r) . ": $cnt";
            }
            $lines[] = "Role Breakdown: " . implode(', ', $roleStr);

            // Graduating students count
            $graduating = $this->db->query("SELECT COUNT(*) FROM students WHERE lifecycle_status = 'graduating'")->fetchColumn();
            $lines[] = "Graduating Students Flagged for Review: $graduating";

            // Low Stock & Reorders
            if(can_view_inventory($this->user,$this->db)){
            $lowCount = $this->db->query("SELECT COUNT(*) FROM medicines WHERE quantity <= reorder_level AND is_archived = 0")->fetchColumn();
            $pendingReorders = $this->db->query("SELECT COUNT(*) FROM medicine_reorders WHERE status = 'for_approval'")->fetchColumn();
            $lines[] = "Low Stock Items: $lowCount | Reorder Requests for Approval: $pendingReorders";
            }

        } catch (Exception $e) {
            $lines[] = "Context temporarily unavailable.";
        }
        return implode("\n", $lines);
    }

    /**
     * Teacher / Adviser context
     */
    private function getTeacherPatientContextString(): string {
        $stmt=$this->db->prepare('SELECT c.consultation_date,c.chief_complaint,c.diagnosis FROM consultations c JOIN teachers t ON t.id=c.teacher_id WHERE t.user_id=? ORDER BY c.consultation_date DESC LIMIT 2');
        $stmt->execute([(int)$this->user['id']]);
        $lines=['Own teacher clinic records:'];
        foreach($stmt as $record)$lines[]=implode(' | ',array_map('strval',$record));
        return implode("\n",$lines);
    }

    private function getAdviserContextString(): string
    {
        if(!empty($this->user['self_service_only']))return 'Personal clinic services only; no access to student advisory records.';
        $lines = ["\n=== ADVISER CONTEXT ==="];
        try {
            $stmt = $this->db->prepare("SELECT id, employee_id FROM teachers WHERE user_id = ? LIMIT 1");
            $stmt->execute([$this->user['id']]);
            $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($teacher) {
                $secStmt = $this->db->prepare("
                    SELECT s.section_name, s.course, s.year_level, COUNT(st.id) as student_count
                    FROM advisory_assignments aa
                    JOIN sections s ON s.id = aa.section_id
                    LEFT JOIN students st ON st.section = s.section_name
                    WHERE aa.teacher_id = ?
                    GROUP BY s.id
                ");
                $secStmt->execute([$teacher['id']]);
                $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC);
                if ($sections) {
                    $lines[] = "Assigned Advisory Sections:";
                    foreach ($sections as $sec) {
                        $lines[] = " - {$sec['course']} {$sec['year_level']}-{$sec['section_name']} ({$sec['student_count']} students)";
                    }
                } else {
                    $lines[] = "Assigned Advisory Sections: None assigned.";
                }
            }
        } catch (Exception $e) {
            $lines[] = "Context temporarily unavailable.";
        }
        return implode("\n", $lines);
    }

    /**
     * Shared clinic context (announcements, clinic hours, emergency contacts)
     */
    private function getSharedClinicContextString(): string
    {
        $lines = ["\n=== GENERAL CLINIC INFORMATION ==="];
        $lines[] = "Clinic Hours: Monday - Friday, 8:00 AM - 5:00 PM (Emergency SOS monitored 24/7)";
        $lines[] = "Location: BPC Main Campus Clinic, Ground Floor Health Services Center";

        try {
            require_once __DIR__.'/../announcement_service.php';
            $anns=array_slice((new AnnouncementService($this->db,$this->user))->listing(),0,2);
            if ($anns) {
                $lines[] = "Latest Clinic Announcements:";
                foreach ($anns as $a) {
                    $lines[] = " • " . $a['title'] . ": " . substr(strip_tags($a['content']), 0, 100) . '...';
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        return implode("\n", $lines);
    }

    // ================================================================
    // HELPER DATA METHODS
    // ================================================================

    public function getLowStockMedicines(): array
    {
        if(!can_view_inventory($this->user,$this->db))return [];
        $stmt = $this->db->query("
            SELECT id, medicine_name, generic_name, category, quantity, unit, reorder_level, expiration_date
            FROM medicines
            WHERE quantity <= reorder_level AND is_archived = 0
            ORDER BY quantity ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExpiringMedicines(int $days = 30): array
    {
        if(!can_view_inventory($this->user,$this->db))return [];
        $stmt = $this->db->prepare("
            SELECT id, medicine_name, generic_name, category, quantity, unit, expiration_date,
                   DATEDIFF(expiration_date, CURDATE()) as days_left
            FROM medicines
            WHERE expiration_date IS NOT NULL 
              AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              AND is_archived = 0
            ORDER BY expiration_date ASC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPendingAppointments(): array
    {
        if(!has_permission('clinic_monitor',$this->user,$this->db))return [];
        $stmt = $this->db->query("
            SELECT a.*, s.student_number, u.first_name, u.last_name, u.phone
            FROM appointments a
            JOIN students s ON s.id = a.student_id
            JOIN users u ON u.id = s.user_id
            WHERE a.status = 'pending'
            ORDER BY a.appointment_date ASC, a.appointment_time ASC
            LIMIT 50
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDailySummaryData(): array
    {
        if(permission_profile($this->user,$this->db)==='mis'){require_once __DIR__.'/../system_monitoring.php';return ['profile'=>'mis','system'=>(new SystemMonitoringService($this->db))->summary(),'generated_at'=>date('c')];}
        if(!has_permission('clinic_monitor',$this->user,$this->db))throw new RuntimeException('Clinical monitoring is not authorized.');
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $todayApps = (int)$this->db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = '$today' AND status NOT IN ('cancelled','rejected')")->fetchColumn();
        $pendingApps = (int)$this->db->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending'")->fetchColumn();
        $waitingQueue = (int)$this->db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = '$today' AND status = 'waiting'")->fetchColumn();
        $servingQueue = (int)$this->db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = '$today' AND status = 'serving'")->fetchColumn();
        $lowStockMeds = $expiringMeds = $expiredMeds = null;
        if (can_view_inventory($this->user,$this->db)) {
            require_once __DIR__ . '/../medicine_inventory_service.php';
            $rows = (new MedicineInventoryService($this->db))->snapshot();
            $lowStockMeds = count(MedicineInventoryService::filter($rows, ['status'=>'low_stock'], $today));
            $expiringMeds = count(MedicineInventoryService::filter($rows, ['expiry'=>'expiring30'], $today));
            $expiredMeds = count(MedicineInventoryService::filter($rows, ['status'=>'expired'], $today));
        }
        $activeEmergencies = (int)$this->db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('active','new','acknowledged','responding')")->fetchColumn();
        $todayVisits = (int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = '$today'")->fetchColumn();
        $yesterdayVisits = (int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = '$yesterday'")->fetchColumn();
        $pendingRegistrations = null;
        if(permission_profile($this->user,$this->db)==='doctor') {
            require_once __DIR__.'/../nurse_verification_service.php';
            $staff=new NurseVerificationService($this->db,$this->user);
            if($staff->ready())$pendingRegistrations=$staff->summaryCounts()['pending'];
        }

        return [
            'today_appointments'     => $todayApps,
            'pending_appointments'   => $pendingApps,
            'waiting_queue'          => $waitingQueue,
            'serving_queue'          => $servingQueue,
            'today_visits'           => $todayVisits,
            'low_stock_medicines'    => $lowStockMeds,
            'expiring_medicines_30d' => $expiringMeds,
            'expired_medicines'      => $expiredMeds,
            'active_emergencies'     => $activeEmergencies,
            'yesterday_visits'       => $yesterdayVisits,
            'pending_registrations'  => $pendingRegistrations,
            'generated_at'           => date('Y-m-d H:i:s'),
        ];
    }
}
