<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','nurse']);
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    $stmt = $db->query("SELECT *, CASE WHEN expiration_date IS NOT NULL AND expiration_date <= CURDATE() THEN 1 ELSE 0 END AS is_expired,
                         CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END AS is_low
                         FROM medicines ORDER BY medicine_name");
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'add') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can add medicines.');
    $stmt = $db->prepare("INSERT INTO medicines (medicine_name, category, supplier, quantity, unit, reorder_level, expiration_date, batch_number, barcode)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        trim($_POST['medicine_name']), trim($_POST['category']), trim($_POST['supplier']),
        (int)$_POST['quantity'], trim($_POST['unit']) ?: 'pcs', (int)($_POST['reorder_level'] ?: 20),
        $_POST['expiration_date'] ?: null, trim($_POST['batch_number']), trim($_POST['barcode']),
    ]);
    log_activity($user['id'], 'Added medicine', 'inventory', $_POST['medicine_name']);
    json_response(true, [], 'Medicine added.');
}

if ($action === 'stock_update') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can update stock.');

    $id = (int)$_POST['id'];
    $type = $_POST['type']; // stock_in / stock_out
    $qty = (int)$_POST['quantity'];
    $reason = trim($_POST['reason'] ?? '');

    if (!in_array($type, ['stock_in','stock_out'], true) || $qty <= 0) json_response(false, [], 'Invalid input.');

    $stmt = $db->prepare("SELECT quantity FROM medicines WHERE id=?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if ($current === false) json_response(false, [], 'Medicine not found.');
    if ($type === 'stock_out' && $qty > $current) json_response(false, [], 'Not enough stock available.');

    $newQty = $type === 'stock_in' ? $current + $qty : $current - $qty;
    $db->prepare("UPDATE medicines SET quantity=? WHERE id=?")->execute([$newQty, $id]);
    $db->prepare("INSERT INTO medicine_logs (medicine_id, action, quantity, reason, performed_by) VALUES (?,?,?,?,?)")
       ->execute([$id, $type, $qty, $reason, $user['id']]);

    log_activity($user['id'], ucfirst(str_replace('_',' ',$type)), 'inventory', "Medicine #$id qty $qty");
    json_response(true, ['new_quantity' => $newQty], 'Stock updated.');
}

json_response(false, [], 'Unknown action');
