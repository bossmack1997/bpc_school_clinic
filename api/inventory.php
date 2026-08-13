<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','nurse']);
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

// ================================================================
// Helper: compute medicine status using shared function
// ================================================================
function inv_status($m) {
    return medicine_status($m['quantity'], $m['reorder_level'], $m['expiration_date']);
}

// Fire automated stock/expiry alerts after a medicine mutation.
function fire_med_alerts($db, $medicineId) {
    $stmt = $db->prepare("SELECT * FROM medicines WHERE id = ? AND is_archived = 0");
    $stmt->execute([$medicineId]);
    $m = $stmt->fetch();
    if (!$m) return;
    $status = medicine_status($m['quantity'], $m['reorder_level'], $m['expiration_date']);
    if (in_array($status, ['Low Stock', 'Out of Stock', 'Near Expiration', 'Expired'], true)) {
        notify_staff_medicine_alert($db, $m, $status);
    }
}

// ================================================================
// LIST — all medicines with computed statuses + category filter
// ================================================================
if ($action === 'list') {
    $cat = trim($_GET['category'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $expiry = trim($_GET['expiry'] ?? '');   // all | expiring30 | expired
    $search = trim($_GET['search'] ?? '');
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);

    $where = "WHERE m.is_archived = 0";
    $params = [];

    if ($cat !== '') { $where .= " AND m.category = ?"; $params[] = $cat; }
    if ($status !== '') {
        $today = date('Y-m-d');
        switch ($status) {
            case 'available':
                $where .= " AND m.quantity > m.reorder_level AND (m.expiration_date IS NULL OR m.expiration_date > DATE_ADD(?, INTERVAL 30 DAY))";
                $params[] = $today;
                break;
            case 'low_stock':
                $where .= " AND m.quantity > 0 AND m.quantity <= m.reorder_level AND (m.expiration_date IS NULL OR m.expiration_date >= ?)";
                $params[] = $today;
                break;
            case 'out_of_stock':
                $where .= " AND m.quantity <= 0";
                break;
            case 'expired':
                $where .= " AND m.expiration_date IS NOT NULL AND m.expiration_date < ?";
                $params[] = $today;
                break;
            case 'near_expiration':
                $where .= " AND m.expiration_date IS NOT NULL AND m.expiration_date BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)";
                $params[] = $today;
                $params[] = $today;
                break;
        }
    }
    if ($search !== '') {
        $where .= " AND (m.medicine_name LIKE ? OR m.generic_name LIKE ? OR m.supplier LIKE ? OR m.category LIKE ? OR m.batch_number LIKE ? OR m.barcode LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }
    if ($expiry === 'expiring30') {
        $where .= " AND m.expiration_date IS NOT NULL AND m.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($expiry === 'expired') {
        $where .= " AND m.expiration_date IS NOT NULL AND m.expiration_date < CURDATE()";
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM medicines m $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT m.* FROM medicines m $where ORDER BY m.medicine_name ASC LIMIT ? OFFSET ?");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Compute status in PHP
    foreach ($rows as &$r) {
        $r['status'] = inv_status($r);
    }
    unset($r);

    json_response(true, ['data' => $rows, 'total' => $total]);
}

// ================================================================
// STATS — dashboard cards
// ================================================================
if ($action === 'stats') {
    $total = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0")->fetchColumn();
    $categories = (int)$db->query("SELECT COUNT(DISTINCT category) FROM medicines WHERE is_archived = 0 AND category IS NOT NULL AND category <> ''")->fetchColumn();
    $avail = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity > reorder_level AND (expiration_date IS NULL OR expiration_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY))")->fetchColumn();
    $low = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity > 0 AND quantity <= reorder_level AND (expiration_date IS NULL OR expiration_date > CURDATE())")->fetchColumn();
    $expired = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND expiration_date IS NOT NULL AND expiration_date < CURDATE()")->fetchColumn();
    $nearExp = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND expiration_date IS NOT NULL AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $outOfStock = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= 0")->fetchColumn();

// "Dispensed Today" counts BOTH the walk-in dispensing flow (medicine_dispensing)
    // AND the standalone Medicine Dispensing module (medicine_logs action='stock_out').
    $dispensedToday = (int)$db->query(
        "SELECT COALESCE((SELECT COALESCE(SUM(quantity),0) FROM medicine_dispensing md JOIN walk_in_visits w ON w.id = md.walk_in_id WHERE w.visit_date = CURDATE())
                       + (SELECT COALESCE(SUM(quantity),0) FROM medicine_logs ml WHERE ml.action = 'stock_out' AND ml.student_id IS NOT NULL AND DATE(ml.performed_at) = CURDATE()), 0)"
    )->fetchColumn();

    $monthStart = date('Y-m-01');
    $stmtMonth = $db->prepare(
        "SELECT COALESCE((SELECT COALESCE(SUM(md.quantity),0) FROM medicine_dispensing md JOIN walk_in_visits w ON w.id = md.walk_in_id WHERE w.visit_date >= ?)
                       + (SELECT COALESCE(SUM(ml.quantity),0) FROM medicine_logs ml WHERE ml.action = 'stock_out' AND ml.student_id IS NOT NULL AND DATE(ml.performed_at) >= ?), 0)"
    );
    $stmtMonth->execute([$monthStart, $monthStart]);
    $dispensedMonth = (int)$stmtMonth->fetchColumn();

    json_response(true, [
        'total_medicines' => $total,
        'available' => $avail,
        'low_stock' => $low,
        'out_of_stock' => $outOfStock,
        'expired' => $expired,
        'near_expiration' => $nearExp,
        'dispensed_today' => $dispensedToday,
        'dispensed_month' => $dispensedMonth,
        'total_categories' => $categories,
    ]);
}

