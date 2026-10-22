<?php
/**
 * MICAAutomationEngine.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * Autonomous intelligence engine for proactive monitoring,
 * anomaly detection, reorder preparation, and scheduled workflows.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/MICAActionService.php';

class MICAAutomationEngine
{
    private PDO $db;
    private MICAActionService $actionService;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->actionService = new MICAActionService($db, ['id' => 0, 'role' => 'system']);
    }

    /**
     * Run all autonomous monitoring routines
     */
    public function runAllTasks(): array
    {
        require_once __DIR__.'/MICAHubService.php';
        return (new MICAHubService($this->db, ['id'=>0,'role'=>'admin']))->scan();
    }

    /**
     * 1. Deterministic inventory monitoring and deduplicated staff notifications.
     * Requisition quantities require staff confirmation; no automatic reorder is created.
     */
    public function checkLowStock(): array
    {
        require_once __DIR__ . '/../medicine_inventory_service.php';
        return (new MedicineInventoryService($this->db))->monitor();
    }

    /**
     * 2. Expiration Intelligence
     * Classifies medicines into Expired, Expiring in 7d, 30d, 60d.
     */
    public function checkExpiringMedicines(): array
    {
        $today = date('Y-m-d');
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

        $expired = [];
        $within7d = [];
        $within30d = [];
        $within60d = [];

        foreach ($expiring as $m) {
            $days = (int)$m['days_left'];
            if ($days < 0) {
                $expired[] = $m;
                $this->actionService->createAction([
                    'target_role'  => 'nurse',
                    'action_type'  => 'expiring_medicine',
                    'target_type'  => 'medicine',
                    'target_id'    => $m['id'],
                    'title'        => "EXPIRED MEDICINE: {$m['medicine_name']}",
                    'description'  => "Expired on {$m['expiration_date']} ({$m['quantity']} {$m['unit']} remaining). Blocked from dispensing.",
                    'reason'       => 'Medicine has passed its safety expiration date.',
                    'priority'     => 'critical',
                ]);
            } elseif ($days <= 7) {
                $within7d[] = $m;
                $this->actionService->createAction([
                    'target_role'  => 'nurse',
                    'action_type'  => 'expiring_medicine',
                    'target_type'  => 'medicine',
                    'target_id'    => $m['id'],
                    'title'        => "Expiring in {$days} Days: {$m['medicine_name']}",
                    'description'  => "Expires on {$m['expiration_date']} ({$m['quantity']} {$m['unit']} in stock).",
                    'reason'       => 'Approaching expiration within 7 days.',
                    'priority'     => 'high',
                ]);
            } elseif ($days <= 30) {
                $within30d[] = $m;
                $this->actionService->createAction([
                    'target_role'  => 'nurse',
                    'action_type'  => 'expiring_medicine',
                    'target_type'  => 'medicine',
                    'target_id'    => $m['id'],
                    'title'        => "Expiring in {$days} Days: {$m['medicine_name']}",
                    'description'  => "Expires on {$m['expiration_date']} ({$m['quantity']} {$m['unit']} in stock).",
                    'reason'       => 'Approaching expiration within 30 days.',
                    'priority'     => 'medium',
                ]);
            } else {
                $within60d[] = $m;
            }
        }

        return [
            'total'      => count($expiring),
            'expired'    => count($expired),
            'within_7d'  => count($within7d),
            'within_30d' => count($within30d),
            'within_60d' => count($within60d),
        ];
    }

    /**
     * 3. Student Lifecycle Monitoring
     * Flags cohorts approaching graduation for administrative review.
     */
    public function checkGraduatingStudents(): array
    {
        $stmt = $this->db->query("
            SELECT s.id, s.student_number, s.course, s.year_level, s.section,
                   u.first_name, u.last_name, u.status
            FROM students s
            JOIN users u ON u.id = s.user_id
            WHERE (s.year_level IN ('4', '4th Year', 'Fourth Year') OR s.lifecycle_status = 'graduating')
              AND s.lifecycle_status != 'graduated'
              AND u.status = 'active'
            ORDER BY u.last_name ASC
        ");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($students as $st) {
            $this->actionService->createAction([
                'target_role'  => 'admin',
                'action_type'  => 'graduating_student',
                'target_type'  => 'student',
                'target_id'    => $st['id'],
                'title'        => "Graduation Review: {$st['first_name']} {$st['last_name']}",
                'description'  => "Student #{$st['student_number']} ({$st['course']} {$st['year_level']}-{$st['section']}) is eligible for graduation lifecycle review.",
                'reason'       => 'Senior student lifecycle verification required.',
                'priority'     => 'medium',
                'suggested_data' => [
                    'student_id'     => $st['id'],
                    'student_number' => $st['student_number'],
                    'name'           => "{$st['first_name']} {$st['last_name']}",
                ]
            ]);
        }

        return [
            'count' => count($students)
        ];
    }

    /**
     * 4. Pending Account & Clinic Action Requests
     *
     * Pending account monitoring is owned by MICAHubService::scan() which
     * reconciles pending_review alerts against live AccountMonitoringService
     * counts and deduplicates across scans. This engine no longer emits
     * competing pending_review actions.
     */
    public function checkPendingRequests(): array
    {
        return [
            'pending_users'      => (int)$this->db->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn(),
            'active_emergencies' => (int)$this->db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('active','new','acknowledged','responding')")->fetchColumn(),
        ];
    }

    /**
     * 5. Anomaly Detection Engine
     * Detects sudden inventory depletion spikes, abnormal consultation influx, and failed logins.
     */
    public function detectInventoryAnomalies(): array
    {
        $anomalies = [];

        // Check for unusual stock outflows (> 40 units in 48 hours)
        $stmt = $this->db->query("
            SELECT ml.medicine_id, m.medicine_name, m.unit, SUM(ml.quantity) as total_out, COUNT(*) as log_events
            FROM medicine_logs ml
            JOIN medicines m ON m.id = ml.medicine_id
            WHERE ml.action = 'stock_out' AND ml.performed_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
            GROUP BY ml.medicine_id
            HAVING total_out >= 40
        ");
        $highOutflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($highOutflows as $ho) {
            $anomalies[] = [
                'type' => 'inventory_high_outflow',
                'medicine' => $ho['medicine_name'],
                'quantity_out' => $ho['total_out'],
                'details' => "High dispensing outflow of {$ho['total_out']} {$ho['unit']} over the past 48 hours ({$ho['log_events']} dispensing events)."
            ];

            $this->actionService->createAction([
                'target_role'  => 'nurse',
                'action_type'  => 'inventory_anomaly',
                'target_type'  => 'medicine',
                'target_id'    => $ho['medicine_id'],
                'title'        => "Stock Outflow Spike: {$ho['medicine_name']}",
                'description'  => "Dispensed {$ho['total_out']} {$ho['unit']} in the last 48 hours. Please verify consumption records.",
                'reason'       => 'Dispensing volume is unusually higher than standard baseline.',
                'priority'     => 'high',
            ]);
        }

        // Check for repeated failed logins (> 5 failed attempts on an account)
        $fStmt = $this->db->query("
            SELECT username, failed_login_count, last_failed_login 
            FROM users 
            WHERE failed_login_count >= 5 AND last_failed_login >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $failedLogins = $fStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($failedLogins as $fl) {
            $anomalies[] = [
                'type' => 'security_failed_logins',
                'username' => $fl['username'],
                'attempts' => $fl['failed_login_count']
            ];

            $this->actionService->createAction([
                'target_role'  => 'admin',
                'action_type'  => 'security_anomaly',
                'target_type'  => 'user',
                'target_id'    => null,
                'title'        => "Failed Login Spike: {$fl['username']}",
                'description'  => "Account has recorded {$fl['failed_login_count']} consecutive failed authentication attempts.",
                'reason'       => 'Multiple failed login threshold reached.',
                'priority'     => 'high',
            ]);
        }

        return [
            'count' => count($anomalies),
            'anomalies' => $anomalies
        ];
    }

    /**
     * 6. Daily Clinic Intelligence Summary Synthesis (Assistive Decision Support)
     */
    public function generateDailyClinicSummary(bool $includeInventory = true): array
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $todayVisits = (int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = '$today'")->fetchColumn();
        $yesterdayVisits = (int)$this->db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = '$yesterday'")->fetchColumn();
        $lowStockMeds = $expiringMeds = null;
        if ($includeInventory) {
            require_once __DIR__ . '/../medicine_inventory_service.php';
            $inventory = new MedicineInventoryService($this->db);
            $rows = $inventory->snapshot();
            $lowStockMeds = count(MedicineInventoryService::filter($rows, ['status'=>'low_stock'], $today));
            $expiringMeds = count(MedicineInventoryService::filter($rows, ['expiry'=>'expiring30'], $today));
        }
        $activeEmergencies = (int)$this->db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('active','new','acknowledged','responding')")->fetchColumn();
        $pendingUsers = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();

        $recommendedActions = [];
        if ($activeEmergencies > 0) {
            $recommendedActions[] = "Respond to {$activeEmergencies} active SOS emergency alert(s).";
        }
        if ($lowStockMeds > 0) {
            $recommendedActions[] = "Review {$lowStockMeds} medicine(s) currently below reorder threshold.";
        }
        if ($expiringMeds > 0) {
            $recommendedActions[] = "Inspect {$expiringMeds} medicine batch(es) expiring within 30 days.";
        }
        if ($pendingUsers > 0) {
            $recommendedActions[] = "Review {$pendingUsers} pending account verification(s).";
        }

        return [
            'today_visits'         => $todayVisits,
            'yesterday_visits'     => $yesterdayVisits,
            'low_stock_medicines'  => $lowStockMeds,
            'expiring_medicines'   => $expiringMeds,
            'active_emergencies'   => $activeEmergencies,
            'pending_users'        => $pendingUsers,
            'recommended_actions'  => $recommendedActions,
            'generated_at'         => date('Y-m-d H:i:s'),
        ];
    }
}
