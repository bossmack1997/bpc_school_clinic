<?php
/**
 * DatabaseContext.php
 * 
 * Retrieves data from MySQL using PDO with strict role-based access.
 * 
 * Role Rules:
 * - Student:  Only the logged-in student's records
 * - Nurse:    All clinic records (students, appointments, etc.)
 * - Admin:    Analytics only (aggregated data)
 * 
 * Never exposes other student's information.
 * Uses prepared statements exclusively.
 */

class DatabaseContext
{
    private PDO $db;
    private array $user;

    public function __construct(PDO $db, array $user)
    {
        $this->db = $db;
        $this->user = $user;
    }

    private function getUserId(): int
    {
        return (int)($this->user['id'] ?? 0);
    }

    private function getRole(): string
    {
        return $this->user['role'] ?? '';
    }

    /**
     * Get the student_id for the logged-in student user.
     */
    private function getStudentId(): int
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM students WHERE user_id = ?");
            $stmt->execute([$this->getUserId()]);
            $result = $stmt->fetchColumn();
            return $result !== false ? (int)$result : 0;
        } catch (PDOException $e) {
            error_log('[MICA DB Error] Failed to fetch student_id: ' . $e->getMessage());
            return 0;
        }
    }

    // ================================================================
    // STUDENT PROFILE
    // ================================================================

    /**
     * Get full student profile for logged-in student.
     */
    public function getStudentProfile(): ?array
    {
        if ($this->getRole() !== 'student') return null;
        try {
            $stmt = $this->db->prepare("
                SELECT s.id AS student_id, s.student_number, s.course, s.year_level, s.section,
                       s.gender, s.birthdate, s.address, s.blood_type, s.allergies,
                       s.guardian_name, s.guardian_contact,
                       s.emergency_contact_name, s.emergency_contact_number,
                       u.first_name, u.last_name, u.email, u.phone
                FROM students s
                JOIN users u ON u.id = s.user_id
                WHERE s.user_id = ?
            ");
            $stmt->execute([$this->getUserId()]);
            $res = $stmt->fetch();
            return $res ?: null;
        } catch (PDOException $e) {
            error_log('[MICA DB Error] getStudentProfile: ' . $e->getMessage());
            return null;
        }
    }

    // ================================================================
    // MEDICAL RECORDS
    // ================================================================

    /**
     * Get medical records.
     * 
     * @return array
     */
    public function getMedicalRecords(): array
    {
        $role = $this->getRole();

        if ($role === 'student') {
            $studentId = $this->getStudentId();
            if (!$studentId) return [];

            $stmt = $this->db->prepare("
                SELECT mr.id, mr.file_path, mr.file_type, mr.description, mr.uploaded_at,
                       c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment
                FROM medical_records mr
                JOIN consultations c ON c.id = mr.consultation_id
                WHERE mr.student_id = ?
                ORDER BY c.consultation_date DESC
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll();
        }

        if ($role === 'nurse') {
            $stmt = $this->db->query("
                SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment,
                       u.first_name, u.last_name, s.student_number
                FROM medical_records mr
                JOIN consultations c ON c.id = mr.consultation_id
                JOIN students s ON s.id = mr.student_id
                JOIN users u ON u.id = s.user_id
                ORDER BY c.consultation_date DESC
            ");
            return $stmt->fetchAll();
        }

        // Admin — analytics only
        $stmt = $this->db->query("
            SELECT COUNT(*) AS total_records,
                   COUNT(DISTINCT student_id) AS total_students_with_records,
                   MAX(uploaded_at) AS latest_upload
            FROM medical_records
        ");
        return $stmt->fetchAll();
    }

    // ================================================================
    // APPOINTMENTS
    // ================================================================

    /**
     * Get queue tickets & clinic visit status (formerly appointments).
     * 
     * @return array
     */
    public function getAppointments(): array
    {
        $role = $this->getRole();

        if ($role === 'student') {
            $studentId = $this->getStudentId();
            if (!$studentId) return [];

            $stmt = $this->db->prepare("
                SELECT q.*, u.first_name AS patient_first, u.last_name AS patient_last
                FROM queue_numbers q
                LEFT JOIN students s ON s.id = q.student_id
                LEFT JOIN users u ON u.id = s.user_id
                WHERE q.student_id = ?
                ORDER BY q.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll();
        }

        if ($role === 'nurse') {
            $stmt = $this->db->query("
                SELECT q.*, s.student_number, u.first_name, u.last_name
                FROM queue_numbers q
                LEFT JOIN students s ON s.id = q.student_id
                LEFT JOIN users u ON u.id = s.user_id
                WHERE DATE(q.created_at) = CURDATE()
                ORDER BY FIELD(q.status, 'called', 'serving', 'waiting', 'completed', 'skipped'), q.created_at ASC
            ");
            return $stmt->fetchAll();
        }

        // Admin — summary metrics
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) AS total_queue_tickets,
                SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS waiting,
                SUM(CASE WHEN status = 'called' THEN 1 ELSE 0 END) AS called,
                SUM(CASE WHEN status = 'serving' THEN 1 ELSE 0 END) AS serving,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped,
                COUNT(DISTINCT student_id) AS total_patients
            FROM queue_numbers
            WHERE DATE(created_at) = CURDATE()
        ");
        return $stmt->fetchAll();
    }

    // ================================================================
    // BMI (via Health Monitoring)
    // ================================================================

    /**
     * Get BMI records.
     * 
     * @return array
     */
    public function getBmiRecords(): array
    {
        $role = $this->getRole();

        if ($role === 'student') {
            $studentId = $this->getStudentId();
            if (!$studentId) return [];

            $stmt = $this->db->prepare("
                SELECT id, height_cm, weight_kg, bmi, bmi_status, blood_pressure, heart_rate, temperature, recorded_at
                FROM health_monitoring
                WHERE student_id = ? AND bmi IS NOT NULL
                ORDER BY recorded_at DESC
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll();
        }

        if ($role === 'nurse') {
            $stmt = $this->db->query("
                SELECT hm.*, u.first_name, u.last_name, s.student_number
                FROM health_monitoring hm
                JOIN students s ON s.id = hm.student_id
                JOIN users u ON u.id = s.user_id
                WHERE hm.bmi IS NOT NULL
                ORDER BY hm.recorded_at DESC
                LIMIT 100
            ");
            return $stmt->fetchAll();
        }

        // Admin — analytics only
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) AS total_records,
                ROUND(AVG(bmi), 2) AS average_bmi,
                MIN(bmi) AS min_bmi,
                MAX(bmi) AS max_bmi,
                SUM(CASE WHEN bmi_status = 'Underweight' THEN 1 ELSE 0 END) AS underweight,
                SUM(CASE WHEN bmi_status = 'Normal' THEN 1 ELSE 0 END) AS normal,
                SUM(CASE WHEN bmi_status = 'Overweight' THEN 1 ELSE 0 END) AS overweight,
                SUM(CASE WHEN bmi_status = 'Obese' THEN 1 ELSE 0 END) AS obese
            FROM health_monitoring
            WHERE bmi IS NOT NULL
        ");
        return $stmt->fetchAll();
    }

    // ================================================================
    // VACCINATIONS
    // ================================================================

    /**
     * Get vaccination records.
     * 
     * @return array
     */
    public function getVaccinations(): array
    {
        $role = $this->getRole();

        if ($role === 'student') {
            $studentId = $this->getStudentId();
            if (!$studentId) return [];

            $stmt = $this->db->prepare("
                SELECT vaccine_name, dose_number, date_administered, administered_by, next_dose_date
                FROM vaccinations
                WHERE student_id = ?
                ORDER BY date_administered DESC
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll();
        }

        if ($role === 'nurse') {
            $stmt = $this->db->query("
                SELECT v.*, u.first_name, u.last_name, s.student_number
                FROM vaccinations v
                JOIN students s ON s.id = v.student_id
                JOIN users u ON u.id = s.user_id
                ORDER BY v.date_administered DESC
                LIMIT 100
            ");
            return $stmt->fetchAll();
        }

        // Admin — analytics only
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) AS total_vaccinations,
                COUNT(DISTINCT student_id) AS total_students_vaccinated,
                COUNT(DISTINCT vaccine_name) AS unique_vaccines,
                MAX(date_administered) AS most_recent
            FROM vaccinations
        ");
        return $stmt->fetchAll();
    }

    // ================================================================
    // MEDICINES (Inventory)
    // ================================================================

    /**
     * Get medicine inventory.
     * 
     * @return array
     */
    public function getMedicines(): array
    {
        $role = $this->getRole();

        if ($role === 'student') {
            // Students see available medicine catalog
            $stmt = $this->db->query("
                SELECT id, medicine_name, category, (quantity > 0) AS is_available, unit
                FROM medicines WHERE is_archived = 0
                ORDER BY medicine_name ASC
            ");
            return $stmt->fetchAll();
        }

        if ($role === 'nurse') {
            $stmt = $this->db->query("
                SELECT id, medicine_name, category, quantity, unit, reorder_level, expiration_date, batch_number,
                       CASE WHEN expiration_date IS NOT NULL AND expiration_date <= CURDATE() THEN 1 ELSE 0 END AS is_expired,
                       CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END AS is_low
                FROM medicines WHERE is_archived = 0
                ORDER BY medicine_name
            ");
            return $stmt->fetchAll();
        }

        // Admin — analytics only
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) AS total_medicines,
                SUM(quantity) AS total_units,
                SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) AS low_stock_count,
                SUM(CASE WHEN expiration_date IS NOT NULL AND expiration_date < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
                ROUND(AVG(quantity), 0) AS average_quantity
            FROM medicines WHERE is_archived = 0
        ");
        return $stmt->fetchAll();
    }

    // ================================================================
    // ANNOUNCEMENTS
    // ================================================================

    /**
     * Get announcements based on role.
     * 
     * @return array
     */
    public function getAnnouncements(): array
    {
        $role = $this->getRole();

        if (in_array($role, ['student', 'nurse'])) {
            $stmt = $this->db->prepare("
                SELECT a.title, a.content, a.is_pinned, a.created_at,
                       u.first_name, u.last_name
                FROM announcements a
                LEFT JOIN users u ON u.id = a.posted_by
                WHERE a.audience IN ('all', ?)
                ORDER BY a.is_pinned DESC, a.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$role]);
            return $stmt->fetchAll();
        }

        // Admin sees all announcements
        $stmt = $this->db->query("
            SELECT a.*, u.first_name, u.last_name
            FROM announcements a
            LEFT JOIN users u ON u.id = a.posted_by
            ORDER BY a.is_pinned DESC, a.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    // ================================================================
    // CLINIC SCHEDULE
    // ================================================================

    /**
     * Get clinic schedule / system settings.
     * 
     * @return array
     */
    public function getClinicSchedule(): array
    {
        $role = $this->getRole();

        if ($role === 'admin') {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        }

        $stmt = $this->db->query("
            SELECT setting_key, setting_value 
            FROM system_settings 
            WHERE setting_key IN ('school_name', 'clinic_name', 'school_address', 'clinic_contact')
            ORDER BY setting_key
        ");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    // ================================================================
    // QR SCAN LOGS
    // ================================================================

    /**
     * Get recent QR scan logs for the current context.
     *
     * @return array
     */
    public function getRecentScanLogs(): array
    {
        $role = $this->getRole();

        if ($role === 'student') {
            $studentId = $this->getStudentId();
            if (!$studentId) return [];
            $stmt = $this->db->prepare("
                SELECT purpose, scan_time, status, scan_location
                FROM qr_scan_logs
                WHERE student_id = ?
                ORDER BY scan_time DESC
                LIMIT 10
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll();
        }

        if ($role === 'nurse') {
            $stmt = $this->db->query("
                SELECT qsl.purpose, qsl.scan_time, qsl.status, qsl.scan_location,
                       u.first_name, u.last_name, s.student_number
                FROM qr_scan_logs qsl
                LEFT JOIN students s ON s.id = qsl.student_id
                LEFT JOIN users u ON u.id = s.user_id
                ORDER BY qsl.scan_time DESC
                LIMIT 20
            ");
            return $stmt->fetchAll();
        }

        // Admin — analytics
        $stmt = $this->db->query("
            SELECT purpose, COUNT(*) AS cnt,
                   SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful,
                   SUM(CASE WHEN status = 'duplicate' THEN 1 ELSE 0 END) AS duplicates
            FROM qr_scan_logs
            GROUP BY purpose
            ORDER BY cnt DESC
        ");
        return $stmt->fetchAll();
    }

    // ================================================================
    // QUEUE NUMBERS
    // ================================================================

    /**
     * Get current active queue numbers (nurse/admin only).
     *
     * @return array
     */
    public function getCurrentQueue(): array
    {
        $role = $this->getRole();
        if ($role === 'student') return [];

        $stmt = $this->db->query("
            SELECT qn.queue_number, qn.visit_type, qn.status, qn.created_at,
                   u.first_name, u.last_name, s.student_number
            FROM queue_numbers qn
            LEFT JOIN students s ON s.id = qn.student_id
            LEFT JOIN users u ON u.id = s.user_id
            WHERE qn.status IN ('waiting', 'called', 'serving')
            ORDER BY qn.created_at ASC
        ");
        return $stmt->fetchAll();
    }

    // ================================================================
    // STUDENT VISIT SUMMARY — For MICA AI context after QR scan
    // ================================================================

    /**
     * Get a summary of the logged-in student's visit context for MICA.
     */
    public function getStudentVisitSummary(): array
    {
        $role = $this->getRole();
        if ($role !== 'student') return [];

        $studentId = $this->getStudentId();
        if (!$studentId) return [];

        $summary = [];

        // Active queue number
        $stmt = $this->db->prepare("
            SELECT queue_number, visit_type, status, created_at, estimated_wait_minutes
            FROM queue_numbers
            WHERE student_id = ? AND status IN ('waiting', 'called', 'serving')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $queue = $stmt->fetch();
        if ($queue) {
            $summary['active_queue'] = $queue;
        }

        // Today's appointment
        $today = date('Y-m-d');
        // Recent consultation / visit
        $stmt = $this->db->prepare("
            SELECT consultation_date, chief_complaint, diagnosis, treatment
            FROM consultations
            WHERE student_id = ?
            ORDER BY consultation_date DESC LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $consult = $stmt->fetch();
        if ($consult) {
            $summary['recent_consultation'] = $consult;
        }

        // Last QR scan
        $stmt = $this->db->prepare("
            SELECT purpose, scan_time, status, scan_location
            FROM qr_scan_logs
            WHERE student_id = ?
            ORDER BY scan_time DESC LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $lastScan = $stmt->fetch();
        if ($lastScan) {
            $summary['last_scan'] = $lastScan;
        }

        // Latest BMI
        $stmt = $this->db->prepare("
            SELECT bmi, bmi_status, recorded_at
            FROM health_monitoring
            WHERE student_id = ? AND bmi IS NOT NULL
            ORDER BY recorded_at DESC LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $bmi = $stmt->fetch();
        if ($bmi) {
            $summary['latest_bmi'] = $bmi;
        }

        return $summary;
    }

    // ================================================================
    // QR SETTINGS
    // ================================================================

    /**
     * Get all QR settings as a flat key => value map.
     */
    public function getQrSettings(): array
    {
        $stmt = $this->db->query("SELECT setting_key, setting_value FROM qr_settings");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    // ================================================================
    // GET ALL — Returns an array with all data types
    // ================================================================

    /**
     * Get all context data in one array.
     *
     * @return array
     */
    public function getAll(): array
    {
        $result = [];

        foreach (['medical_records', 'appointments', 'bmi_records', 'vaccinations', 'medicines', 'announcements', 'scan_logs', 'current_queue', 'qr_settings'] as $key) {
            try {
                $result[$key] = match ($key) {
                    'medical_records' => $this->getMedicalRecords(),
                    'appointments'    => $this->getAppointments(),
                    'bmi_records'     => $this->getBmiRecords(),
                    'vaccinations'    => $this->getVaccinations(),
                    'medicines'       => $this->getMedicines(),
                    'announcements'   => $this->getAnnouncements(),
                    'scan_logs'       => $this->getRecentScanLogs(),
                    'current_queue'   => $this->getCurrentQueue(),
                    'qr_settings'     => $this->getQrSettings(),
                };
            } catch (PDOException $e) {
                error_log('[MICA DB Error] Failed to fetch ' . $key . ': ' . $e->getMessage());
                $result[$key] = [];
            }
        }

        try {
            $result['student_visit'] = $this->getStudentVisitSummary();
        } catch (PDOException $e) {
            error_log('[MICA DB Error] Failed to fetch student_visit: ' . $e->getMessage());
            $result['student_visit'] = [];
        }

        try {
            $result['clinic_schedule'] = $this->getClinicSchedule();
        } catch (PDOException $e) {
            error_log('[MICA DB Error] Failed to fetch clinic_schedule: ' . $e->getMessage());
            $result['clinic_schedule'] = [];
        }

        return $result;
    }

    /**
     * Build a plain-text context summary string for AI prompts.
     * This provides real, structured data to MICA.
     *
     * @return string
     */
    public function buildContextSummary(): string
    {
        $data = $this->getAll();
        $role = $this->getRole();
        $parts = [];

        $parts[] = "### LOGGED-IN USER IDENTITY";
        $parts[] = "Role: " . strtoupper($role);
        $parts[] = "Name: " . ($this->user['first_name'] ?? '') . ' ' . ($this->user['last_name'] ?? '');

        if ($role === 'student') {
            $profile = $this->getStudentProfile();
            if ($profile) {
                $pInfo = [];
                if (!empty($profile['student_number'])) $pInfo[] = "Student ID: " . $profile['student_number'];
                if (!empty($profile['course'])) $pInfo[] = "Course: " . $profile['course'];
                if (!empty($profile['year_level'])) $pInfo[] = "Year Level: " . $profile['year_level'];
                if (!empty($profile['section'])) $pInfo[] = "Section: " . $profile['section'];
                if (!empty($pInfo)) {
                    $parts[] = implode(" | ", $pInfo);
                }
            }
        }

        // Appointments
        $appts = $data['appointments'] ?? [];
        if (!empty($appts)) {
            $parts[] = "\n### APPOINTMENTS IN CLINIC DATABASE";
            if ($role === 'student') {
                $today = date('Y-m-d');
                $upcoming = [];
                $past = [];
                foreach ($appts as $a) {
                    $d = $a['appointment_date'] ?? '';
                    $t = (!empty($a['appointment_time'])) ? date('g:i A', strtotime($a['appointment_time'])) : 'Anytime';
                    $st = ucfirst($a['status'] ?? 'pending');
                    $r = $a['reason'] ?? 'General Consultation';
                    $line = "- Date: " . format_date($d) . " at {$t} (Status: {$st}, Reason: \"{$r}\")";
                    if ($d >= $today && in_array($a['status'] ?? '', ['pending', 'approved'], true)) {
                        $upcoming[] = $line;
                    } else {
                        $past[] = $line;
                    }
                }
                if (!empty($upcoming)) {
                    $parts[] = "Active/Upcoming Appointments for this student:\n" . implode("\n", array_slice($upcoming, 0, 3));
                } else {
                    $parts[] = "No active upcoming appointments found for this student.";
                    if (!empty($past)) {
                        $parts[] = "Most Recent Past Appointment:\n" . $past[0];
                    }
                }
            } else {
                $parts[] = "Total Appointments: " . count($appts);
            }
        } else if ($role === 'student') {
            $parts[] = "\n### APPOINTMENTS: No appointment records found for this student in the clinic system.";
        }

        // BMI & Vitals
        $bmi = $data['bmi_records'] ?? [];
        if (!empty($bmi)) {
            $parts[] = "\n### HEALTH MONITORING & VITAL SIGNS";
            if ($role === 'student') {
                $latest = $bmi[0];
                $bLine = "- Latest BMI: " . ($latest['bmi'] ?? 'N/A') . " (" . ($latest['bmi_status'] ?? 'N/A') . ")";
                if (!empty($latest['weight_kg'])) $bLine .= " | Weight: {$latest['weight_kg']} kg";
                if (!empty($latest['height_cm'])) $bLine .= " | Height: {$latest['height_cm']} cm";
                if (!empty($latest['recorded_at'])) $bLine .= " | Date: " . format_date($latest['recorded_at']);
                $parts[] = $bLine;
                if (!empty($latest['blood_pressure'])) $parts[] = "- Blood Pressure: {$latest['blood_pressure']}";
                if (!empty($latest['heart_rate'])) $parts[] = "- Heart Rate: {$latest['heart_rate']} bpm";
                if (!empty($latest['temperature'])) $parts[] = "- Temperature: {$latest['temperature']} °C";
            } else {
                $b = $bmi[0];
                $parts[] = "BMI Records Count: " . count($bmi);
            }
        }

        // Medical Records / Consultations
        $records = $data['medical_records'] ?? [];
        if (!empty($records) && $role === 'student') {
            $parts[] = "\n### MEDICAL & CONSULTATION RECORDS";
            $rLines = [];
            foreach (array_slice($records, 0, 3) as $rec) {
                $cDate = !empty($rec['consultation_date']) ? format_date($rec['consultation_date']) : 'Recent';
                $diag = $rec['diagnosis'] ?? 'Checkup';
                $treat = $rec['treatment'] ?? 'None recorded';
                $rLines[] = "- {$cDate}: Diagnosis: {$diag} | Treatment: {$treat}";
            }
            $parts[] = implode("\n", $rLines);
        }

        // Vaccinations
        $vax = $data['vaccinations'] ?? [];
        if (!empty($vax) && $role === 'student') {
            $parts[] = "\n### VACCINATION RECORDS";
            $vLines = [];
            foreach (array_slice($vax, 0, 5) as $v) {
                $vDate = !empty($v['date_administered']) ? format_date($v['date_administered']) : 'Recorded';
                $vLines[] = "- " . ($v['vaccine_name'] ?? 'Vaccine') . " (Dose " . ($v['dose_number'] ?? '1') . ") on {$vDate}";
            }
            $parts[] = implode("\n", $vLines);
        }

        // Medicines in Stock
        $meds = $data['medicines'] ?? [];
        if (!empty($meds)) {
            $parts[] = "\n### CLINIC MEDICINE INVENTORY (ACTUAL DATABASE STOCK)";
            if ($role === 'student' || $role === 'nurse') {
                $inStock = [];
                $outStock = [];
                foreach ($meds as $m) {
                    $name = $m['medicine_name'] ?? '';
                    if (!$name) continue;
                    $avail = (isset($m['is_available']) && $m['is_available']) || (isset($m['quantity']) && $m['quantity'] > 0);
                    if ($avail) {
                        $inStock[] = $name . (!empty($m['category']) ? " ({$m['category']})" : '');
                    } else {
                        $outStock[] = $name;
                    }
                }
                if (!empty($inStock)) {
                    $parts[] = "Available in Clinic: " . implode(', ', array_slice($inStock, 0, 20));
                }
                if (!empty($outStock)) {
                    $parts[] = "Currently Out of Stock: " . implode(', ', array_slice($outStock, 0, 10));
                }
            }
        }

        // Announcements
        $ann = $data['announcements'] ?? [];
        if (!empty($ann)) {
            $parts[] = "\n### RECENT CLINIC ANNOUNCEMENTS";
            $aLines = [];
            foreach (array_slice($ann, 0, 3) as $a) {
                $aTitle = $a['title'] ?? 'Announcement';
                $aBody = mb_substr(strip_tags($a['content'] ?? ''), 0, 100);
                $aLines[] = "- \"{$aTitle}\": {$aBody}...";
            }
            $parts[] = implode("\n", $aLines);
        }

        // Clinic Schedule
        $schedule = $data['clinic_schedule'] ?? [];
        $parts[] = "\n### CLINIC SCHEDULE & SERVICES";
        $parts[] = "Location: Bulacan Polytechnic College (BPC) Main Campus Clinic";
        $parts[] = "Hours: Monday - Friday, 8:00 AM - 5:00 PM (Regular School Hours)";
        $parts[] = "Services: First Aid, Health Checkup/Monitoring, Consultations, Medicine Dispensing, Medical Certificates, Vaccine Records";

        // Current Queue / Active Visit
        $visit = $data['student_visit'] ?? [];
        if (!empty($visit) && $role === 'student') {
            if (isset($visit['active_queue'])) {
                $parts[] = "\n### TODAY'S CLINIC VISIT / QUEUE";
                $parts[] = "Active Queue Number: " . $visit['active_queue']['queue_number'] .
                           " (Status: " . ucfirst($visit['active_queue']['status']) . ")";
            }
        }

        // Nurse-Specific Live Summary
        if ($role === 'nurse') {
            $parts[] = "\n### NURSE CLINIC STATUS";
            try {
                $today = date('Y-m-d');
                $tApptStmt = $this->db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('pending','approved')");
                $tApptStmt->execute([$today]);
                $todayAppts = (int)$tApptStmt->fetchColumn();
                $parts[] = "- Today's Active Appointments: {$todayAppts}";
                $waitingQueue = (int)$this->db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'")->fetchColumn();
                $servingQueue = (int)$this->db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'serving'")->fetchColumn();
                $todayVisits  = (int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()")->fetchColumn();
                $parts[] = "- Live Queue: {$waitingQueue} waiting, {$servingQueue} in consultation";
                $parts[] = "- Today's Completed Consultations: {$todayVisits}";

                $sosStmt = $this->db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status = 'active'");
                $sosStmt = $this->db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('active','new','acknowledged','responding')");
                $activeSos = (int)$sosStmt->fetchColumn();
                $parts[] = "- Active SOS Alerts: {$activeSos}";

                $lowMedStmt = $this->db->query("SELECT COUNT(*) FROM medicine_inventory WHERE quantity <= min_quantity OR is_available = 0");
                $lowMedStmt = $this->db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level");
                $lowMeds = (int)$lowMedStmt->fetchColumn();
                $parts[] = "- Low Stock / Unavailable Medicines: {$lowMeds}";
                $parts[] = "- Low Stock / Alert Medicines: {$lowMeds}";
            } catch (PDOException $e) { /* fallback */ }
        }

        // Teacher / Adviser Specific Summary
        if ($role === 'adviser' || $role === 'teacher') {
            $parts[] = "\n### MY ADVISORY CLASSES";
            try {
                $aStmt = $this->db->prepare("SELECT id FROM teachers_advisers WHERE user_id = ? LIMIT 1");
                $aStmt->execute([$this->getUserId()]);
                $advId = $aStmt->fetchColumn();
                $advClasses = function_exists('get_adviser_active_assignment') 
                    ? get_adviser_active_assignment($this->db, $this->getUserId()) 
                    : [];
                if (!empty($advClasses)) {
                    foreach ($advClasses as $ac) {
                        $parts[] = "- Section " . ($ac['course'] ?? $ac['course_code'] ?? '') . " " . ($ac['section_name'] ?? '') . ": Assigned Class";
                    }
                } else {
                    $parts[] = "- No active advisory section currently assigned.";
                }
            } catch (Exception $e) { /* fallback */ }
        }

        // Admin Specific Summary
        if ($role === 'admin') {
            $parts[] = "\n### SYSTEM SUMMARY";
            try {
                $uStmt = $this->db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role");
                $roles = $uStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $parts[] = "- Users: " . implode(', ', array_map(fn($r, $c) => ucfirst($r) . ": {$c}", array_keys($roles), array_values($roles)));

                $cCount = (int)$this->db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
                $sCount = (int)$this->db->query("SELECT COUNT(*) FROM sections")->fetchColumn();
                $parts[] = "- Academic Setup: {$cCount} Courses, {$sCount} Sections";
            } catch (PDOException $e) { /* fallback */ }
        }

        return implode("\n", $parts);
    }
}