// ================================================================
// CATEGORIES — distinct list for filters
// ================================================================
if ($action === 'categories') {
    $rows = $db->query("SELECT DISTINCT category FROM medicines WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll();
    json_response(true, ['data' => array_map(fn($r) => $r['category'], $rows)]);
}

// ================================================================
// ADD — professional medicine form
// ----------------------------------------------------
// Workflow: validate -> auth (nurse) -> duplicate check ->
// (insert OR increase existing stock) -> log -> alert -> notify
// ================================================================
if ($action === 'add') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can add medicines.');

    $medicineName   = trim($_POST['medicine_name'] ?? '');
    $genericName    = trim($_POST['generic_name'] ?? '');
    $brand          = trim($_POST['brand'] ?? '');
    $category       = trim($_POST['category'] ?? '');
    $dosage         = trim($_POST['dosage'] ?? '');
    $form           = trim($_POST['form'] ?? '');
    $supplier       = trim($_POST['supplier'] ?? '');
    $unit           = trim($_POST['unit'] ?? 'pcs') ?: 'pcs';
    $batch          = trim($_POST['batch_number'] ?? '');
    $barcode        = trim($_POST['barcode'] ?? '');
    $location       = trim($_POST['storage_location'] ?? '');
    $desc           = trim($_POST['description'] ?? '');
    $manufacturer   = trim($_POST['manufacturer'] ?? '');

    // --- Required field: medicine name ---
    if ($medicineName === '') json_response(false, [], 'Medicine name is required.');

    // --- Quantity: must be a non-negative number ---
    $rawQty = trim((string)($_POST['quantity'] ?? ''));
    if ($rawQty === '' || !is_numeric($rawQty)) {
        json_response(false, [], 'Quantity must be a valid number.');
    }
    $quantity = (int)$rawQty;
    if ($quantity < 0) json_response(false, [], 'Quantity cannot be negative.');

    // --- Reorder level: non-negative integer ---
    $rawReorder = trim((string)($_POST['reorder_level'] ?? '20'));
    if ($rawReorder === '' || !is_numeric($rawReorder) || (int)$rawReorder < 0) {
        json_response(false, [], 'Minimum stock must be a non-negative number.');
    }
    $reorder = (int)$rawReorder;

    // --- Expiration date: if provided, valid format and not in the past ---
    $expDate = trim((string)($_POST['expiration_date'] ?? ''));
    if ($expDate !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $expDate);
        if (!$d || $d->format('Y-m-d') !== $expDate) {
            json_response(false, [], 'Please provide a valid expiration date (YYYY-MM-DD).');
        }
        if ($expDate < date('Y-m-d')) {
            json_response(false, [], 'Expiration date cannot be in the past.');
        }
    }

    // --- Duplicate handling: an active medicine with the same name already
    //     exists -> increase its stock instead of creating a duplicate row. ---
    $dupStmt = $db->prepare("SELECT id, quantity, medicine_name FROM medicines WHERE medicine_name = ? AND is_archived = 0");
    $dupStmt->execute([$medicineName]);
    $existingMed = $dupStmt->fetch();
    $isMerge = $existingMed !== false;

    try {
        $db->beginTransaction();

        if ($isMerge) {
            // Increase existing stock (do NOT create a duplicate record).
            $newQty = (int)$existingMed['quantity'] + $quantity;
            $db->prepare("UPDATE medicines SET quantity = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$newQty, (int)$existingMed['id']]);
            $medicineId = (int)$existingMed['id'];

            if ($quantity > 0) {
                $db->prepare("INSERT INTO medicine_logs (medicine_id, action, quantity, reason, performed_by) VALUES (?, 'stock_in', ?, 'Restocked via Add Medicine', ?)")
                   ->execute([$medicineId, $quantity, $user['id']]);
            }

            $msgNurse  = $medicineName . ' has been successfully added to the clinic inventory.';
            $msgOthers = $medicineName . ' has been added to the clinic inventory.';
        } else {
            $stmt = $db->prepare(
                "INSERT INTO medicines
                   (medicine_name, generic_name, brand, category, dosage, form, supplier, manufacturer,
                    quantity, unit, reorder_level, expiration_date, batch_number, barcode,
                    storage_location, description)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $medicineName, $genericName, $brand, $category, $dosage, $form, $supplier, $manufacturer,
                $quantity, $unit, $reorder, $expDate !== '' ? $expDate : null, $batch, $barcode, $location, $desc,
            ]);
            $medicineId = (int)$db->lastInsertId();

            if ($quantity > 0) {
                $db->prepare("INSERT INTO medicine_logs (medicine_id, action, quantity, reason, performed_by) VALUES (?, 'stock_in', ?, 'Initial stock', ?)")
                   ->execute([$medicineId, $quantity, $user['id']]);
            }

            $msgNurse  = $medicineName . ' has been successfully added to the clinic inventory.';
            $msgOthers = $medicineName . ' has been added to the clinic inventory.';
        }

        log_activity($user['id'], 'Added medicine', 'inventory', $medicineName);
        fire_med_alerts($db, $medicineId);

        // Notify the acting nurse
        push_notification(
            $user['id'],
            'inventory',
            'Medicine Added',
            $msgNurse,
            APP_URL . '/pages/nurse/inventory.php',
            null,
            'medium',
            $medicineId
        );
        // Notify other nurses
        $nurseStmt = $db->query("SELECT user_id FROM nurses");
        foreach ($nurseStmt->fetchAll() as $n) {
            if ((int)$n['user_id'] !== (int)$user['id']) {
                push_notification($n['user_id'], 'inventory', 'Medicine Added', $msgOthers, APP_URL . '/pages/nurse/inventory.php', null, 'medium', $medicineId);
            }
        }
        // Notify admins
        $adminStmt = $db->query("SELECT id FROM users WHERE role = 'admin'");
        foreach ($adminStmt->fetchAll() as $a) {
            push_notification($a['id'], 'inventory', 'Medicine Added', $msgOthers, APP_URL . '/pages/nurse/inventory.php', null, 'medium', $medicineId);
        }

        $db->commit();
        json_response(true, ['id' => $medicineId, 'merged' => $isMerge], 'Medicine has been successfully added to the clinic inventory.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Add medicine failed: ' . $e->getMessage());
        json_response(false, [], 'Unable to add medicine. Please try again.');
    }
}

