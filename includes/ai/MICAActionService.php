<?php
/**
 * MICAActionService.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * Implements the Suggest -> Confirm -> Execute -> Verify -> Log workflow
 * for all AI-assisted clinic operations.
 * ------------------------------------------------------------------
 */

class MICAActionService
{
    private PDO $db;
    private array $user;

    public function __construct(PDO $db, array $user)
    {
        $this->db = $db;
        $this->user = $user;
    }

    /**
     * Create a structured AI Recommendation / Action card
     */
    public function createAction(array $data): int
    {
        $targetRole  = $data['target_role'] ?? 'all';
        $actionType  = $data['action_type'] ?? 'general';
        $targetType  = $data['target_type'] ?? 'general';
        $targetId    = !empty($data['target_id']) ? (int)$data['target_id'] : null;
        $title       = trim($data['title'] ?? 'AI Recommendation');
        $description = trim($data['description'] ?? '');
        $reason      = trim($data['reason'] ?? '');
        $priority    = in_array($data['priority'] ?? '', ['critical', 'high', 'medium', 'informational'], true) ? $data['priority'] : 'medium';
        $suggested   = !empty($data['suggested_data']) ? (is_array($data['suggested_data']) ? json_encode($data['suggested_data']) : $data['suggested_data']) : null;

        // Prevent duplicate unresolved actions of same type and target
        if ($targetType && $targetId) {
            $stmt = $this->db->prepare("
                SELECT id FROM ai_actions 
                WHERE action_type = ? AND target_type = ? AND target_id = ? AND status IN ('new', 'reviewed')
                LIMIT 1
            ");
            $stmt->execute([$actionType, $targetType, $targetId]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                return (int)$existing;
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO ai_actions (user_id, target_role, action_type, target_type, target_id, title, description, reason, priority, status, suggested_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?)
        ");
        $stmt->execute([
            $this->user['id'] ?? null,
            $targetRole,
            $actionType,
            $targetType,
            $targetId,
            $title,
            $description,
            $reason,
            $priority,
            $suggested
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Retrieve active recommended actions filtered by role
     */
    public function getRecommendedActions(?string $role = null, int $limit = 15): array
    {
        $role = $role ?: ($this->user['role'] ?? 'nurse');
        $stmt = $this->db->prepare("
            SELECT a.*, u.first_name, u.last_name
            FROM ai_actions a
            LEFT JOIN users u ON u.id = a.executed_by
            WHERE (a.target_role = 'all' OR a.target_role = ?)
              AND a.status IN ('new', 'reviewed')
            ORDER BY 
              CASE a.priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                ELSE 4
              END,
              a.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $role, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create or retrieve Medicine Reorder Request (Status: FOR APPROVAL)
     * Enforces human-in-the-loop: Nurse/Admin must review and approve.
     */
    public function createReorderRequest(int $medicineId, int $suggestedQty, string $reason): array
    {
        $mStmt = $this->db->prepare("SELECT * FROM medicines WHERE id = ? AND is_archived = 0");
        $mStmt->execute([$medicineId]);
        $med = $mStmt->fetch(PDO::FETCH_ASSOC);
        if (!$med) {
            return ['success' => false, 'message' => 'Medicine not found.'];
        }

        // Check for existing pending request to prevent duplicates
        $chk = $this->db->prepare("SELECT id FROM medicine_reorders WHERE medicine_id = ? AND status = 'for_approval' LIMIT 1");
        $chk->execute([$medicineId]);
        $existingId = $chk->fetchColumn();
        if ($existingId) {
            return [
                'success' => true,
                'reorder_id' => (int)$existingId,
                'message' => "A pending reorder request already exists for {$med['medicine_name']} (Request #$existingId).",
                'duplicate' => true
            ];
        }

        $stmt = $this->db->prepare("
            INSERT INTO medicine_reorders (medicine_id, current_stock, reorder_level, suggested_quantity, approved_quantity, reason, status, requested_by)
            VALUES (?, ?, ?, ?, ?, ?, 'for_approval', ?)
        ");
        $stmt->execute([
            $medicineId,
            (int)$med['quantity'],
            (int)$med['reorder_level'],
            max(1, $suggestedQty),
            max(1, $suggestedQty),
            $reason ?: "Stock level is at or below minimum threshold.",
            $this->user['id'] ?? null
        ]);
        $reorderId = (int)$this->db->lastInsertId();

        // Log AI action
        $this->createAction([
            'target_role'  => 'nurse',
            'action_type'  => 'low_stock_reorder',
            'target_type'  => 'medicine',
            'target_id'    => $medicineId,
            'title'        => "Reorder Request: {$med['medicine_name']}",
            'description'  => "Stock is {$med['quantity']} {$med['unit']} (Threshold: {$med['reorder_level']}). Suggested replenishment: {$suggestedQty} {$med['unit']}.",
            'reason'       => $reason,
            'priority'     => ($med['quantity'] <= 0) ? 'critical' : 'high',
            'suggested_data' => [
                'reorder_id'    => $reorderId,
                'medicine_id'   => $medicineId,
                'medicine_name' => $med['medicine_name'],
                'current_stock' => $med['quantity'],
                'suggested_qty' => $suggestedQty,
            ]
        ]);

        log_activity($this->user['id'] ?? 0, 'Created medicine reorder request', 'inventory', "Medicine #$medicineId (Reorder #$reorderId)");

        return [
            'success'    => true,
            'reorder_id' => $reorderId,
            'message'    => "Reorder request prepared for {$med['medicine_name']} (Suggested: {$suggestedQty} {$med['unit']}). Awaiting Nurse/Admin approval.",
            'duplicate'  => false
        ];
    }

    /**
     * Approve Medicine Reorder Request
     */
    public function approveReorderRequest(int $reorderId, int $approvedQty, ?string $notes = null): array
    {
        if (!in_array($this->user['role'] ?? '', ['nurse', 'admin', 'doctor'], true)) {
            return ['success' => false, 'message' => 'Unauthorized. Only clinical staff or administrators can approve reorders.'];
        }

        $stmt = $this->db->prepare("SELECT r.*, m.medicine_name, m.unit FROM medicine_reorders r JOIN medicines m ON m.id = r.medicine_id WHERE r.id = ?");
        $stmt->execute([$reorderId]);
        $reorder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reorder) {
            return ['success' => false, 'message' => 'Reorder request not found.'];
        }

        $this->db->prepare("
            UPDATE medicine_reorders 
            SET status = 'approved', approved_quantity = ?, approved_by = ?, approved_at = NOW(), notes = ?
            WHERE id = ?
        ")->execute([max(1, $approvedQty), $this->user['id'], $notes, $reorderId]);

        // Resolve matching AI action
        $this->db->prepare("
            UPDATE ai_actions 
            SET status = 'approved', executed_by = ?, executed_at = NOW() 
            WHERE target_type = 'medicine' AND target_id = ? AND action_type = 'low_stock_reorder'
        ")->execute([$this->user['id'], $reorder['medicine_id']]);

        log_activity($this->user['id'], 'Approved medicine reorder request', 'inventory', "Reorder #$reorderId for {$reorder['medicine_name']} ({$approvedQty} {$reorder['unit']})");

        return [
            'success' => true,
            'message' => "Reorder request #$reorderId for {$reorder['medicine_name']} approved successfully for {$approvedQty} {$reorder['unit']}. Ready to print."
        ];
    }

    /**
     * Reject Medicine Reorder Request
     */
    public function rejectReorderRequest(int $reorderId, ?string $notes = null): array
    {
        if (!in_array($this->user['role'] ?? '', ['nurse', 'admin', 'doctor'], true)) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        $this->db->prepare("
            UPDATE medicine_reorders 
            SET status = 'rejected', approved_by = ?, approved_at = NOW(), notes = ?
            WHERE id = ?
        ")->execute([$this->user['id'], $notes, $reorderId]);

        log_activity($this->user['id'], 'Rejected medicine reorder request', 'inventory', "Reorder #$reorderId");

        return ['success' => true, 'message' => "Reorder request #$reorderId was rejected."];
    }

    /**
     * Process official Student Graduation lifecycle deactivation.
     * Preserves all medical records, consultations, and appointment history.
     */
    public function deactivateGraduatedStudent(int $studentId, ?string $notes = null): array
    {
        if (($this->user['role'] ?? '') !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized. Only Administrators can officially confirm student graduation deactivation.'];
        }

        $sStmt = $this->db->prepare("SELECT s.*, u.first_name, u.last_name, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?");
        $sStmt->execute([$studentId]);
        $student = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            return ['success' => false, 'message' => 'Student record not found.'];
        }

        $this->db->beginTransaction();
        try {
            // 1. Update Student lifecycle status & graduation date
            $this->db->prepare("
                UPDATE students 
                SET lifecycle_status = 'graduated', graduation_date = CURDATE()
                WHERE id = ?
            ")->execute([$studentId]);

            // 2. Set User account status to inactive (blocks login while preserving all data)
            $this->db->prepare("
                UPDATE users 
                SET status = 'inactive'
                WHERE id = ?
            ")->execute([$student['user_id']]);

            // 3. Resolve AI action
            $this->db->prepare("
                UPDATE ai_actions 
                SET status = 'completed', executed_by = ?, executed_at = NOW() 
                WHERE target_type = 'student' AND target_id = ? AND action_type = 'graduating_student'
            ")->execute([$this->user['id'], $studentId]);

            $this->db->commit();

            log_activity(
                $this->user['id'],
                'Confirmed student graduation and deactivated login',
                'users',
                "Student: {$student['first_name']} {$student['last_name']} (Student #{$student['student_number']})"
            );

            return [
                'success' => true,
                'message' => "Student {$student['first_name']} {$student['last_name']} (Student #{$student['student_number']}) officially marked as GRADUATED. Login deactivated; all medical and clinic records preserved."
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'message' => 'Graduation processing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Update AI Action Status (reviewed, dismissed, approved, rejected)
     */
    public function updateActionStatus(int $actionId, string $status): array
    {
        $allowed = ['new', 'reviewed', 'approved', 'rejected', 'completed'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'Invalid status.'];
        }

        $stmt = $this->db->prepare("UPDATE ai_actions SET status = ?, executed_by = ?, executed_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $this->user['id'] ?? null, $actionId]);

        return ['success' => true, 'message' => "Action status updated to " . ucfirst($status) . "."];
    }
}