<?php
// scratch/apply_security_fixes.php

// 1. Update api/medical_records.php
$medRecPath = __DIR__ . '/../api/medical_records.php';
$content = file_get_contents($medRecPath);

// Update list action
$oldList = 'if ($action === \'list\') {
    if ($user[\'role\'] === \'student\') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user[\'id\']]);
        $studentId = $stmt->fetchColumn();
        if (!$studentId) json_response(false, [], \'Student record not found.\');

        $stmt = $db->prepare("
            SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment
            FROM medical_records mr
            JOIN consultations c ON c.id = mr.consultation_id
            WHERE mr.student_id = ?
            ORDER BY c.consultation_date DESC
        ");
        $stmt->execute([$studentId]);
    } else {
        $studentId = (int)($_GET[\'student_id\'] ?? 0);
        $search = trim($_GET[\'search\'] ?? \'\');
        $where = \'1=1\';
        $params = [];
        if ($studentId > 0) { $where .= \' AND mr.student_id = ?\'; $params[] = $studentId; }
        if ($search !== \'\') {
            $where .= \' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.student_number LIKE ? OR c.chief_complaint LIKE ? OR c.diagnosis LIKE ?)\';
            $like = \'%\' . $search . \'%\';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        $stmt = $db->prepare("
            SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment,
                   u.first_name, u.last_name, s.student_number
            FROM medical_records mr
            JOIN consultations c ON c.id = mr.consultation_id
            JOIN students s ON s.id = mr.student_id
            JOIN users u ON u.id = s.user_id
            WHERE $where
            ORDER BY c.consultation_date DESC
        ");
        $stmt->execute($params);
    }
    json_response(true, [\'data\' => $stmt->fetchAll()]);
}';

$newList = 'if ($action === \'list\') {
    $role = canonical_role($user[\'role\'] ?? \'\');
    if ($role === \'student\') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user[\'id\']]);
        $studentId = $stmt->fetchColumn();
        if (!$studentId) json_response(false, [], \'Student record not found.\');

        $stmt = $db->prepare("
            SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment
            FROM medical_records mr
            JOIN consultations c ON c.id = mr.consultation_id
            WHERE mr.student_id = ?
            ORDER BY c.consultation_date DESC
        ");
        $stmt->execute([$studentId]);
    } elseif (in_array($role, [\'nurse\', \'admin\'], true)) {
        $studentId = (int)($_GET[\'student_id\'] ?? 0);
        $search = trim($_GET[\'search\'] ?? \'\');
        $where = \'1=1\';
        $params = [];
        if ($studentId > 0) { $where .= \' AND mr.student_id = ?\'; $params[] = $studentId; }
        if ($search !== \'\') {
            $where .= \' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.student_number LIKE ? OR c.chief_complaint LIKE ? OR c.diagnosis LIKE ?)\';
            $like = \'%\' . $search . \'%\';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        $stmt = $db->prepare("
            SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment,
                   u.first_name, u.last_name, s.student_number
            FROM medical_records mr
            JOIN consultations c ON c.id = mr.consultation_id
            JOIN students s ON s.id = mr.student_id
            JOIN users u ON u.id = s.user_id
            WHERE $where
            ORDER BY c.consultation_date DESC
        ");
        $stmt->execute($params);
    } else {
        json_response(false, [], \'Access denied.\');
    }
    json_response(true, [\'data\' => $stmt->fetchAll()]);
}';

// Normalize line endings for replacement
$contentNorm = str_replace("\r\n", "\n", $content);
$oldListNorm = str_replace("\r\n", "\n", $oldList);
$newListNorm = str_replace("\r\n", "\n", $newList);

if (str_contains($contentNorm, $oldListNorm)) {
    $contentNorm = str_replace($oldListNorm, $newListNorm, $contentNorm);
    echo "api/medical_records.php list updated.\n";
} else {
    echo "api/medical_records.php list NOT matched.\n";
}

// Update consultations and detail checks
$contentNorm = str_replace(
    'if ($action === \'consultations\') {' . "\n" . '    if ($user[\'role\'] !== \'nurse\') json_response(false, [], \'Not authorized.\');',
    'if ($action === \'consultations\') {' . "\n" . '    if (!in_array(canonical_role($user[\'role\'] ?? \'\'), [\'nurse\', \'admin\'], true)) json_response(false, [], \'Not authorized.\');',
    $contentNorm
);

$contentNorm = str_replace(
    'if ($action === \'detail\') {' . "\n" . '    if ($user[\'role\'] !== \'nurse\') json_response(false, [], \'Not authorized.\');',
    'if ($action === \'detail\') {' . "\n" . '    if (!in_array(canonical_role($user[\'role\'] ?? \'\'), [\'nurse\', \'admin\'], true)) json_response(false, [], \'Not authorized.\');',
    $contentNorm
);

$contentNorm = str_replace(
    'if ($action === \'delete\') {' . "\n" . '    csrf_verify_or_die();' . "\n" . '    if ($user[\'role\'] !== \'nurse\') json_response(false, [], \'Not authorized.\');',
    'if ($action === \'delete\') {' . "\n" . '    csrf_verify_or_die();' . "\n" . '    if (!in_array(canonical_role($user[\'role\'] ?? \'\'), [\'nurse\', \'admin\'], true)) json_response(false, [], \'Not authorized.\');',
    $contentNorm
);

file_put_contents($medRecPath, str_replace("\n", "\r\n", $contentNorm));

// 2. Update api/health_monitoring.php
$hmPath = __DIR__ . '/../api/health_monitoring.php';
$hmContent = str_replace("\r\n", "\n", file_get_contents($hmPath));

$oldHmHistory = 'if ($action === \'history\') {
    if ($user[\'role\'] === \'student\') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user[\'id\']]);
        $studentId = $stmt->fetchColumn();
        if (!$studentId) json_response(false, [], \'Student record not found.\');
    } else {
        $studentId = (int)($_GET[\'student_id\'] ?? 0);
        if ($studentId <= 0) json_response(false, [], \'Invalid student.\');
    }';

$newHmHistory = 'if ($action === \'history\') {
    $role = canonical_role($user[\'role\'] ?? \'\');
    if ($role === \'student\') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user[\'id\']]);
        $studentId = $stmt->fetchColumn();
        if (!$studentId) json_response(false, [], \'Student record not found.\');
    } elseif ($role === \'teacher\') {
        $studentId = (int)($_GET[\'student_id\'] ?? 0);
        if ($studentId <= 0) json_response(false, [], \'Invalid student.\');
        require_adviser_student_access($db, $studentId);
    } elseif (in_array($role, [\'nurse\', \'admin\'], true)) {
        $studentId = (int)($_GET[\'student_id\'] ?? 0);
        if ($studentId <= 0) json_response(false, [], \'Invalid student.\');
    } else {
        json_response(false, [], \'Access denied.\');
    }';

if (str_contains($hmContent, $oldHmHistory)) {
    $hmContent = str_replace($oldHmHistory, $newHmHistory, $hmContent);
    file_put_contents($hmPath, str_replace("\n", "\r\n", $hmContent));
    echo "api/health_monitoring.php history updated.\n";
} else {
    echo "api/health_monitoring.php history NOT matched.\n";
}

// 3. Update api/sections.php
$secPath = __DIR__ . '/../api/sections.php';
$secContent = str_replace("\r\n", "\n", file_get_contents($secPath));

$oldSecStudents = 'if ($action === \'section_students\') {
    $id = (int)($_GET[\'id\'] ?? 0);
    if ($id <= 0) json_response(false, [], \'Invalid section ID.\');

    $secStmt = $db->prepare("SELECT * FROM sections WHERE id = ? LIMIT 1");
    $secStmt->execute([$id]);
    $sec = $secStmt->fetch();
    if (!$sec) json_response(false, [], \'Section not found.\');';

$newSecStudents = 'if ($action === \'section_students\') {
    $id = (int)($_GET[\'id\'] ?? 0);
    if ($id <= 0) json_response(false, [], \'Invalid section ID.\');

    $secStmt = $db->prepare("SELECT * FROM sections WHERE id = ? LIMIT 1");
    $secStmt->execute([$id]);
    $sec = $secStmt->fetch();
    if (!$sec) json_response(false, [], \'Section not found.\');

    if (canonical_role($user[\'role\'] ?? \'\') === \'teacher\') {
        $assignedStmt = $db->prepare("
            SELECT id FROM advisory_assignments 
            WHERE teacher_id = ? AND section_id = ? AND status = \'active\'
              AND (end_date IS NULL OR end_date >= CURDATE())
              AND (start_date IS NULL OR start_date <= CURDATE())
            LIMIT 1
        ");
        $assignedStmt->execute([(int)$user[\'id\'], $id]);
        if (!$assignedStmt->fetch()) {
            json_response(false, [], \'Access denied. You are not assigned as adviser to this section.\');
        }
    }';

if (str_contains($secContent, $oldSecStudents)) {
    $secContent = str_replace($oldSecStudents, $newSecStudents, $secContent);
    file_put_contents($secPath, str_replace("\n", "\r\n", $secContent));
    echo "api/sections.php section_students updated.\n";
} else {
    echo "api/sections.php section_students NOT matched.\n";
}

// 4. Update api/dispensing.php
$dispPath = __DIR__ . '/../api/dispensing.php';
$dispContent = str_replace("\r\n", "\n", file_get_contents($dispPath));

// Replace require_role('nurse') with require_login()
$dispContent = preg_replace('/^require_role\(\'nurse\'\);/m', 'require_login();', $dispContent);

// search_medicines
$dispContent = str_replace(
    'if ($action === \'search_medicines\') {',
    'if ($action === \'search_medicines\') {' . "\n" . '    if (!in_array(canonical_role($user[\'role\'] ?? \'\'), [\'nurse\', \'admin\'], true)) json_response(false, [], \'Not authorized.\');',
    $dispContent
);

// dispense
$dispContent = str_replace(
    'if ($action === \'dispense\') {' . "\n" . '    csrf_verify_or_die();',
    'if ($action === \'dispense\') {' . "\n" . '    csrf_verify_or_die();' . "\n" . '    if (canonical_role($user[\'role\'] ?? \'\') !== \'nurse\') json_response(false, [], \'Only nurses can dispense medicine.\');',
    $dispContent
);

// search_students
$dispContent = str_replace(
    'if ($action === \'search_students\') {',
    'if ($action === \'search_students\') {' . "\n" . '    if (!in_array(canonical_role($user[\'role\'] ?? \'\'), [\'nurse\', \'admin\'], true)) json_response(false, [], \'Not authorized.\');',
    $dispContent
);

// all_history
$dispContent = str_replace(
    'if ($action === \'all_history\') {',
    'if ($action === \'all_history\') {' . "\n" . '    if (!in_array(canonical_role($user[\'role\'] ?? \'\'), [\'nurse\', \'admin\'], true)) json_response(false, [], \'Not authorized.\');',
    $dispContent
);

// history
$oldDispHistory = 'if ($action === \'history\') {
    $studentId = (int)($_GET[\'student_id\'] ?? 0);
    if ($studentId <= 0) json_response(false, [], \'Invalid student.\');

    // IDOR protection: students can only access their own history
    if ($user[\'role\'] === \'student\') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user[\'id\']]);
        $myStudentId = (int)$stmt->fetchColumn();
        if ($myStudentId <= 0 || $studentId !== $myStudentId) {
            json_response(false, [], \'Not authorized to view this dispensing history.\');
        }
    }';

$newDispHistory = 'if ($action === \'history\') {
    $studentId = (int)($_GET[\'student_id\'] ?? 0);
    if ($studentId <= 0) json_response(false, [], \'Invalid student.\');

    $role = canonical_role($user[\'role\'] ?? \'\');
    // IDOR protection: students can only access their own history
    if ($role === \'student\') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user[\'id\']]);
        $myStudentId = (int)$stmt->fetchColumn();
        if ($myStudentId <= 0 || $studentId !== $myStudentId) {
            json_response(false, [], \'Not authorized to view this dispensing history.\');
        }
    } elseif ($role === \'teacher\') {
        require_adviser_student_access($db, $studentId);
    } elseif (!in_array($role, [\'nurse\', \'admin\'], true)) {
        json_response(false, [], \'Not authorized.\');
    }';

if (str_contains($dispContent, $oldDispHistory)) {
    $dispContent = str_replace($oldDispHistory, $newDispHistory, $dispContent);
    file_put_contents($dispPath, str_replace("\n", "\r\n", $dispContent));
    echo "api/dispensing.php history updated.\n";
} else {
    echo "api/dispensing.php history NOT matched.\n";
}

// 5. Update api/inventory.php
$invPath = __DIR__ . '/../api/inventory.php';
$invContent = str_replace("\r\n", "\n", file_get_contents($invPath));

$oldStockUpdate = '    if (!in_array($type, [\'stock_in\', \'stock_out\', \'adjustment\'], true) || $qty <= 0) json_response(false, [], \'Invalid input.\');

    $stmt = $db->prepare("SELECT quantity FROM medicines WHERE id=?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if ($current === false) json_response(false, [], \'Medicine not found.\');

    if ($type === \'stock_in\') {
        $newQty = (int)$current + $qty;
    } elseif ($type === \'stock_out\') {
        if ($qty > (int)$current) json_response(false, [], \'Not enough stock available.\');
        $newQty = (int)$current - $qty;
    } else { // adjustment: set absolute quantity
        $newQty = $qty;
    }

    $db->prepare("UPDATE medicines SET quantity=? WHERE id=?")->execute([$newQty, $id]);
    update_medicine_status($db, $id);
    $db->prepare("INSERT INTO inventory_logs (medicine_id, change_type, quantity_change, remarks, logged_by) VALUES (?,?,?,?,?)")
       ->execute([$id, $type === \'adjustment\' ? \'adjustment\' : ($type === \'stock_in\' ? \'in\' : \'out\'), $newQty - (int)$current, $reason, $user[\'id\']]);

    log_activity($user[\'id\'], ucfirst(str_replace(\'_\', \' \', $type)), \'inventory\', "Medicine #$id qty $qty");
    json_response(true, [\'new_quantity\' => $newQty], \'Stock updated.\');';

$newStockUpdate = '    if (!in_array($type, [\'stock_in\', \'stock_out\', \'adjustment\'], true)) {
        json_response(false, [], \'Invalid stock action type.\');
    }
    if ($type === \'adjustment\' && $qty < 0) {
        json_response(false, [], \'Adjustment quantity cannot be negative.\');
    }
    if ($type !== \'adjustment\' && $qty <= 0) {
        json_response(false, [], \'Quantity must be greater than zero.\');
    }

    $stmt = $db->prepare("SELECT quantity FROM medicines WHERE id=?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if ($current === false) json_response(false, [], \'Medicine not found.\');

    if ($type === \'stock_in\') {
        $newQty = (int)$current + $qty;
    } elseif ($type === \'stock_out\') {
        if ($qty > (int)$current) json_response(false, [], \'Not enough stock available.\');
        $newQty = (int)$current - $qty;
    } else { // adjustment: set absolute quantity
        $newQty = $qty;
    }

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE medicines SET quantity=? WHERE id=?")->execute([$newQty, $id]);
        update_medicine_status($db, $id);
        $db->prepare("INSERT INTO inventory_logs (medicine_id, change_type, quantity_change, remarks, logged_by) VALUES (?,?,?,?,?)")
           ->execute([$id, $type === \'adjustment\' ? \'adjustment\' : ($type === \'stock_in\' ? \'in\' : \'out\'), $newQty - (int)$current, $reason, $user[\'id\']]);

        log_activity($user[\'id\'], ucfirst(str_replace(\'_\', \' \', $type)), \'inventory\', "Medicine #$id qty $qty (new total: $newQty)");
        $db->commit();
        json_response(true, [\'new_quantity\' => $newQty], \'Stock updated.\');
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, [], safe_api_error($e, \'Failed to update stock. Please try again.\', \'inventory/stock_update\'));
    }';

if (str_contains($invContent, $oldStockUpdate)) {
    $invContent = str_replace($oldStockUpdate, $newStockUpdate, $invContent);
    file_put_contents($invPath, str_replace("\n", "\r\n", $invContent));
    echo "api/inventory.php stock_update updated.\n";
} else {
    echo "api/inventory.php stock_update NOT matched.\n";
}