// ================================================================
// UPDATE — edit medicine fields
// ================================================================
if ($action === 'update') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can update medicines.');

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid medicine.');

    $stmt = $db->prepare("
        UPDATE medicines SET
            medicine_name=?, generic_name=?, brand=?, category=?, dosage=?, form=?,
            supplier=?, manufacturer=?, unit=?, reorder_level=?, expiration_date=?,
            batch_number=?, barcode=?, storage_location=?, description=?
        WHERE id=? AND is_archived=0
    ");
    $stmt->execute([
        trim($_POST['medicine_name'] ?? ''), trim($_POST['generic_name'] ?? ''),
        trim($_POST['brand'] ?? ''), trim($_POST['category'] ?? ''),
        trim($_POST['dosage'] ?? ''), trim($_POST['form'] ?? ''),
        trim($_POST['supplier'] ?? ''), trim($_POST['manufacturer'] ?? ''),
        trim($_POST['unit'] ?? 'pcs'), (int)($_POST['reorder_level'] ?? 20),
        ($_POST['expiration_date'] ?? null) ?: null,
        trim($_POST['batch_number'] ?? ''), trim($_POST['barcode'] ?? ''),
        trim($_POST['storage_location'] ?? ''), trim($_POST['description'] ?? ''),
        $id
    ]);

    log_activity($user['id'], 'Updated medicine', 'inventory', "Medicine #$id");
    json_response(true, [], 'Medicine updated.');
}

