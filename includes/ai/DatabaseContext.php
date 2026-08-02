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
     * Get appointments.
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
                SELECT a.*, u.first_name AS nurse_first, u.last_name AS nurse_last
                FROM appointments a
                LEFT JOIN nurses n ON n.id = a.nurse_id
                LEFT JOIN users u ON u.id = n.user_id
                WHERE a.student_id = ?
                ORDER BY a.appointment_date DESC, a.appointment_time DESC
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetchAll();
        }

        if ($role === 'nurse') {
            $stmt = $this->db->query("
                SELECT a.*, s.student_number, u.first_name, u.last_name
                FROM appointments a
                JOIN students s ON s.id = a.student_id
                JOIN users u ON u.id = s.user_id
                ORDER BY a.appointment_date DESC, a.appointment_time DESC
            ");
            return $stmt->fetchAll();
        }

        // Admin — analytics only
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) AS total_appointments,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                COUNT(DISTINCT student_id) AS total_students
            FROM appointments
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
                SELECT id, height_cm, weight_kg, bmi, bmi_status, recorded_at
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
            // Students can only see medicine availability status, not sensitive details
            $stmt = $this->db->query("
                SELECT 
                    COUNT(*) AS total_medicines,
                    SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) AS available,
                    SUM(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 ELSE 0 END) AS low_stock,
                    SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock
                FROM medicines
            ");
            return $stmt->fetchAll();
        }

        if ($role === 'nurse') {
            $stmt = $this->db->query("
                SELECT id, medicine_name, category, quantity, unit, reorder_level, expiration_date, batch_number,
                       CASE WHEN expiration_date IS NOT NULL AND expiration_date <= CURDATE() THEN 1 ELSE 0 END AS is_expired,
                       CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END AS is_low
                FROM medicines
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
                SUM(CASE WHEN expiration_date IS NOT NULL AND expiration_date <= CURDATE() THEN 1 ELSE 0 END) AS expired_count,
                ROUND(AVG(quantity), 0) AS average_quantity
            FROM medicines
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

        // Students and Nurses see relevant announcements
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

        // All roles can see clinic info, but admin sees all settings
        if ($role === 'admin') {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        }

        // Students and Nurses see only public info
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
    // GET ALL — Returns an array with all data types
    // ================================================================

    /**
     * Get all context data in one array.
     * Use this for building AI prompts.
     *
     * @return array
     */
    public function getAll(): array
    {
        $result = [];

        foreach (['medical_records', 'appointments', 'bmi_records', 'vaccinations', 'medicines', 'announcements'] as $key) {
            try {
                $result[$key] = match ($key) {
                    'medical_records' => $this->getMedicalRecords(),
                    'appointments'    => $this->getAppointments(),
                    'bmi_records'     => $this->getBmiRecords(),
                    'vaccinations'    => $this->getVaccinations(),
                    'medicines'       => $this->getMedicines(),
                    'announcements'   => $this->getAnnouncements(),
                };
            } catch (PDOException $e) {
                error_log('[MICA DB Error] Failed to fetch ' . $key . ': ' . $e->getMessage());
                $result[$key] = [];
            }
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
     * This is what getContextSummary() used to return.
     *
     * @return string
     */
    public function buildContextSummary(): string
    {
        $data = $this->getAll();
        $role = $this->getRole();
        $parts = [];

        $parts[] = "User Role: " . strtoupper($role);

        // Medical Records
        $records = $data['medical_records'];
        if (!empty($records)) {
            if ($role === 'admin') {
                $r = $records[0];
                $parts[] = "Medical Records Analytics: {$r['total_records']} total records, {$r['total_students_with_records']} students";
            } else {
                $parts[] = "Medical Records: " . count($records) . " records found";
            }
        }

        // Appointments
        $appts = $data['appointments'];
        if (!empty($appts)) {
            if ($role === 'admin') {
                $a = $appts[0];
                $parts[] = "Appointments: {$a['total_appointments']} total ({$a['pending']} pending, {$a['approved']} approved, {$a['completed']} completed)";
            } else {
                $upcoming = array_filter($appts, fn($a) => ($a['status'] ?? '') === 'approved' || ($a['status'] ?? '') === 'pending');
                $parts[] = "Appointments: " . count($upcoming) . " upcoming";
            }
        }

        // BMI
        $bmi = $data['bmi_records'];
        if (!empty($bmi)) {
            if ($role === 'admin') {
                $b = $bmi[0];
                $parts[] = "BMI Analytics: Avg {$b['average_bmi']}, Underweight {$b['underweight']}, Normal {$b['normal']}, Overweight {$b['overweight']}, Obese {$b['obese']}";
            } elseif ($role === 'student') {
                $latest = $bmi[0];
                $parts[] = "Latest BMI: {$latest['bmi']} ({$latest['bmi_status']})";
            }
        }

        // Vaccinations
        $vax = $data['vaccinations'];
        if (!empty($vax)) {
            if ($role === 'admin') {
                $v = $vax[0];
                $parts[] = "Vaccinations: {$v['total_vaccinations']} total, {$v['total_students_vaccinated']} students";
            } else {
                $parts[] = "Vaccination Records: " . count($vax) . " entries";
            }
        }

        // Medicines
        $meds = $data['medicines'];
        if (!empty($meds)) {
            if ($role === 'student') {
                $m = $meds[0];
                $parts[] = "Medicine Inventory: {$m['available']} available, {$m['low_stock']} low stock";
            } elseif ($role === 'nurse') {
                $low = array_filter($meds, fn($m) => ($m['is_low'] ?? 0) == 1);
                $parts[] = "Medicine Inventory: " . count($meds) . " items, " . count($low) . " low stock alerts";
            } else {
                $m = $meds[0];
                $parts[] = "Inventory Analytics: {$m['total_medicines']} items, {$m['low_stock_count']} low stock, {$m['expired_count']} expired";
            }
        }

        // Announcements
        $ann = $data['announcements'];
        if (!empty($ann)) {
            $parts[] = "Recent Announcements: " . count($ann) . " posted";
        }

        // Clinic Schedule
        $schedule = $data['clinic_schedule'];
        if (!empty($schedule)) {
            $name = $schedule['clinic_name'] ?? 'BPC Clinic';
            $parts[] = "Clinic: {$name}";
        }

        return implode("\n", $parts);
    }
}