// ================================================================
// STOCK UPDATE — stock_in / stock_out / adjustment
// ================================================================
if ($action === 'stock_update') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can update stock.');

    $id = (int)$_POST['id'];
    $type = $_POST['type']; // stock_in / stock_out / adjustment
    $qty = (int)$_POST['quantity'];
    $reason = trim($_POST['reason'] ?? '');

    if (!in_array($type, ['stock_in', 'stock_out', 'adjustment'], true) || $qty <= 0) json_response(false, [], 'Invalid input.');

    $stmt = $db->prepare("SELECT quantity FROM medicines WHERE id=?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if ($current === false) json_response(false, [], 'Medicine not found.');

    if ($type === 'stock_in') {
        $newQty = $current + $qty;
    } elseif ($type === 'stock_out') {
        if ($qty > $current) json_response(false, [], 'Not enough stock available.');
        $newQty = $current - $qty;
    } else { // adjustment: set absolute quantity
        $newQty = $qty;
    }

    $db->prepare("UPDATE medicines SET quantity=? WHERE id=?")->execute([$newQty, $id]);
    $db->prepare("INSERT INTO inventory_logs (medicine_id, change_type, quantity_change, remarks, logged_by) VALUES (?,?,?,?,?)")
       ->execute([$id, $type === 'adjustment' ? 'adjustment' : ($type === 'stock_in' ? 'in' : 'out'), $newQty - $current, $reason, $user['id']]);

    log_activity($user['id'], ucfirst(str_replace('_', ' ', $type)), 'inventory', "Medicine #$id qty $qty");
    json_response(true, ['new_quantity' => $newQty], 'Stock updated.');
}

// ================================================================
// DELETE — soft delete (archive) or hard based on type
// ================================================================
if ($action === 'delete') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can delete medicines.');
    $id = (int)($_POST['id'] ?? 0);
    $hard = !empty($_POST['hard']);
    if ($hard) {
        $db->prepare("DELETE FROM medicines WHERE id = ?")->execute([$id]);
    } else {
        $db->prepare("UPDATE medicines SET is_archived = 1 WHERE id = ?")->execute([$id]);
    }
    log_activity($user['id'], 'Deleted medicine', 'inventory', "Medicine #$id");
    json_response(true, [], 'Medicine ' . ($hard ? 'deleted' : 'archived') . '.');
}

// ================================================================
// BULK DELETE / ARCHIVE
// ================================================================
if ($action === 'bulk_delete' || $action === 'bulk_archive') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can perform bulk actions.');
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || !$ids) json_response(false, [], 'No medicines selected.');
    $safe = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!$safe) json_response(false, [], 'Invalid selection.');
    $ph = implode(',', array_fill(0, count($safe), '?'));

    if ($action === 'bulk_archive') {
        $stmt = $db->prepare("UPDATE medicines SET is_archived = 1 WHERE id IN ($ph)");
    } else {
        $stmt = $db->prepare("DELETE FROM medicines WHERE id IN ($ph)");
    }
    $stmt->execute($safe);
    log_activity($user['id'], ucfirst(str_replace('_', ' ', $action)), 'inventory', count($safe) . ' item(s)');
    json_response(true, ['count' => count($safe)], count($safe) . ' medicine(s) ' . ($action === 'bulk_archive' ? 'archived' : 'deleted') . '.');
}

// ================================================================
// BULK STOCK UPDATE / CATEGORY CHANGE
// ================================================================
if ($action === 'bulk_stock_update') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can perform bulk actions.');
    $ids = $_POST['ids'] ?? [];
    $delta = (int)($_POST['delta'] ?? 0); // + adds, - subtracts
    if (!is_array($ids) || !$ids || $delta === 0) json_response(false, [], 'Invalid input.');
    $safe = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!$safe) json_response(false, [], 'Invalid selection.');
    $ph = implode(',', array_fill(0, count($safe), '?'));

    $stmt = $db->prepare("UPDATE medicines SET quantity = GREATEST(0, quantity + ?) WHERE id IN ($ph)");
    $stmt->execute(array_merge([$delta], $safe));
    json_response(true, ['count' => count($safe)], 'Stock adjusted for ' . count($safe) . ' medicine(s).');
}

if ($action === 'bulk_category') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can perform bulk actions.');
    $ids = $_POST['ids'] ?? [];
    $category = trim($_POST['category'] ?? '');
    if (!is_array($ids) || !$ids || !$category) json_response(false, [], 'Invalid input.');
    $safe = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    $ph = implode(',', array_fill(0, count($safe), '?'));
    $db->prepare("UPDATE medicines SET category = ? WHERE id IN ($ph)")->execute(array_merge([$category], $safe));
    json_response(true, ['count' => count($safe)], 'Category updated for ' . count($safe) . ' medicine(s).');
}

// ================================================================
// IMPORT — CSV / XLSX parse & validate
// ================================================================
if ($action === 'import_preview') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can import medicines.');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) json_response(false, [], 'Please choose a CSV or Excel file.');

    $tmp = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    $rows = null;
    if ($ext === 'csv') {
        $handle = fopen($tmp, 'r');
        if (!$handle) json_response(false, [], 'Cannot open file.');
        $data = [];
        while (($line = fgetcsv($handle)) !== false) {
            $data[] = array_map('trim', $line);
        }
        fclose($handle);
        $rows = $data;
    } elseif (in_array($ext, ['xlsx', 'xls'], true)) {
        // Use SheetJS on the client to upload JSON; fallback here reads CSV only.
        json_response(false, [], 'For Excel files, use the client-side preview (SheetJS) and then import via JSON.');
    } else {
        json_response(false, [], 'Unsupported file type. Use CSV or Excel.');
    }

    if (!$rows || count($rows) < 2) json_response(false, [], 'File appears empty.');

    // Expected header order (match CSV template):
    $headers = ['Medicine Name','Generic Name','Brand','Category','Dosage','Form','Quantity','Unit','Minimum Stock','Supplier','Batch Number','Expiration Date','Location','Description'];
    $headerRow = $rows[0];
    // Case-insensitive + trimmed
    $normalizedHeader = array_map(fn($h) => strtolower(trim((string)$h)), $headerRow);

    $valid = 0; $invalid = 0; $skipped = 0; $preview = []; $errors = [];

    $existing = $db->query("SELECT medicine_name, generic_name, batch_number FROM medicines")->fetchAll();

    foreach (array_slice($rows, 1) as $idx => $row) {
        $lineNo = $idx + 2;
        $rowErr = [];

        $mName  = trim((string)($row[0] ?? ''));
        $gName  = trim((string)($row[1] ?? ''));
        $brand  = trim((string)($row[2] ?? ''));
        $cat    = trim((string)($row[3] ?? ''));
        $dosage = trim((string)($row[4] ?? ''));
        $form   = trim((string)($row[5] ?? ''));
        $qty    = trim((string)($row[6] ?? ''));
        $unit   = trim((string)($row[7] ?? '')) ?: 'pcs';
        $reorder= trim((string)($row[8] ?? ''));
        $supplier = trim((string)($row[9] ?? ''));
        $batch  = trim((string)($row[10] ?? ''));
        $expiration = trim((string)($row[11] ?? ''));
        $location = trim((string)($row[12] ?? ''));
        $desc   = trim((string)($row[13] ?? ''));

        // validate required
        if (!$mName) { $rowErr[] = 'Medicine Name is required'; }
        if ($qty === '') { $rowErr[] = 'Quantity is required'; }
        elseif (!is_numeric($qty) || (int)$qty < 0) { $rowErr[] = 'Quantity must be a non-negative number'; }

        // validate dates
        if ($expiration !== '' && !strtotime($expiration)) { $rowErr[] = 'Invalid Expiration Date'; }
        if ($expiration !== '' && strtotime($expiration) && $expiration < date('Y-m-d')) { $rowErr[] = 'Expiration date is in the past'; }

        // validate reorder
        if ($reorder !== '' && (!is_numeric($reorder) || (int)$reorder < 0)) { $rowErr[] = 'Minimum Stock must be non-negative'; }

        // duplicate medicine name
        foreach ($existing as $ex) {
            if (strcasecmp($ex['medicine_name'], $mName) === 0) { $rowErr[] = 'Duplicate medicine'; break; }
        }
        // duplicate batch
        foreach ($existing as $ex) {
            if ($batch !== '' && $ex['batch_number'] !== null && strcasecmp((string)$ex['batch_number'], $batch) === 0) { $rowErr[] = 'Duplicate batch number'; break; }
        }

        if ($rowErr) {
            $invalid++;
            $errors[] = ['line' => $lineNo, 'medicine' => $mName, 'errors' => $rowErr];
        } else {
            $valid++;
            $preview[] = [
                'medicine_name' => $mName, 'generic_name' => $gName, 'brand' => $brand, 'category' => $cat,
                'dosage' => $dosage, 'form' => $form, 'quantity' => (int)$qty, 'unit' => $unit,
                'reorder_level' => $reorder === '' ? 20 : (int)$reorder, 'supplier' => $supplier,
                'batch_number' => $batch, 'expiration_date' => $expiration ?: null,
                'storage_location' => $location, 'description' => $desc,
            ];
        }
    }

    json_response(true, [
        'preview' => $preview,
        'summary' => [
            'total' => count($rows) - 1,
            'valid' => $valid,
            'invalid' => $invalid,
            'skipped' => $skipped,
        ],
        'errors' => $errors,
    ]);
}

// ================================================================
// IMPORT — commit validated rows (from preview)
// ================================================================
if ($action === 'import_commit') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can import medicines.');
    $items = $_POST['items'] ?? [];
    if (!is_array($items) || !$items) json_response(false, [], 'No valid items to import.');

    $inserted = 0;
    $stmt = $db->prepare("
        INSERT INTO medicines
           (medicine_name, generic_name, brand, category, dosage, form, supplier, quantity, unit, reorder_level, expiration_date, batch_number, storage_location, description)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    foreach ($items as $item) {
        $stmt->execute([
            $item['medicine_name'], $item['generic_name'] ?? '', $item['brand'] ?? '', $item['category'] ?? '',
            $item['dosage'] ?? '', $item['form'] ?? '', $item['supplier'] ?? '',
            (int)$item['quantity'], $item['unit'] ?: 'pcs', (int)($item['reorder_level'] ?? 20),
            $item['expiration_date'] ?: null, $item['batch_number'] ?? '',
            $item['storage_location'] ?? '', $item['description'] ?? '',
        ]);
        $inserted++;
    }

    log_activity($user['id'], 'Imported medicines', 'inventory', "$inserted item(s)");
    json_response(true, ['inserted' => $inserted], "$inserted medicine(s) imported successfully.");
}

// ================================================================
// TEMPLATE — generate downloadable CSV
// ================================================================
if ($action === 'template') {
    $headers = ['Medicine Name','Generic Name','Brand','Category','Dosage','Form','Quantity','Unit','Minimum Stock','Supplier','Batch Number','Expiration Date','Location','Description'];
    $sample = ['Paracetamol','Acetaminophen','Biogesic','Analgesic','500mg','Tablet','100','pcs','20','PharmaCorp','BT-2025-001','2025-12-31','Shelf A1','For fever and pain'];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="medicine_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    fputcsv($out, $sample);
    fclose($out);
    exit;
}

// ================================================================
// HISTORY — medicine_logs / dispensing history
// ================================================================
if ($action === 'history') {
    $stmt = $db->query("
        SELECT ml.*, m.medicine_name, m.unit, u.first_name, u.last_name
        FROM medicine_logs ml
        LEFT JOIN medicines m ON m.id = ml.medicine_id
        LEFT JOIN users u ON u.id = ml.performed_by
        ORDER BY ml.performed_at DESC LIMIT 200
    ");
    json_response(true, ['data' => $stmt->fetchAll()]);
}

json_response(false, [], 'Unknown action');

